<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Models\Tenant\FeeAssign;
use App\Models\Tenant\FineDate;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Services\FeeAssignService;
use App\Services\FineService;
use Carbon\CarbonImmutable;
use Tests\Support\TenantFixtures;
use Tests\TenantTestCase;

/**
 * T-1 from bcs-docs/08-testing-strategy.md, plus the idempotency the legacy
 * command lacks.
 */
class FineAccrualTest extends TenantTestCase
{
    use TenantFixtures;

    /**
     * T-1: assign a fee, run the fine clock three periods forward, assert the
     * fine is 3 x rate and the member is suspended.
     */
    public function test_three_overdue_periods_produce_three_fines_and_a_suspension(): void
    {
        $this->inTenant(function () {
            $this->seedSettings();
            $member = $this->makeMember();
            $feeSetup = $this->makeFeeSetup();

            $assign = app(FeeAssignService::class)->assign($member->id, $feeSetup, '2026-01');

            // Three fine dates have elapsed: 1 Jan, 1 Feb, 1 Mar.
            $summary = app(FineService::class)->accrueAll(
                CarbonImmutable::create(2026, 3, 15)
            );

            $assign->refresh();
            $member->refresh();

            $this->assertSame('300.00', $assign->fine_amount, 'Fine should be 3 x 100.00');

            // The instalment is untouched. This is the assertion that would fail
            // if anything merged the fine into it (ADR-0005).
            $this->assertSame('1000.00', $assign->amount);

            $this->assertSame(Member::STATUS_SUSPENDED, $member->status);
            $this->assertSame(1, $summary['suspended']);
        });
    }

    /**
     * FR-FINE-3. The legacy command increments the fine, so a second run in one
     * day doubles it. This one recomputes from the fine-date series.
     */
    public function test_running_accrual_twice_in_one_day_does_not_double_the_fine(): void
    {
        $this->inTenant(function () {
            $this->seedSettings();
            $member = $this->makeMember();
            $feeSetup = $this->makeFeeSetup();
            $assign = app(FeeAssignService::class)->assign($member->id, $feeSetup, '2026-01');

            $asOf = CarbonImmutable::create(2026, 2, 10);

            app(FineService::class)->accrueAll($asOf);
            $firstRun = $assign->fresh()->fine_amount;

            app(FineService::class)->accrueAll($asOf);
            $secondRun = $assign->fresh()->fine_amount;

            $this->assertSame('200.00', $firstRun);
            $this->assertSame($firstRun, $secondRun, 'A second run must change nothing.');

            // And the series has not grown spurious rows either.
            $this->assertSame(
                2,
                FineDate::where('fee_assign_id', $assign->id)
                    ->where('fine_date', '<=', $asOf->toDateString())
                    ->count()
            );
        });
    }

    /** The rate is per association, not a class constant (FR-SET-2). */
    public function test_the_fine_rate_comes_from_association_settings(): void
    {
        $this->inTenant(function () {
            $this->seedSettings([Setting::FINE_RATE => '250.00']);
            $member = $this->makeMember();
            $feeSetup = $this->makeFeeSetup();
            $assign = app(FeeAssignService::class)->assign($member->id, $feeSetup, '2026-01');

            app(FineService::class)->accrueAll(CarbonImmutable::create(2026, 2, 10));

            $this->assertSame('500.00', $assign->fresh()->fine_amount, '2 periods x 250.00');
        });
    }

    /** The threshold is per association too. */
    public function test_the_suspension_threshold_comes_from_settings(): void
    {
        $this->inTenant(function () {
            $this->seedSettings([Setting::SUSPENSION_THRESHOLD => 2]);
            $member = $this->makeMember();
            $feeSetup = $this->makeFeeSetup();
            app(FeeAssignService::class)->assign($member->id, $feeSetup, '2026-01');

            app(FineService::class)->accrueAll(CarbonImmutable::create(2026, 2, 10));

            $this->assertSame(Member::STATUS_SUSPENDED, $member->fresh()->status);
        });
    }

    public function test_a_paid_assignment_stops_accruing(): void
    {
        $this->inTenant(function () {
            $this->seedSettings();
            $member = $this->makeMember();
            $feeSetup = $this->makeFeeSetup();
            $assign = app(FeeAssignService::class)->assign($member->id, $feeSetup, '2026-01');

            $assign->update(['status' => FeeAssign::STATUS_PAID]);

            app(FineService::class)->accrueAll(CarbonImmutable::create(2026, 6, 1));

            $this->assertSame('0.00', $assign->fresh()->fine_amount);
        });
    }

    /**
     * FR-TEN-12 in miniature: one member's failure must not stop the others.
     * Here the "failure" is simply that two members are processed independently
     * and both get their fines - the legacy single-transaction design would roll
     * both back if either threw.
     */
    public function test_accrual_processes_each_member_independently(): void
    {
        $this->inTenant(function () {
            $this->seedSettings();
            $feeSetup = $this->makeFeeSetup();

            $first = $this->makeMember();
            $second = $this->makeMember();

            app(FeeAssignService::class)->assign($first->id, $feeSetup, '2026-01');
            app(FeeAssignService::class)->assign($second->id, $feeSetup, '2026-01');

            $summary = app(FineService::class)->accrueAll(CarbonImmutable::create(2026, 2, 10));

            $this->assertSame(2, $summary['examined']);
            $this->assertSame(2, $summary['fined']);
            $this->assertSame([], $summary['failed']);
        });
    }
}
