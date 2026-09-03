<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Audit\PaymentAudit;
use App\Models\Tenant\FeeAssign;
use App\Models\Tenant\LedgerTrace;
use App\Models\Tenant\MemberShareBalance;
use App\Models\Tenant\PaymentInfo;
use App\Models\Tenant\PaymentInfoItem;
use App\Models\User;
use App\Services\TenantSeedService;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\TenantFixtures;
use Tests\TenantTestCase;

/**
 * FR-REP-6, the payment inconsistency audit.
 *
 * WHAT THESE TESTS ARE FOR
 * ------------------------
 * An audit is the one kind of report where "it returned nothing" is the
 * expected answer, which makes it uniquely easy to get wrong: a check that is
 * silently broken and a check that found nothing look identical from outside.
 * The legacy audit ran for three years returning zero while two defects were
 * corrupting member-visible figures.
 *
 * So every test here breaks the data on purpose and insists the audit notices.
 * A test that only asserts a clean database produces no findings would have
 * passed against the legacy service too.
 */
class InconsistencyAuditTest extends TenantTestCase
{
    use TenantFixtures;

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
        return $this->inTenant(function () {
            app(TenantSeedService::class)->seedAll();

            static $sequence = 0;
            $sequence++;

            $user = User::create([
                'name' => "Auditor {$sequence}",
                'email' => "audit{$sequence}@assoc.test",
                'password' => 'secret-password',
            ]);
            $user->assignRole('superadmin');

            return $user->createToken('test', $user->getAllPermissions()->pluck('name')->all())
                ->plainTextToken;
        });
    }

    /** Incremented outside the closure: a `static` inside one resets on each
     * call, which collides when a single test needs two accounts. */
    private int $narrowSequence = 0;

    /** @param  list<string>  $permissions */
    private function tokenWithPermissions(array $permissions): string
    {
        $sequence = ++$this->narrowSequence;

        return $this->inTenant(function () use ($permissions, $sequence) {
            app(TenantSeedService::class)->seedAll();

            $role = Role::findOrCreate("audit-narrow-{$sequence}", 'web');
            $role->syncPermissions(
                array_map(fn (string $p) => Permission::findByName($p, 'web'), $permissions)
            );

            $user = User::create([
                'name' => "Narrow {$sequence}",
                'email' => "auditnarrow{$sequence}@assoc.test",
                'password' => 'secret-password',
            ]);
            $user->assignRole($role);

            return $user->createToken('test', $user->getAllPermissions()->pluck('name')->all())
                ->plainTextToken;
        });
    }

    /**
     * One completed payment for one instalment, correct in every respect,
     * with its ledger entries. The starting point every test then breaks.
     *
     * @return array{payment: PaymentInfo, item: PaymentInfoItem, assign: FeeAssign}
     */
    private function seedGoodPayment(array $paymentOverrides = []): array
    {
        $this->seedSettings();

        $setup = $this->makeFeeSetup();
        $member = $this->makeMember();

        $member->associatorInfo()->create([
            'membership_no' => '114',
            'num_or_shares' => 0,
        ]);

        $assign = FeeAssign::create([
            'member_id' => $member->id,
            'fee_setup_id' => $setup->id,
            'period' => '2026-01',
            'assign_date' => '2026-01-01',
            'fine_date' => '2026-01-10',
            'amount' => '1000.00',
            'fine_amount' => '0.00',
            'status' => 'Paid',
        ]);

        $payment = PaymentInfo::create(array_merge([
            'invoice_no' => 'INV-AUDIT-'.$assign->id,
            'member_id' => $member->id,
            'ledger_id' => $setup->ledger_id,
            'payable_amount' => '1000.00',
            'fine_amount' => '0.00',
            'total_amount' => '1000.00',
            'status' => 'completed',
            'payment_type' => 'manual',
            'payment_date' => '2026-01-05',
        ], $paymentOverrides));

        $item = PaymentInfoItem::create([
            'payment_info_id' => $payment->id,
            'fee_assign_id' => $assign->id,
            'period' => '2026-01',
            'amount' => '1000.00',
            'fine_amount' => '0.00',
            'payment_status' => 'completed',
        ]);

        // The balanced pair LedgerService would have written.
        foreach ([['debit' => '1000.00', 'credit' => '0.00'], ['debit' => '0.00', 'credit' => '1000.00']] as $side) {
            LedgerTrace::create($side + [
                'ledger_id' => $setup->ledger_id,
                'source_type' => PaymentInfo::class,
                'source_id' => $payment->id,
                'reference' => $payment->invoice_no,
                'posted_on' => '2026-01-05',
            ]);
        }

        return ['payment' => $payment, 'item' => $item, 'assign' => $assign];
    }

    /** @return list<string> the check ids the audit reported */
    private function checksFound(): array
    {
        return $this->inTenant(function () {
            return array_map(
                fn ($f) => $f->check,
                (new PaymentAudit())->run()
            );
        });
    }

    // --------------------------------------------------------- the happy case

    /** Correct data must produce nothing, or every other test here is noise. */
    public function test_correct_data_produces_no_findings(): void
    {
        $this->inTenant(fn () => $this->seedGoodPayment());

        $this->assertSame([], $this->checksFound());
    }

    // ------------------------------------------------- each check must fire

    public function test_it_catches_a_total_that_is_not_instalments_plus_fine(): void
    {
        $this->inTenant(function () {
            $seed = $this->seedGoodPayment();
            // Fine recorded, but not added into the total.
            $seed['payment']->update(['fine_amount' => '100.00', 'total_amount' => '1000.00']);
        });

        $this->assertContains('TOTAL_MISMATCH', $this->checksFound());
    }

    public function test_it_catches_an_instalment_total_that_disagrees_with_its_items(): void
    {
        $this->inTenant(function () {
            $seed = $this->seedGoodPayment();
            $seed['payment']->update(['payable_amount' => '2000.00', 'total_amount' => '2000.00']);
        });

        $this->assertContains('PAYABLE_NOT_SUM_OF_ITEMS', $this->checksFound());
    }

    /**
     * The check the legacy system could not have had: it had nowhere
     * consistent to keep a fine, so there was nothing to reconcile against.
     */
    public function test_it_catches_a_fine_total_that_disagrees_with_its_items(): void
    {
        $this->inTenant(function () {
            $seed = $this->seedGoodPayment();
            $seed['payment']->update(['fine_amount' => '100.00', 'total_amount' => '1100.00']);
            // Items still carry no fine, so the header fine is unsupported.
        });

        $this->assertContains('FINE_NOT_SUM_OF_ITEMS', $this->checksFound());
    }

    public function test_it_catches_an_item_charging_more_than_its_assignment(): void
    {
        $this->inTenant(function () {
            $seed = $this->seedGoodPayment();
            $seed['assign']->update(['amount' => '500.00']);
        });

        $this->assertContains('ITEM_AMOUNT_DIFFERS_FROM_ASSIGN', $this->checksFound());
    }

    /** D-20: 716 legacy payments were in this state, unnoticed for three years. */
    public function test_it_catches_a_completed_payment_with_no_ledger_entry(): void
    {
        $this->inTenant(function () {
            $seed = $this->seedGoodPayment();

            LedgerTrace::where('source_type', PaymentInfo::class)
                ->where('source_id', $seed['payment']->id)
                ->delete();
        });

        $this->assertContains('NO_LEDGER_POSTING', $this->checksFound());
    }

    public function test_it_catches_ledger_entries_that_do_not_balance(): void
    {
        $this->inTenant(function () {
            $seed = $this->seedGoodPayment();

            LedgerTrace::where('source_type', PaymentInfo::class)
                ->where('source_id', $seed['payment']->id)
                ->where('credit', '>', 0)
                ->delete();
        });

        $this->assertContains('UNBALANCED_POSTING', $this->checksFound());
    }

    public function test_it_catches_a_completed_payment_naming_no_ledger(): void
    {
        $this->inTenant(function () {
            $seed = $this->seedGoodPayment();
            $seed['payment']->update(['ledger_id' => null]);
        });

        $this->assertContains('COMPLETED_WITHOUT_LEDGER', $this->checksFound());
    }

    /**
     * D-19, the defect that was actively corrupting member-visible figures
     * while the legacy audit reported zero findings.
     */
    public function test_it_catches_a_share_balance_nobody_paid_for(): void
    {
        $this->inTenant(function () {
            $this->seedSettings();

            $setup = $this->makeFeeSetup(['is_share' => true, 'amount' => '1000.00']);
            $member = $this->makeMember();
            $member->associatorInfo()->create(['membership_no' => '115', 'num_or_shares' => 0]);

            // Shares held, no payment anywhere behind them.
            MemberShareBalance::create([
                'member_id' => $member->id,
                'fee_setup_id' => $setup->id,
                'shares' => 3,
            ]);
        });

        $this->assertContains('SHARE_BALANCE_MISMATCH', $this->checksFound());
    }

    /**
     * The mirror image, and the one a spot-check misses: the member paid but
     * holds no balance row at all, so there is no wrong number to notice.
     */
    public function test_it_catches_shares_paid_for_but_never_credited(): void
    {
        $this->inTenant(function () {
            $this->seedSettings();

            $setup = $this->makeFeeSetup(['is_share' => true, 'amount' => '1000.00']);
            $member = $this->makeMember();
            $member->associatorInfo()->create(['membership_no' => '116', 'num_or_shares' => 0]);

            $assign = FeeAssign::create([
                'member_id' => $member->id,
                'fee_setup_id' => $setup->id,
                'period' => '2026-02',
                'assign_date' => '2026-02-01',
                'fine_date' => '2026-02-10',
                'amount' => '1000.00',
                'fine_amount' => '0.00',
                'status' => 'Paid',
            ]);

            $payment = PaymentInfo::create([
                'invoice_no' => 'INV-SHARE-UNCREDITED',
                'member_id' => $member->id,
                'ledger_id' => $setup->ledger_id,
                'payable_amount' => '1000.00',
                'fine_amount' => '0.00',
                'total_amount' => '1000.00',
                'status' => 'completed',
                'payment_type' => 'manual',
                'payment_date' => '2026-02-05',
            ]);

            PaymentInfoItem::create([
                'payment_info_id' => $payment->id,
                'fee_assign_id' => $assign->id,
                'period' => '2026-02',
                'amount' => '1000.00',
                'fine_amount' => '0.00',
                'payment_status' => 'completed',
            ]);

            foreach ([['debit' => '1000.00', 'credit' => '0.00'], ['debit' => '0.00', 'credit' => '1000.00']] as $side) {
                LedgerTrace::create($side + [
                    'ledger_id' => $setup->ledger_id,
                    'source_type' => PaymentInfo::class,
                    'source_id' => $payment->id,
                    'reference' => $payment->invoice_no,
                    'posted_on' => '2026-02-05',
                ]);
            }
        });

        $found = $this->checksFound();

        $this->assertContains('SHARE_BALANCE_MISMATCH', $found);
    }

    /**
     * A transfer legitimately moves a member's balance away from what they
     * paid for, so the check must account for it or it cries wolf on every
     * share the association ever transfers.
     */
    public function test_a_transferred_share_is_not_reported_as_a_mismatch(): void
    {
        $this->inTenant(function () {
            $this->seedSettings();

            $setup = $this->makeFeeSetup(['is_share' => true, 'amount' => '1000.00']);
            $buyer = $this->makeMember();
            $buyer->associatorInfo()->create(['membership_no' => '117', 'num_or_shares' => 0]);

            MemberShareBalance::create([
                'member_id' => $buyer->id,
                'fee_setup_id' => $setup->id,
                'shares' => 2,
            ]);

            $seller = $this->makeMember();
            $seller->associatorInfo()->create(['membership_no' => '118', 'num_or_shares' => 0]);

            MemberShareBalance::create([
                'member_id' => $seller->id,
                'fee_setup_id' => $setup->id,
                'shares' => -2,
            ]);

            DB::table('share_transfers')->insert([
                'seller_id' => $seller->id,
                'buyer_id' => $buyer->id,
                'fee_setup_id' => $setup->id,
                'shares' => 2,
                'amount' => '2000.00',
                'transferred_on' => '2026-03-01',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertNotContains('SHARE_BALANCE_MISMATCH', $this->checksFound());
    }

    public function test_it_catches_an_instalment_marked_paid_with_no_payment(): void
    {
        $this->inTenant(function () {
            $seed = $this->seedGoodPayment();
            // Remove the payment, leave the assignment claiming it was paid.
            PaymentInfoItem::where('id', $seed['item']->id)->delete();
            LedgerTrace::where('source_id', $seed['payment']->id)->delete();
            PaymentInfo::where('id', $seed['payment']->id)->delete();
        });

        $this->assertContains('PAID_WITHOUT_PAYMENT', $this->checksFound());
    }

    public function test_it_catches_an_instalment_paid_but_not_marked(): void
    {
        $this->inTenant(function () {
            $seed = $this->seedGoodPayment();
            $seed['assign']->update(['status' => 'Unpaid']);
        });

        $this->assertContains('PAID_NOT_MARKED', $this->checksFound());
    }

    public function test_it_catches_an_item_period_disagreeing_with_its_assignment(): void
    {
        $this->inTenant(function () {
            $seed = $this->seedGoodPayment();
            $seed['item']->update(['period' => '2026-07']);
        });

        $this->assertContains('ITEM_PERIOD_MISMATCH', $this->checksFound());
    }

    /**
     * D-21. The tab is the point: MySQL's TRIM() does not strip it, so a check
     * written with TRIM() reports nothing here - which is exactly the mistake
     * the first version of the legacy tooling made.
     */
    public function test_it_catches_whitespace_in_an_invoice_number(): void
    {
        $this->inTenant(function () {
            $seed = $this->seedGoodPayment();

            DB::table('payment_infos')
                ->where('id', $seed['payment']->id)
                ->update(['invoice_no' => $seed['payment']->invoice_no."\t"]);
        });

        $this->assertContains('INVOICE_WHITESPACE', $this->checksFound());
    }

    // ------------------------------------------------------------ the endpoint

    public function test_it_lists_every_check_that_ran_not_only_the_hits(): void
    {
        $token = $this->staffToken();
        $this->inTenant(fn () => $this->seedGoodPayment());

        $response = $this->getJson('/api/v1/staff/reports/inconsistencies', $this->headers($token));

        $response->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonCount(count(PaymentAudit::CHECKS), 'meta.checks');

        // "Cannot happen" is a different assurance from "we found nothing",
        // and the report has to be able to say which one it means.
        $this->assertNotEmpty($response->json('meta.structurally_prevented'));
    }

    public function test_it_reports_a_finding_with_a_subject_staff_can_act_on(): void
    {
        $token = $this->staffToken();

        $this->inTenant(function () {
            $seed = $this->seedGoodPayment();
            $seed['payment']->update(['fine_amount' => '100.00', 'total_amount' => '1000.00']);
        });

        $response = $this->getJson('/api/v1/staff/reports/inconsistencies', $this->headers($token));

        /*
          Not an exact count: a fine recorded in the header but absent from the
          items legitimately trips FINE_NOT_SUM_OF_ITEMS as well, and asserting
          "exactly one" would make the audit worse to be right.
        */
        $response->assertOk();
        $this->assertGreaterThanOrEqual(1, $response->json('meta.by_severity.high'));

        $finding = collect($response->json('data'))->firstWhere('check', 'TOTAL_MISMATCH');

        $this->assertNotNull($finding);
        // An invoice number, not a primary key: staff cannot look up row 3,544.
        $this->assertStringContainsString('INV-AUDIT-', $finding['subject']);
        $this->assertStringContainsString('100.00', $finding['detail']);
    }

    public function test_an_unknown_check_name_is_rejected_rather_than_ignored(): void
    {
        $token = $this->staffToken();

        $this->getJson(
            '/api/v1/staff/reports/inconsistencies?checks=NOT_A_CHECK',
            $this->headers($token)
        )->assertStatus(422);
    }

    public function test_it_runs_only_the_checks_asked_for(): void
    {
        $token = $this->staffToken();

        $this->inTenant(function () {
            $seed = $this->seedGoodPayment();
            $seed['payment']->update(['fine_amount' => '100.00', 'total_amount' => '1000.00']);
            $seed['assign']->update(['status' => 'Unpaid']);
        });

        $all = $this->getJson('/api/v1/staff/reports/inconsistencies', $this->headers($token));
        $one = $this->getJson(
            '/api/v1/staff/reports/inconsistencies?checks=PAID_NOT_MARKED',
            $this->headers($token)
        );

        $this->assertGreaterThan($one->json('meta.total'), $all->json('meta.total'));
        $this->assertSame(['PAID_NOT_MARKED' => 1], $one->json('meta.by_check'));
    }

    public function test_it_requires_the_inconsistency_permission(): void
    {
        $token = $this->tokenWithPermissions(['reports.due']);

        $this->getJson('/api/v1/staff/reports/inconsistencies', $this->headers($token))
            ->assertForbidden();
    }

    /**
     * Reading a report on screen and walking out with the file are separate
     * permissions (FR-REP-7), and this report names members whose figures
     * are wrong.
     */
    public function test_the_export_needs_both_permissions(): void
    {
        $readOnly = $this->tokenWithPermissions(['reports.inconsistency']);

        $this->getJson(
            '/api/v1/staff/reports/inconsistencies/export?format=csv',
            $this->headers($readOnly)
        )->assertForbidden();

        $both = $this->tokenWithPermissions(['reports.inconsistency', 'reports.export']);

        $this->get(
            '/api/v1/staff/reports/inconsistencies/export?format=csv',
            $this->headers($both)
        )->assertOk();
    }

    public function test_the_export_carries_the_findings(): void
    {
        $token = $this->staffToken();

        $this->inTenant(function () {
            $seed = $this->seedGoodPayment();
            $seed['payment']->update(['ledger_id' => null]);
        });

        $response = $this->get(
            '/api/v1/staff/reports/inconsistencies/export?format=csv',
            $this->headers($token)
        );

        $response->assertOk();

        $csv = $response->streamedContent();

        $this->assertStringContainsString('COMPLETED_WITHOUT_LEDGER', $csv);
        $this->assertStringContainsString('INV-AUDIT-', $csv);
    }
}
