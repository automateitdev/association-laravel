<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Tenant\Ledger;
use App\Models\Tenant\LedgerTrace;
use App\Models\Tenant\Voucher;
use App\Models\User;
use App\Services\TenantSeedService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\TenantFixtures;
use Tests\TenantTestCase;

/**
 * Manual vouchers (FR-ACC-4, FR-ACC-6).
 *
 * A voucher is the one place a PERSON decides what the ledger says - payments
 * build both sides themselves and cannot post unbalanced. So the tests worth
 * writing are the ways a typed document can be wrong, and the ways an approved
 * one could be quietly changed afterwards.
 */
class VoucherTest extends TenantTestCase
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
                'name' => "Accountant {$n}",
                'email' => "voucher{$n}@assoc.test",
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

            $role = Role::findOrCreate("voucher-narrow-{$n}", 'web');
            $role->syncPermissions(
                array_map(fn (string $p) => Permission::findByName($p, 'web'), $permissions)
            );

            $user = User::create([
                'name' => "Narrow {$n}",
                'email' => "vouchernarrow{$n}@assoc.test",
                'password' => 'secret-password',
            ]);
            $user->assignRole($role);

            return $user->createToken('test', $user->getAllPermissions()->pluck('name')->all())
                ->plainTextToken;
        });
    }

    /** @return array{int, int} two ledger ids from the seeded chart */
    private function ledgers(): array
    {
        return $this->inTenant(function () {
            app(TenantSeedService::class)->seedAll();

            $ids = Ledger::orderBy('id')->limit(2)->pluck('id')->all();

            return [$ids[0], $ids[1]];
        });
    }

    /** A balanced journal: 900 out of one account, 900 into another. */
    private function balancedPayload(int $debitLedger, int $creditLedger, string $amount = '900.00'): array
    {
        return [
            'type' => 'journal',
            'voucher_date' => '2026-03-01',
            'narration' => 'Electricity for March',
            'lines' => [
                ['ledger_id' => $debitLedger, 'debit' => $amount, 'narration' => 'Expense'],
                ['ledger_id' => $creditLedger, 'credit' => $amount, 'narration' => 'Paid from bank'],
            ],
        ];
    }

    // ------------------------------------------------------------- drafting

    public function test_a_balanced_voucher_can_be_drafted_and_posts_nothing_yet(): void
    {
        $token = $this->staffToken();
        [$debit, $credit] = $this->ledgers();

        $response = $this->postJson(
            '/api/v1/staff/vouchers',
            $this->balancedPayload($debit, $credit),
            $this->headers($token),
        )->assertCreated();

        $response->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.total_debit', '900.00')
            ->assertJsonPath('data.total_credit', '900.00');

        // A number was allocated rather than asked for.
        $this->assertStringStartsWith('JV-', $response->json('data.voucher_no'));

        // THE POINT OF A DRAFT: nothing is in the ledger.
        $this->inTenant(fn () => $this->assertSame(
            0,
            LedgerTrace::where('source_type', Voucher::class)->count()
        ));
    }

    /**
     * The rule everything else serves. A payment cannot post unbalanced because
     * the code builds both sides; a voucher is typed, so this is where the
     * guarantee is earned.
     */
    public function test_an_unbalanced_voucher_is_refused_with_the_difference(): void
    {
        $token = $this->staffToken();
        [$debit, $credit] = $this->ledgers();

        $payload = $this->balancedPayload($debit, $credit);
        $payload['lines'][1]['credit'] = '500.00';

        $response = $this->postJson('/api/v1/staff/vouchers', $payload, $this->headers($token))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VOUCHER_REFUSED');

        // The difference is in the message: a reader who knows it is out by 400
        // usually knows which line is wrong.
        $this->assertStringContainsString('400.00', $response->json('error.message'));
    }

    public function test_a_line_cannot_carry_both_a_debit_and_a_credit(): void
    {
        $token = $this->staffToken();
        [$debit, $credit] = $this->ledgers();

        $payload = $this->balancedPayload($debit, $credit);
        $payload['lines'][0]['credit'] = '100.00';

        $this->postJson('/api/v1/staff/vouchers', $payload, $this->headers($token))
            ->assertStatus(422);
    }

    public function test_a_voucher_of_zero_posts_nothing_and_is_refused(): void
    {
        $token = $this->staffToken();
        [$debit, $credit] = $this->ledgers();

        $this->postJson(
            '/api/v1/staff/vouchers',
            $this->balancedPayload($debit, $credit, '0.00'),
            $this->headers($token),
        )->assertStatus(422);
    }

    public function test_one_line_is_not_a_double_entry(): void
    {
        $token = $this->staffToken();
        [$debit] = $this->ledgers();

        $this->postJson('/api/v1/staff/vouchers', [
            'type' => 'journal',
            'voucher_date' => '2026-03-01',
            'lines' => [['ledger_id' => $debit, 'debit' => '900.00']],
        ], $this->headers($token))->assertStatus(422);
    }

    // ------------------------------------------------------------ approving

    public function test_approving_posts_a_balanced_pair_to_the_ledger(): void
    {
        $token = $this->staffToken();
        [$debit, $credit] = $this->ledgers();

        $id = $this->postJson('/api/v1/staff/vouchers', $this->balancedPayload($debit, $credit), $this->headers($token))
            ->json('data.id');

        $this->postJson("/api/v1/staff/vouchers/{$id}/decide", ['decision' => 'approve'], $this->headers($token))
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->inTenant(function () use ($id) {
            $traces = LedgerTrace::where('source_type', Voucher::class)->where('source_id', $id)->get();

            $this->assertCount(2, $traces);

            // Compared as money, not as whatever Collection::sum() stringifies
            // to - it returns a number, so '900.00' arrives as '900'.
            $this->assertSame(0, bccomp('900.00', (string) $traces->sum('debit'), 2));
            $this->assertSame(0, bccomp('900.00', (string) $traces->sum('credit'), 2));
        });
    }

    public function test_rejecting_posts_nothing(): void
    {
        $token = $this->staffToken();
        [$debit, $credit] = $this->ledgers();

        $id = $this->postJson('/api/v1/staff/vouchers', $this->balancedPayload($debit, $credit), $this->headers($token))
            ->json('data.id');

        $this->postJson("/api/v1/staff/vouchers/{$id}/decide", ['decision' => 'reject'], $this->headers($token))
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $this->inTenant(fn () => $this->assertSame(
            0,
            LedgerTrace::where('source_type', Voucher::class)->count()
        ));
    }

    public function test_an_approved_voucher_cannot_be_approved_again(): void
    {
        $token = $this->staffToken();
        [$debit, $credit] = $this->ledgers();

        $id = $this->postJson('/api/v1/staff/vouchers', $this->balancedPayload($debit, $credit), $this->headers($token))
            ->json('data.id');

        $this->postJson("/api/v1/staff/vouchers/{$id}/decide", ['decision' => 'approve'], $this->headers($token))
            ->assertOk();

        $this->postJson("/api/v1/staff/vouchers/{$id}/decide", ['decision' => 'approve'], $this->headers($token))
            ->assertStatus(422);

        // And it did not post a second time.
        $this->inTenant(fn () => $this->assertSame(
            2,
            LedgerTrace::where('source_type', Voucher::class)->count()
        ));
    }

    /**
     * Its entries are in the ledger and have been reported on. Editing one
     * would change history silently, which is what reversal exists to avoid.
     */
    public function test_an_approved_voucher_cannot_be_edited_or_deleted(): void
    {
        $token = $this->staffToken();
        [$debit, $credit] = $this->ledgers();

        $id = $this->postJson('/api/v1/staff/vouchers', $this->balancedPayload($debit, $credit), $this->headers($token))
            ->json('data.id');

        $this->postJson("/api/v1/staff/vouchers/{$id}/decide", ['decision' => 'approve'], $this->headers($token));

        $this->putJson(
            "/api/v1/staff/vouchers/{$id}",
            $this->balancedPayload($debit, $credit, '50.00'),
            $this->headers($token),
        )->assertStatus(422);

        $this->deleteJson("/api/v1/staff/vouchers/{$id}", [], $this->headers($token))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VOUCHER_NOT_A_DRAFT');
    }

    public function test_a_draft_can_be_edited_and_deleted(): void
    {
        $token = $this->staffToken();
        [$debit, $credit] = $this->ledgers();

        $id = $this->postJson('/api/v1/staff/vouchers', $this->balancedPayload($debit, $credit), $this->headers($token))
            ->json('data.id');

        $this->putJson(
            "/api/v1/staff/vouchers/{$id}",
            $this->balancedPayload($debit, $credit, '1200.00'),
            $this->headers($token),
        )
            ->assertOk()
            ->assertJsonPath('data.total_debit', '1200.00');

        $this->deleteJson("/api/v1/staff/vouchers/{$id}", [], $this->headers($token))->assertOk();
    }

    // ------------------------------------------------------------ reversing

    public function test_reversing_posts_the_mirror_image_and_leaves_the_original(): void
    {
        $token = $this->staffToken();
        [$debit, $credit] = $this->ledgers();

        $id = $this->postJson('/api/v1/staff/vouchers', $this->balancedPayload($debit, $credit), $this->headers($token))
            ->json('data.id');

        $this->postJson("/api/v1/staff/vouchers/{$id}/decide", ['decision' => 'approve'], $this->headers($token));

        $this->postJson("/api/v1/staff/vouchers/{$id}/reverse", [
            'reason' => 'Posted to the wrong expense account.',
        ], $this->headers($token))
            ->assertCreated()
            ->assertJsonPath('data.status', 'approved');

        $this->inTenant(function () use ($id) {
            // The original entries are untouched.
            $this->assertCount(2, LedgerTrace::where('source_id', $id)->where('source_type', Voucher::class)->get());

            // And the reversal points at each of them.
            $reversing = LedgerTrace::whereNotNull('reverses_id')->get();
            $this->assertCount(2, $reversing);

            // Net effect on the ledger: nothing.
            $all = LedgerTrace::where('source_type', Voucher::class)->get();
            $this->assertSame(
                0,
                bccomp((string) $all->sum('debit'), (string) $all->sum('credit'), 2),
                'A voucher and its reversal must leave the ledger balanced.'
            );
        });
    }

    /**
     * Reversing twice cancels the cancellation, leaving the ledger looking
     * untouched with two unexplained pairs of entries in it.
     */
    public function test_a_voucher_cannot_be_reversed_twice(): void
    {
        $token = $this->staffToken();
        [$debit, $credit] = $this->ledgers();

        $id = $this->postJson('/api/v1/staff/vouchers', $this->balancedPayload($debit, $credit), $this->headers($token))
            ->json('data.id');

        $this->postJson("/api/v1/staff/vouchers/{$id}/decide", ['decision' => 'approve'], $this->headers($token));

        $this->postJson("/api/v1/staff/vouchers/{$id}/reverse", ['reason' => 'First reversal.'], $this->headers($token))
            ->assertCreated();

        $this->postJson("/api/v1/staff/vouchers/{$id}/reverse", ['reason' => 'Second attempt.'], $this->headers($token))
            ->assertStatus(422);
    }

    /**
     * The pair names itself in both directions.
     *
     * Without this, a list of vouchers can only learn that one has been
     * reversed by loading its ledger traces and asking whether anything points
     * at them - a query per row - so the screen offers a Reverse button the
     * server will only refuse.
     */
    public function test_a_reversal_and_its_original_each_name_the_other(): void
    {
        $token = $this->staffToken();
        [$debit, $credit] = $this->ledgers();

        $original = $this->postJson('/api/v1/staff/vouchers', $this->balancedPayload($debit, $credit), $this->headers($token))
            ->json('data');

        $this->postJson("/api/v1/staff/vouchers/{$original['id']}/decide", ['decision' => 'approve'], $this->headers($token));

        $reversal = $this->postJson("/api/v1/staff/vouchers/{$original['id']}/reverse", [
            'reason' => 'Posted to the wrong expense account.',
        ], $this->headers($token))->json('data');

        // The reversal says what it undoes.
        $this->assertSame($original['voucher_no'], $reversal['reverses']);
        $this->assertNull($reversal['reversed_by']);

        // And the original, re-read, says what undid it.
        $this->getJson("/api/v1/staff/vouchers/{$original['id']}", $this->headers($token))
            ->assertOk()
            ->assertJsonPath('data.reversed_by', $reversal['voucher_no'])
            ->assertJsonPath('data.reverses', null);

        $this->inTenant(function () use ($original, $reversal) {
            $this->assertSame($original['id'], Voucher::find($reversal['id'])->reverses_id);
        });
    }

    /**
     * The document-level link refuses on its own.
     *
     * Both halves of the guard are asked, and either is enough. Dropping the
     * traces here removes the check that was already proven and leaves only the
     * new one holding the door.
     */
    public function test_the_document_link_alone_refuses_a_second_reversal(): void
    {
        $token = $this->staffToken();
        [$debit, $credit] = $this->ledgers();

        $id = $this->postJson('/api/v1/staff/vouchers', $this->balancedPayload($debit, $credit), $this->headers($token))
            ->json('data.id');

        $this->postJson("/api/v1/staff/vouchers/{$id}/decide", ['decision' => 'approve'], $this->headers($token));
        $this->postJson("/api/v1/staff/vouchers/{$id}/reverse", ['reason' => 'First reversal.'], $this->headers($token))
            ->assertCreated();

        $this->inTenant(function () {
            LedgerTrace::whereNotNull('reverses_id')->update(['reverses_id' => null]);
        });

        $this->postJson("/api/v1/staff/vouchers/{$id}/reverse", ['reason' => 'Second attempt.'], $this->headers($token))
            ->assertStatus(422)
            ->assertJsonPath('error.message', 'This voucher has already been reversed.');
    }

    public function test_a_draft_cannot_be_reversed(): void
    {
        $token = $this->staffToken();
        [$debit, $credit] = $this->ledgers();

        $id = $this->postJson('/api/v1/staff/vouchers', $this->balancedPayload($debit, $credit), $this->headers($token))
            ->json('data.id');

        $this->postJson("/api/v1/staff/vouchers/{$id}/reverse", ['reason' => 'Not approved yet.'], $this->headers($token))
            ->assertStatus(422);
    }

    public function test_reversing_requires_a_reason(): void
    {
        $token = $this->staffToken();
        [$debit, $credit] = $this->ledgers();

        $id = $this->postJson('/api/v1/staff/vouchers', $this->balancedPayload($debit, $credit), $this->headers($token))
            ->json('data.id');

        $this->postJson("/api/v1/staff/vouchers/{$id}/decide", ['decision' => 'approve'], $this->headers($token));

        $this->postJson("/api/v1/staff/vouchers/{$id}/reverse", [], $this->headers($token))
            ->assertStatus(422);
    }

    // ---------------------------------------------------------- who may act

    public function test_writing_a_voucher_does_not_let_you_approve_it(): void
    {
        [$debit, $credit] = $this->ledgers();
        $token = $this->tokenWithPermissions(['vouchers.view', 'vouchers.create']);

        $id = $this->postJson('/api/v1/staff/vouchers', $this->balancedPayload($debit, $credit), $this->headers($token))
            ->assertCreated()
            ->json('data.id');

        $this->postJson("/api/v1/staff/vouchers/{$id}/decide", ['decision' => 'approve'], $this->headers($token))
            ->assertForbidden();
    }

    /**
     * Not forbidden - a two-person association could post nothing if it were -
     * but recorded, because an approval performed on one's own work is not the
     * control it looks like.
     */
    public function test_self_approval_is_allowed_and_flagged(): void
    {
        $token = $this->staffToken();
        [$debit, $credit] = $this->ledgers();

        $id = $this->postJson('/api/v1/staff/vouchers', $this->balancedPayload($debit, $credit), $this->headers($token))
            ->json('data.id');

        $this->postJson("/api/v1/staff/vouchers/{$id}/decide", ['decision' => 'approve'], $this->headers($token))
            ->assertOk()
            ->assertJsonPath('data.self_approved', true);
    }
}
