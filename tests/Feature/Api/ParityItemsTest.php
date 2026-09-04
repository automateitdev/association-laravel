<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Tenant\AccountGroup;
use App\Models\Tenant\FeeAssign;
use App\Models\Tenant\Ledger;
use App\Models\Tenant\LedgerTrace;
use App\Models\Tenant\Nominee;
use App\Models\Tenant\PaymentInfo;
use App\Models\Tenant\PaymentInfoItem;
use App\Models\User;
use App\Services\TenantSeedService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\TenantFixtures;
use Tests\TenantTestCase;

/**
 * Fine adjustment, ledger writes, the membership register and nominees.
 *
 * Four small features, and what they have in common is that each one can move a
 * number somebody is entitled to rely on. The tests worth writing are the
 * REFUSALS - anybody can check that a save saves.
 */
class ParityItemsTest extends TenantTestCase
{
    use TenantFixtures;

    private int $sequence = 0;

    private function headers(?string $token = null): array
    {
        return array_filter([
            'X-Tenant' => $this->slug(),
            'Accept' => 'application/json',
            'Authorization' => $token ? "Bearer {$token}" : null,
        ]);
    }

    private function staffToken(): string
    {
        $n = ++$this->sequence;

        return $this->inTenant(function () use ($n) {
            app(TenantSeedService::class)->seedAll();

            $user = User::create([
                'name' => "Parity {$n}",
                'email' => "parity{$n}@assoc.test",
                'password' => 'secret-password',
            ]);
            $user->assignRole('superadmin');

            return $user->createToken('test', $user->getAllPermissions()->pluck('name')->all())
                ->plainTextToken;
        });
    }

    /** @param  list<string>  $permissions */
    private function tokenWithPermissions(array $permissions): string
    {
        $n = ++$this->sequence;

        return $this->inTenant(function () use ($permissions, $n) {
            app(TenantSeedService::class)->seedAll();

            $role = Role::findOrCreate("parity-{$n}", 'web');
            $role->syncPermissions(
                array_map(fn (string $p) => Permission::findByName($p, 'web'), $permissions)
            );

            $user = User::create([
                'name' => "Narrow {$n}",
                'email' => "paritynarrow{$n}@assoc.test",
                'password' => 'secret-password',
            ]);
            $user->assignRole($role);

            return $user->createToken('test', $user->getAllPermissions()->pluck('name')->all())
                ->plainTextToken;
        });
    }

    private function makeAssign(string $status = FeeAssign::STATUS_UNPAID, string $fine = '100.00'): FeeAssign
    {
        $setup = $this->makeFeeSetup();
        $member = $this->makeMember();

        return FeeAssign::create([
            'member_id' => $member->id,
            'fee_setup_id' => $setup->id,
            'period' => '2026-01',
            'assign_date' => '2026-01-01',
            'fine_date' => '2026-01-10',
            'amount' => '1000.00',
            'fine_amount' => $fine,
            'status' => $status,
        ]);
    }

    // ------------------------------------------------------- fine adjustment

    public function test_a_fine_on_an_unpaid_instalment_can_be_waived(): void
    {
        $token = $this->staffToken();
        $assign = $this->inTenant(fn () => $this->makeAssign());

        $this->postJson(
            "/api/v1/staff/fee-assigns/{$assign->id}/fine-adjustment",
            ['fine_amount' => 0, 'reason' => 'Waived: member was in hospital.'],
            $this->headers($token),
        )
            ->assertOk()
            ->assertJsonPath('data.fine_amount', '0.00')
            ->assertJsonPath('data.total_due', '1000.00')
            ->assertJsonPath('meta.previous_fine_amount', '100.00');
    }

    /**
     * The whole reason this endpoint exists rather than a port of the legacy
     * one, which happily rewrote fines on settled instalments.
     */
    public function test_it_refuses_to_edit_the_fine_on_a_paid_instalment(): void
    {
        $token = $this->staffToken();
        $assign = $this->inTenant(fn () => $this->makeAssign(FeeAssign::STATUS_PAID));

        $this->postJson(
            "/api/v1/staff/fee-assigns/{$assign->id}/fine-adjustment",
            ['fine_amount' => 0, 'reason' => 'Trying to rewrite history.'],
            $this->headers($token),
        )
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'FINE_ALREADY_PAID');

