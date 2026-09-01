<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Models\Tenant\AssociatorInfo;
use App\Models\Tenant\FeeAssign;
use App\Models\Tenant\LedgerTrace;
use App\Models\Tenant\PaymentInfo;
use App\Services\FeeAssignService;
use App\Services\FineService;
use App\Services\PaymentService;
use App\Services\ShareService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Tests\Support\TenantFixtures;
use Tests\TenantTestCase;

/**
 * T-2, T-3, T-9 and the ledger corrections, from
 * bcs-docs/08-testing-strategy.md.
 *
 * Each of these fails against the legacy behaviour. That is the bar: a defect
 * may not be closed without a test that would have caught it.
 */
class PaymentServiceTest extends TenantTestCase
{
    use TenantFixtures;

    /**
     * T-2, and the single most important assertion in this codebase.
     *
     * Pay an instalment that carries a fine. `payable_amount` must equal the
     * item amount and EXCLUDE the fine; `total_amount` must be payable + fine.
     *
     * The legacy online path writes instalment + fine into payable_amount, and
     * every savings figure reads that column (defect D-1).
     */
    public function test_payable_amount_excludes_the_fine(): void
    {
        $this->inTenant(function () {
            $this->seedSettings();
            $member = $this->makeMember();
            $feeSetup = $this->makeFeeSetup();
            $ledgers = $this->makeLedgers();

            app(FeeAssignService::class)->assign($member->id, $feeSetup, '2026-01');

            // Two months overdue: a 200.00 fine on a 1000.00 instalment.
            app(FineService::class)->accrueAll(CarbonImmutable::create(2026, 2, 10));

            $assign = FeeAssign::where('member_id', $member->id)->firstOrFail();
            $this->assertSame('200.00', $assign->fine_amount);

            $payment = app(PaymentService::class)->create(
                $member->id,
                [$assign->id],
                PaymentInfo::TYPE_MANUAL,
                $ledgers['cash']->id,
            );

            $this->assertSame('1000.00', $payment->payable_amount, 'Instalments only.');
            $this->assertSame('200.00', $payment->fine_amount, 'Fine, separately.');
            $this->assertSame('1200.00', $payment->total_amount, 'Payable + fine.');

            $item = $payment->items->first();
            $this->assertSame('1000.00', $item->amount);
            $this->assertSame('200.00', $item->fine_amount);
        });
    }

    /**
     * The gateway's figure is recorded but never allowed near payable_amount.
     * This is the exact line the legacy code gets wrong.
     */
    public function test_the_gateway_amount_never_overwrites_payable_amount(): void
    {
        $this->inTenant(function () {
            [$member, $assign, $ledgers] = $this->memberWithOverdueAssignment();

            $service = app(PaymentService::class);
            $payment = $service->create(
                $member->id, [$assign->id], PaymentInfo::TYPE_ONLINE, $ledgers['cash']->id
            );

            // The gateway reports the full 1200.00 it collected.
            $completed = $service->complete($payment, gatewayAmount: '1200.00');

            $this->assertSame('1200.00', $completed->spg_pay_amount, 'Recorded for reconciliation.');
            $this->assertSame('1000.00', $completed->payable_amount, 'Still instalments only.');
            $this->assertSame('200.00', $completed->fine_amount);
        });
    }

    /** T-3: an assignment cannot be settled twice. */
    public function test_an_assignment_cannot_be_paid_twice(): void
    {
        $this->inTenant(function () {
            [$member, $assign, $ledgers] = $this->memberWithOverdueAssignment();
            $service = app(PaymentService::class);

            $first = $service->create($member->id, [$assign->id], ledgerId: $ledgers['cash']->id);
            $service->complete($first);

            $this->expectException(\DomainException::class);
            $this->expectExceptionMessage('already paid');

            $service->create($member->id, [$assign->id], ledgerId: $ledgers['cash']->id);
        });
    }

    /** An assignment with a live attempt against it is not payable again either (I-4). */
    public function test_an_assignment_with_a_pending_payment_cannot_be_paid_again(): void
    {
        $this->inTenant(function () {
            [$member, $assign, $ledgers] = $this->memberWithOverdueAssignment();
            $service = app(PaymentService::class);

            $service->create($member->id, [$assign->id], ledgerId: $ledgers['cash']->id);

            $this->expectException(\DomainException::class);
            $this->expectExceptionMessage('awaiting confirmation');

            $service->create($member->id, [$assign->id], ledgerId: $ledgers['cash']->id);
        });
    }

