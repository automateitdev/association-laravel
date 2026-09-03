<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Tenant\FeeAssign;
use App\Models\Tenant\LedgerTrace;
use App\Models\Tenant\Member;
use App\Models\Tenant\PaymentInfo;
use App\Models\User;
use App\Services\FeeAssignService;
use App\Services\TenantSeedService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\TenantFixtures;
use Tests\TenantTestCase;

/**
 * Taking money at the counter (FR-FEE-9).
 *
 * WHAT MATTERS HERE
 * -----------------
 * Until this endpoint existed only a MEMBER could create a payment, so an
 * association could not record the cash most of its members actually hand over
 * a desk. These tests are about that path being correct, not merely present:
 *
 *   - the payment is created PENDING, not completed. FR-PAY-2 says so, and the
 *     reason is a cash control: the person who takes the money is not the
 *     person who confirms it was taken.
 *   - a receiving ledger is REQUIRED. Without one the payment is created
 *     happily and then cannot be approved, which strands the money in the queue
 *     with nothing on the collection screen to explain why.
 *   - pressing the button twice does not take the money twice.
 */
class CollectionTest extends TenantTestCase
{
    use TenantFixtures;

    private function headers(?string $token = null, ?string $idempotencyKey = null): array
    {
        return array_filter([
            'X-Tenant' => $this->slug(),
            'Accept' => 'application/json',
            'Authorization' => $token ? "Bearer {$token}" : null,
            'Idempotency-Key' => $idempotencyKey,
        ]);
    }

    private function staffToken(): string
    {
        return $this->inTenant(function () {
            app(TenantSeedService::class)->seedAll();

            static $sequence = 0;
            $sequence++;

            $user = User::create([
                'name' => "Collector {$sequence}",
                'email' => "collector{$sequence}@assoc.test",
                'password' => 'secret-password',
            ]);
            $user->assignRole('superadmin');

            return $user->createToken('test', $user->getAllPermissions()->pluck('name')->all())
                ->plainTextToken;
        });
    }

    /** @param list<string> $permissions */
    private function tokenWithPermissions(array $permissions): string
    {
        return $this->inTenant(function () use ($permissions) {
            app(TenantSeedService::class)->seedAll();

            static $sequence = 0;
            $sequence++;

            $role = Role::findOrCreate("collect-narrow-{$sequence}", 'web');
            $role->syncPermissions(
                array_map(fn (string $p) => Permission::findByName($p, 'web'), $permissions)
            );

            $user = User::create([
                'name' => "Narrow collector {$sequence}",
                'email' => "narrowcollect{$sequence}@assoc.test",
                'password' => 'secret-password',
            ]);
            $user->assignRole($role);

            return $user->createToken('test', $user->getAllPermissions()->pluck('name')->all())
                ->plainTextToken;
        });
    }

    /** @return array{member: int, assigns: list<int>, ledger: int} */
    private function seedMemberWithDues(): array
    {
        return $this->inTenant(function () {
            $this->seedSettings();
            $ledgers = $this->makeLedgers();
            $setup = $this->makeFeeSetup();
            $member = $this->makeMember(['name' => 'Counter Member']);

            $first = app(FeeAssignService::class)->assign($member->id, $setup, '2026-01');
            $second = app(FeeAssignService::class)->assign($member->id, $setup, '2026-02');
            $second->update(['fine_amount' => '150.00']);

            return [
                'member' => $member->id,
                'assigns' => [$first->id, $second->id],
                'ledger' => $ledgers['cash']->id,
            ];
        });
    }

    // ---- what the counter sees -------------------------------------------

    public function test_staff_can_see_what_a_member_owes(): void
    {
        $seed = $this->seedMemberWithDues();

        $response = $this->withHeaders($this->headers($this->staffToken()))
            ->getJson("/api/v1/staff/members/{$seed['member']}/dues")
            ->assertSuccessful();

        $response->assertJsonPath('meta.member_name', 'Counter Member');

        // Instalment and fine reported apart, and the total computed by the
        // SERVER - the app never adds money.
        $response->assertJsonPath('meta.instalment_total', '2000.00');
        $response->assertJsonPath('meta.fine_total', '150.00');
        $response->assertJsonPath('meta.grand_total', '2150.00');
    }

    // ---- taking the money -------------------------------------------------