        // And the figure is untouched, not merely un-reported.
        $this->assertSame(
            '100.00',
            (string) $this->inTenant(fn () => FeeAssign::find($assign->id)->fine_amount)
        );
    }

    public function test_a_fine_adjustment_requires_a_reason(): void
    {
        $token = $this->staffToken();
        $assign = $this->inTenant(fn () => $this->makeAssign());

        $this->postJson(
            "/api/v1/staff/fee-assigns/{$assign->id}/fine-adjustment",
            ['fine_amount' => 0],
            $this->headers($token),
        )->assertStatus(422);
    }

    public function test_a_fine_adjustment_is_audited_with_its_reason(): void
    {
        $token = $this->staffToken();
        $assign = $this->inTenant(fn () => $this->makeAssign());

        $this->postJson(
            "/api/v1/staff/fee-assigns/{$assign->id}/fine-adjustment",
            ['fine_amount' => '25.00', 'reason' => 'Committee reduced it on appeal.'],
            $this->headers($token),
        )->assertOk();

        $log = $this->inTenant(
            fn () => \App\Models\Tenant\AuditLog::where('action', 'fee-assign.fine-adjusted')->latest('id')->first()
        );

        $this->assertNotNull($log);
        $this->assertSame('Committee reduced it on appeal.', $log->reason);
        $this->assertSame('100.00', $log->before['fine_amount']);
        $this->assertSame('25.00', $log->after['fine_amount']);
    }

    public function test_fine_adjustment_needs_its_own_permission(): void
    {
        $token = $this->tokenWithPermissions(['fee-assigns.view']);
        $assign = $this->inTenant(fn () => $this->makeAssign());

        $this->postJson(
            "/api/v1/staff/fee-assigns/{$assign->id}/fine-adjustment",
            ['fine_amount' => 0, 'reason' => 'No permission for this.'],
            $this->headers($token),
        )->assertForbidden();
    }

    // ------------------------------------------------------------- ledgers

    public function test_a_ledger_can_be_created_and_renamed(): void
    {
        $token = $this->staffToken();
        $group = $this->inTenant(fn () => AccountGroup::first());

        $created = $this->postJson(
            '/api/v1/staff/ledgers',
            ['account_group_id' => $group->id, 'name' => 'Welfare Fund', 'code' => 'WF-1'],
            $this->headers($token),
        )->assertCreated();

        $id = $created->json('data.id');

        $this->putJson(
            "/api/v1/staff/ledgers/{$id}",
            ['name' => 'Member Welfare Fund'],
            $this->headers($token),
        )
            ->assertOk()
            ->assertJsonPath('data.name', 'Member Welfare Fund');
    }

    /**
     * Regrouping a posted ledger silently reclassifies every past entry, so
     * last year's income statement stops matching last year's printout.
     */
    public function test_it_refuses_to_regroup_a_ledger_that_has_postings(): void
    {
        $token = $this->staffToken();

        [$ledgerId, $otherGroupId] = $this->inTenant(function () {
            $ledger = Ledger::first();

            LedgerTrace::create([
                'ledger_id' => $ledger->id,
                'debit' => '10.00',
                'credit' => '0.00',
                'posted_on' => '2026-01-01',
            ]);

            $other = AccountGroup::where('id', '<>', $ledger->account_group_id)->first();

            return [$ledger->id, $other->id];
        });

        $this->putJson(
            "/api/v1/staff/ledgers/{$ledgerId}",
            ['account_group_id' => $otherGroupId],
            $this->headers($token),
        )
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'LEDGER_HAS_POSTINGS');
    }

    /**
     * Deactivating a ledger a fee head still names breaks the NEXT posting,
     * which lands on somebody who did not make the change.
     */
    public function test_it_refuses_to_deactivate_a_ledger_a_fee_head_still_uses(): void
    {
        $token = $this->staffToken();

        $ledgerId = $this->inTenant(function () {
            $setup = $this->makeFeeSetup();

            return $setup->ledger_id;
        });

        $response = $this->putJson(
            "/api/v1/staff/ledgers/{$ledgerId}",
            ['is_active' => false],
            $this->headers($token),
        )->assertStatus(422);

        $response->assertJsonPath('error.code', 'LEDGER_IN_USE');
        // The message names WHAT is in the way, not just that something is.
        $this->assertStringContainsString('Monthly Subscription', $response->json('error.message'));
    }

    public function test_an_unused_ledger_can_be_deactivated_and_then_is_hidden(): void
    {
        $token = $this->staffToken();
        $group = $this->inTenant(fn () => AccountGroup::first());

        $id = $this->postJson(
            '/api/v1/staff/ledgers',
            ['account_group_id' => $group->id, 'name' => 'Disused Account'],
            $this->headers($token),
        )->assertCreated()->json('data.id');

        $this->putJson("/api/v1/staff/ledgers/{$id}", ['is_active' => false], $this->headers($token))
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $active = $this->getJson('/api/v1/staff/ledgers', $this->headers($token))->json('data');
        $this->assertNotContains('Disused Account', array_column($active, 'name'));

        // Retired, not gone: the chart screen has to be able to show it.
        $all = $this->getJson('/api/v1/staff/ledgers?include_inactive=true', $this->headers($token))->json('data');
        $this->assertContains('Disused Account', array_column($all, 'name'));
    }

    public function test_creating_a_ledger_needs_the_create_permission(): void
    {
        $token = $this->tokenWithPermissions(['ledgers.view']);
        $group = $this->inTenant(fn () => AccountGroup::first());

        $this->postJson(
            '/api/v1/staff/ledgers',
            ['account_group_id' => $group->id, 'name' => 'Nope'],
            $this->headers($token),
        )->assertForbidden();
    }

    // ------------------------------------------------- membership register

    public function test_the_register_lists_members_by_membership_number(): void
    {
        $token = $this->staffToken();

        $this->inTenant(function () {
            $member = $this->makeMember();
            $member->associatorInfo()->create([
                'membership_no' => '114',
                'num_or_shares' => 7,
                'company' => 'Bangladesh Computer Samity',
            ]);
        });

        $this->getJson('/api/v1/staff/associator-infos', $this->headers($token))
            ->assertOk()
            ->assertJsonPath('data.0.membership_no', '114')
            ->assertJsonPath('data.0.shares', 7)
            ->assertJsonPath('data.0.company', 'Bangladesh Computer Samity');
    }

    public function test_the_register_needs_the_associator_permission(): void
    {
        $token = $this->tokenWithPermissions(['members.view']);

        $this->getJson('/api/v1/staff/associator-infos', $this->headers($token))
            ->assertForbidden();
    }

    /**
     * The route used to be gated on members.edit. Narrowing it is the point of
     * the parity item, so the test pins it.
     */
    public function test_editing_the_register_needs_associator_edit_not_members_edit(): void
    {
        $memberId = $this->inTenant(fn () => $this->makeMember()->id);

        $this->putJson(
            "/api/v1/staff/members/{$memberId}/associator-info",
            ['membership_no' => '200'],
            $this->headers($this->tokenWithPermissions(['members.view', 'members.edit'])),
        )->assertForbidden();

        $this->putJson(
            "/api/v1/staff/members/{$memberId}/associator-info",
            ['membership_no' => '200'],
            $this->headers($this->tokenWithPermissions(['members.view', 'associator.edit'])),
        )->assertOk();
    }

    // ------------------------------------------------------------ nominees

    public function test_nominees_can_be_added_listed_and_removed(): void
    {
        $token = $this->staffToken();
        $memberId = $this->inTenant(fn () => $this->makeMember()->id);

        $id = $this->postJson(
            "/api/v1/staff/members/{$memberId}/nominees",
            ['name' => 'Rokeya Begum', 'relation' => 'Spouse', 'share_percentage' => 60],
            $this->headers($token),
        )
            ->assertCreated()
            ->assertJsonPath('data.share_percentage', '60.00')
            ->json('data.id');

        $this->getJson("/api/v1/staff/members/{$memberId}/nominees", $this->headers($token))
            ->assertOk()
            ->assertJsonPath('meta.allocated_percentage', '60.00')
            ->assertJsonCount(1, 'data');

        $this->deleteJson("/api/v1/staff/nominees/{$id}", [], $this->headers($token))
            ->assertOk();

        $this->assertSame(0, $this->inTenant(fn () => Nominee::count()));
    }

    /** A split adding up to 130% is a dispute, not a split. */
    public function test_nominee_shares_cannot_exceed_one_hundred_percent(): void
    {
        $token = $this->staffToken();
        $memberId = $this->inTenant(fn () => $this->makeMember()->id);

        $this->postJson(
            "/api/v1/staff/members/{$memberId}/nominees",
            ['name' => 'First', 'share_percentage' => 70],
            $this->headers($token),
        )->assertCreated();

        $this->postJson(
            "/api/v1/staff/members/{$memberId}/nominees",
            ['name' => 'Second', 'share_percentage' => 40],
            $this->headers($token),
        )
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'NOMINEE_ALLOCATION_EXCEEDED');
    }

    /**
     * Editing one nominee must not count its own current share against its
     * replacement, or raising 70 to 80 would look like 150.
     */
    public function test_editing_a_nominee_does_not_count_its_own_share_against_itself(): void
    {
        $token = $this->staffToken();
        $memberId = $this->inTenant(fn () => $this->makeMember()->id);

        $id = $this->postJson(
            "/api/v1/staff/members/{$memberId}/nominees",
            ['name' => 'Only', 'share_percentage' => 70],
            $this->headers($token),
        )->assertCreated()->json('data.id');

        $this->putJson(
            "/api/v1/staff/nominees/{$id}",
            ['share_percentage' => 80],
            $this->headers($token),
        )
            ->assertOk()
            ->assertJsonPath('data.share_percentage', '80.00');
    }

    /** Part way through naming three people, the first two must still save. */
    public function test_a_partial_allocation_is_allowed(): void
    {
        $token = $this->staffToken();
        $memberId = $this->inTenant(fn () => $this->makeMember()->id);

        $this->postJson(
            "/api/v1/staff/members/{$memberId}/nominees",
            ['name' => 'Only so far', 'share_percentage' => 25],
            $this->headers($token),
        )->assertCreated();

        $this->getJson("/api/v1/staff/members/{$memberId}/nominees", $this->headers($token))
            ->assertJsonPath('meta.allocated_percentage', '25.00');
    }

    public function test_nominees_need_their_permission(): void
    {
        $token = $this->tokenWithPermissions(['members.view']);
        $memberId = $this->inTenant(fn () => $this->makeMember()->id);

        $this->getJson("/api/v1/staff/members/{$memberId}/nominees", $this->headers($token))
            ->assertForbidden();
    }
}