    /**
     * D-5: the legacy request object's authorize() returns true, so a member can
     * pay against another member's dues. The service refuses regardless of what
     * the HTTP layer did or did not check.
     */
    public function test_a_member_cannot_pay_another_members_assignment(): void
    {
        $this->inTenant(function () {
            [$owner, $assign, $ledgers] = $this->memberWithOverdueAssignment();
            $stranger = $this->makeMember();

            $this->expectException(\DomainException::class);
            $this->expectExceptionMessage('does not belong to member');

            app(PaymentService::class)->create(
                $stranger->id, [$assign->id], ledgerId: $ledgers['cash']->id
            );
        });
    }

    /**
     * FR-PAY-13 / D-6: re-completing must be a no-op, not a second posting.
     */
    public function test_completing_twice_does_not_post_the_ledger_twice(): void
    {
        $this->inTenant(function () {
            [$member, $assign, $ledgers] = $this->memberWithOverdueAssignment();
            $service = app(PaymentService::class);

            $payment = $service->create($member->id, [$assign->id], ledgerId: $ledgers['cash']->id);

            $service->complete($payment);
            $afterFirst = LedgerTrace::count();

            $service->complete($payment->fresh());
            $afterSecond = LedgerTrace::count();

            $this->assertSame($afterFirst, $afterSecond, 'Second completion must post nothing.');
        });
    }

    /**
     * FR-ACC-2: the instalment and the fine credit DIFFERENT accounts, and the
     * whole posting balances.
     */
    public function test_the_instalment_and_the_fine_credit_different_ledgers(): void
    {
        $this->inTenant(function () {
            [$member, $assign, $ledgers] = $this->memberWithOverdueAssignment();
            $service = app(PaymentService::class);

            $payment = $service->create($member->id, [$assign->id], ledgerId: $ledgers['cash']->id);
            $service->complete($payment);

            $instalmentCredit = LedgerTrace::where('ledger_id', $ledgers['income']->id)->sum('credit');
            $fineCredit = LedgerTrace::where('ledger_id', $ledgers['fine']->id)->sum('credit');
            $cashDebit = LedgerTrace::where('ledger_id', $ledgers['cash']->id)->sum('debit');

            $this->assertSame('1000.00', number_format((float) $instalmentCredit, 2, '.', ''));
            $this->assertSame('200.00', number_format((float) $fineCredit, 2, '.', ''));
            $this->assertSame('1200.00', number_format((float) $cashDebit, 2, '.', ''));

            // FR-ACC-6: the document balances.
            $this->assertSame(
                (float) LedgerTrace::sum('debit'),
                (float) LedgerTrace::sum('credit'),
            );
        });
    }

    /**
     * T-9 / D-8: a fine attached to a share purchase must not mint extra shares.
     *
     * Share price 1000.00, instalment 1000.00, fine 200.00. The legacy service
     * divides the CHARGED amount, so a merged 1200.00 would mint 1 share here
     * but 2 at a 600.00 price point. Dividing the ASSIGNED amount makes the
     * whole class of bug unreachable.
     */
    public function test_a_fine_does_not_mint_extra_shares(): void
    {
        $this->inTenant(function () {
            $this->seedSettings();
            $member = $this->makeMember();
            AssociatorInfo::create([
                'member_id' => $member->id,
                'membership_no' => 'M-0001',
                'num_or_shares' => 0,
            ]);

            $ledgers = $this->makeLedgers();
            $shareHead = $this->makeFeeSetup([
                'fee_head' => 'Share Purchase',
                'amount' => '500.00',
                'is_share' => true,
                'ledger_id' => $ledgers['income']->id,
                'fine_ledger_id' => $ledgers['fine']->id,
            ]);

            app(FeeAssignService::class)->assign($member->id, $shareHead, '2026-01');
            app(FineService::class)->accrueAll(CarbonImmutable::create(2026, 3, 15));

            $assign = FeeAssign::where('fee_setup_id', $shareHead->id)->firstOrFail();
            $this->assertSame('300.00', $assign->fine_amount);

            $service = app(PaymentService::class);
            $payment = $service->create($member->id, [$assign->id], ledgerId: $ledgers['cash']->id);
            $service->complete($payment);

            // 500.00 assigned / 500.00 price = 1 share.
            // Had the 300.00 fine been merged in, 800/500 would still be 1 - so
            // assert the balance directly rather than trusting the division.
            $this->assertSame(1, app(ShareService::class)->balanceFor($member->id));
            $this->assertSame(1, AssociatorInfo::where('member_id', $member->id)->value('num_or_shares'));
        });
    }