    public function test_a_collection_is_created_pending_not_completed(): void
    {
        $seed = $this->seedMemberWithDues();

        $response = $this->withHeaders($this->headers($this->staffToken(), 'collect-1'))
            ->postJson('/api/v1/staff/collections', [
                'member_id' => $seed['member'],
                'fee_assign_ids' => $seed['assigns'],
                'ledger_id' => $seed['ledger'],
            ])
            ->assertCreated();

        /*
         * PENDING. The cash being in the drawer is not the same event as the
         * association confirming it, and collapsing the two would remove the
         * separation between who collects and who approves.
         */
        $response->assertJsonPath('data.status', PaymentInfo::STATUS_PENDING);

        // Apart, always (FR-MON-1).
        $response->assertJsonPath('data.payable_amount', '2000.00');
        $response->assertJsonPath('data.fine_amount', '150.00');
        $response->assertJsonPath('data.total_amount', '2150.00');

        // The assignments are spoken for, so nobody collects them twice.
        $this->inTenant(function () use ($seed) {
            foreach ($seed['assigns'] as $id) {
                $this->assertSame(FeeAssign::STATUS_REQUESTED, FeeAssign::find($id)->status);
            }
        });
    }

    public function test_a_collection_cannot_be_recorded_without_a_receiving_ledger(): void
    {
        $seed = $this->seedMemberWithDues();

        /*
         * This was allowed once, and the failure was two steps away: the
         * payment was created, and then approval refused it with "has no
         * receiving ledger; cannot post" - money stranded in the queue with
         * nothing on the collection screen to say why.
         */
        $this->withHeaders($this->headers($this->staffToken(), 'collect-no-ledger'))
            ->postJson('/api/v1/staff/collections', [
                'member_id' => $seed['member'],
                'fee_assign_ids' => $seed['assigns'],
            ])
            ->assertStatus(422);
    }

    public function test_pressing_collect_twice_does_not_take_the_money_twice(): void
    {
        $seed = $this->seedMemberWithDues();
        $token = $this->staffToken();

        $body = [
            'member_id' => $seed['member'],
            'fee_assign_ids' => $seed['assigns'],
            'ledger_id' => $seed['ledger'],
        ];

        $first = $this->withHeaders($this->headers($token, 'collect-same'))
            ->postJson('/api/v1/staff/collections', $body)
            ->assertCreated()
            ->json('data.id');

        $second = $this->withHeaders($this->headers($token, 'collect-same'))
            ->postJson('/api/v1/staff/collections', $body)
            ->assertSuccessful()
            ->json('data.id');

        $this->assertSame($first, $second, 'a repeated submit created a second payment');

        $this->inTenant(function () use ($seed) {
            $this->assertSame(1, PaymentInfo::where('member_id', $seed['member'])->count());
        });
    }

    public function test_the_same_key_with_a_different_body_is_refused(): void
    {
        $seed = $this->seedMemberWithDues();
        $token = $this->staffToken();

        $this->withHeaders($this->headers($token, 'collect-reused'))
            ->postJson('/api/v1/staff/collections', [
                'member_id' => $seed['member'],
                'fee_assign_ids' => $seed['assigns'],
                'ledger_id' => $seed['ledger'],
            ])
            ->assertCreated();

        // Silently returning the first payment would hide a client bug.
        $this->withHeaders($this->headers($token, 'collect-reused'))
            ->postJson('/api/v1/staff/collections', [
                'member_id' => $seed['member'],
                'fee_assign_ids' => [$seed['assigns'][0]],
                'ledger_id' => $seed['ledger'],
            ])
            ->assertStatus(409);
    }

    // ---- and through to the ledger ---------------------------------------

    public function test_approving_a_collection_posts_double_entry_and_settles_the_dues(): void
    {
        $seed = $this->seedMemberWithDues();
        $token = $this->staffToken();

        $payment = $this->withHeaders($this->headers($token, 'collect-approve'))
            ->postJson('/api/v1/staff/collections', [
                'member_id' => $seed['member'],
                'fee_assign_ids' => $seed['assigns'],
                'ledger_id' => $seed['ledger'],
            ])
            ->assertCreated()
            ->json('data.id');

        $before = $this->inTenant(fn () => LedgerTrace::count());

        $this->withHeaders($this->headers($token))
            ->postJson('/api/v1/staff/payments/decide', [
                'payment_ids' => [$payment],
                'decision' => 'completed',
            ])
            ->assertSuccessful()
            ->assertJsonPath('data.decided', 1)
            ->assertJsonPath('data.failed', 0);

        $this->inTenant(function () use ($seed, $before) {
            // Something was actually posted - a collection that settles dues
            // without touching the ledger is the legacy's accounting hole.
            $this->assertGreaterThan($before, LedgerTrace::count());

            foreach ($seed['assigns'] as $id) {
                $this->assertSame(FeeAssign::STATUS_PAID, FeeAssign::find($id)->status);
            }
        });
    }

    // ---- who may take money ----------------------------------------------

    // ---- the receipt ------------------------------------------------------

    public function test_a_receipt_is_produced_for_a_completed_payment(): void
    {
        $seed = $this->seedMemberWithDues();
        $token = $this->staffToken();

        $payment = $this->withHeaders($this->headers($token, 'collect-receipt'))
            ->postJson('/api/v1/staff/collections', [
                'member_id' => $seed['member'],
                'fee_assign_ids' => $seed['assigns'],
                'ledger_id' => $seed['ledger'],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->withHeaders($this->headers($token))
            ->postJson('/api/v1/staff/payments/decide', [
                'payment_ids' => [$payment],
                'decision' => 'completed',
            ])
            ->assertSuccessful();

        $response = $this->withHeaders($this->headers($token))
            ->get("/api/v1/staff/payments/{$payment}/invoice");

        $response->assertSuccessful();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('content-type'));

        /*
         * `inline`, not `attachment`. A receipt is looked at and printed far
         * more often than it is filed, so it opens rather than landing silently
         * in a downloads folder.
         */
        $this->assertStringContainsString(
            'inline',
            (string) $response->headers->get('content-disposition')
        );

        // A plain response, not a streamed one - mPDF builds the whole
        // document in memory before it can emit a byte.
        $this->assertNotEmpty((string) $response->getContent());

        // Actually a PDF, not an error page with the right content type.
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
    }

    public function test_no_receipt_is_issued_before_the_payment_is_approved(): void
    {
        $seed = $this->seedMemberWithDues();
        $token = $this->staffToken();

        $payment = $this->withHeaders($this->headers($token, 'collect-unapproved'))
            ->postJson('/api/v1/staff/collections', [
                'member_id' => $seed['member'],
                'fee_assign_ids' => $seed['assigns'],
                'ledger_id' => $seed['ledger'],
            ])
            ->assertCreated()
            ->json('data.id');

        /*
         * A receipt says the association HAS the money. Issuing one for a
         * payment still in the approvals queue hands the member proof of
         * something that has not happened and may yet be rejected.
         */
        $this->withHeaders($this->headers($token))
            ->get("/api/v1/staff/payments/{$payment}/invoice")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PAYMENT_NOT_COMPLETED');
    }

    public function test_seeing_the_dues_does_not_permit_collecting_them(): void
    {
        $seed = $this->seedMemberWithDues();

        // A clerk may be trusted to look up what somebody owes without being
        // trusted to record a payment against it.
        $token = $this->tokenWithPermissions(['collections.view']);

        $this->withHeaders($this->headers($token))
            ->getJson("/api/v1/staff/members/{$seed['member']}/dues")
            ->assertSuccessful();

        $this->withHeaders($this->headers($token, 'collect-forbidden'))
            ->postJson('/api/v1/staff/collections', [
                'member_id' => $seed['member'],
                'fee_assign_ids' => $seed['assigns'],
                'ledger_id' => $seed['ledger'],
            ])
            ->assertForbidden();
    }

    public function test_a_member_token_cannot_reach_the_collection_endpoint(): void
    {
        $seed = $this->seedMemberWithDues();

        $memberToken = $this->inTenant(function () {
            $member = $this->makeMember(['password' => 'x']);

            return $member->createToken('test', ['collections.create'])->plainTextToken;
        });

        // Even carrying the ability, a member account is refused by the staff
        // middleware - taking money on someone else's behalf is not a member act.
        $this->withHeaders($this->headers($memberToken, 'collect-member'))
            ->postJson('/api/v1/staff/collections', [
                'member_id' => $seed['member'],
                'fee_assign_ids' => $seed['assigns'],
                'ledger_id' => $seed['ledger'],
            ])
            ->assertForbidden();
    }

    public function test_the_dues_endpoint_is_honest_about_an_unknown_member(): void
    {
        $this->withHeaders($this->headers($this->staffToken()))
            ->getJson('/api/v1/staff/members/999999/dues')
            ->assertStatus(404);
    }

    public function test_a_member_cannot_be_billed_for_another_members_assignment(): void
    {
        $first = $this->seedMemberWithDues();

        $other = $this->inTenant(fn () => $this->makeMember(['name' => 'Somebody Else']));

        /*
         * The assignments belong to the first member. Recording them against
         * the second would move another person's debt onto this one's receipt -
         * the service guards it, and this proves the endpoint does not find a
         * way around that guard.
         */
        $this->withHeaders($this->headers($this->staffToken(), 'collect-crossed'))
            ->postJson('/api/v1/staff/collections', [
                'member_id' => $other->id,
                'fee_assign_ids' => $first['assigns'],
                'ledger_id' => $first['ledger'],
            ])
            ->assertStatus(422);

        $this->inTenant(function () use ($first) {
            foreach ($first['assigns'] as $id) {
                $this->assertSame(FeeAssign::STATUS_UNPAID, FeeAssign::find($id)->status);
            }
        });
    }
}