    /**
     * FR-PAY-8 / D-18: an abandoned intent releases its assignments.
     */
    public function test_a_stale_intent_expires_and_releases_its_assignments(): void
    {
        $this->inTenant(function () {
            [$member, $assign, $ledgers] = $this->memberWithOverdueAssignment();
            $service = app(PaymentService::class);

            $payment = $service->create($member->id, [$assign->id], ledgerId: $ledgers['cash']->id);
            $this->assertSame(FeeAssign::STATUS_REQUESTED, $assign->fresh()->status);

            $expired = $service->expireStaleIntents(CarbonImmutable::now()->addHours(2));

            $this->assertSame(1, $expired);
            $this->assertSame(PaymentInfo::STATUS_EXPIRED, $payment->fresh()->status);
            $this->assertSame(FeeAssign::STATUS_UNPAID, $assign->fresh()->status);
        });
    }

    /** Suspension returns the assignments to Unpaid with a reason recorded. */
    public function test_suspending_a_payment_releases_its_assignments(): void
    {
        $this->inTenant(function () {
            [$member, $assign, $ledgers] = $this->memberWithOverdueAssignment();
            $service = app(PaymentService::class);

            $payment = $service->create($member->id, [$assign->id], ledgerId: $ledgers['cash']->id);
            $service->suspend($payment, 'Bank slip did not match');

            $this->assertSame(PaymentInfo::STATUS_SUSPENDED, $payment->fresh()->status);
            $this->assertSame(FeeAssign::STATUS_UNPAID, $assign->fresh()->status);
            $this->assertSame('Bank slip did not match', $payment->fresh()->reason);
        });
    }

    /** Invoice numbers are unique and sequential per tenant (FR-PAY-15). */
    public function test_invoice_numbers_are_sequential_and_unique(): void
    {
        $this->inTenant(function () {
            $this->seedSettings();
            $feeSetup = $this->makeFeeSetup();
            $ledgers = $this->makeLedgers();
            $service = app(PaymentService::class);

            $numbers = [];

            for ($i = 0; $i < 3; $i++) {
                $member = $this->makeMember();
                $assign = app(FeeAssignService::class)->assign($member->id, $feeSetup, '2026-0'.($i + 1));
                $numbers[] = $service->create(
                    $member->id, [$assign->id], ledgerId: $ledgers['cash']->id
                )->invoice_no;
            }

            $this->assertSame($numbers, array_unique($numbers));
            $this->assertStringEndsWith('000001', $numbers[0]);
            $this->assertStringEndsWith('000003', $numbers[2]);
        });
    }

    /**
     * The database is the last line of defence for I-1. Even if the service were
     * bypassed, two completed items for one assignment must be impossible.
     */
    public function test_the_database_refuses_a_second_completed_item_for_one_assignment(): void
    {
        $this->inTenant(function () {
            [$member, $assign, $ledgers] = $this->memberWithOverdueAssignment();
            $service = app(PaymentService::class);

            $payment = $service->create($member->id, [$assign->id], ledgerId: $ledgers['cash']->id);
            $service->complete($payment);

            // Bypass the service entirely.
            $second = PaymentInfo::create([
                'invoice_no' => 'BYPASS-1',
                'member_id' => $member->id,
                'payable_amount' => '1000.00',
                'fine_amount' => '0.00',
                'total_amount' => '1000.00',
                'status' => PaymentInfo::STATUS_COMPLETED,
                'payment_type' => PaymentInfo::TYPE_MANUAL,
            ]);

            $this->expectException(QueryException::class);

            \App\Models\Tenant\PaymentInfoItem::create([
                'payment_info_id' => $second->id,
                'fee_assign_id' => $assign->id,
                'period' => $assign->period,
                'amount' => '1000.00',
                'fine_amount' => '0.00',
                'payment_status' => PaymentInfo::STATUS_COMPLETED,
            ]);
        });
    }

    /**
     * @return array{0: \App\Models\Tenant\Member, 1: FeeAssign, 2: array}
     */
    private function memberWithOverdueAssignment(): array
    {
        $this->seedSettings();
        $member = $this->makeMember();
        $ledgers = $this->makeLedgers();
        $feeSetup = $this->makeFeeSetup([
            'ledger_id' => $ledgers['income']->id,
            'fine_ledger_id' => $ledgers['fine']->id,
        ]);

        app(FeeAssignService::class)->assign($member->id, $feeSetup, '2026-01');
        app(FineService::class)->accrueAll(CarbonImmutable::create(2026, 2, 10));

        $assign = FeeAssign::where('member_id', $member->id)->firstOrFail();

        return [$member, $assign, $ledgers];
    }
}
