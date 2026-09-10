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
     * THE FINE DAY CAN BE SET PER ASSIGNMENT, as the legacy screen allows.
     *
     * Its fee-assign form carries a fine-day dropdown defaulting to the 21st,
     * and associations use it: a fee agreed on different terms, a month where
     * the committee allowed longer, a correction re-entered as it originally
     * stood. The association's grace period is still the rule - this is the
     * exception to it.
     */
    public function test_a_fine_day_puts_the_first_fine_on_that_day_of_the_month(): void
    {
        $this->inTenant(function () {
            $this->seedSettings();
            $member = $this->makeMember();
            $feeSetup = $this->makeFeeSetup();

            $assign = app(FeeAssignService::class)->assign($member->id, $feeSetup, '2026-01', fineDay: 21);

            $this->assertSame('2026-01-21', CarbonImmutable::parse($assign->fine_date)->toDateString());

            // Nothing is owed on the 20th, and one fine is owed on the 21st -
            // the boundary is the whole point of choosing a day.
            app(FineService::class)->accrueAll(CarbonImmutable::create(2026, 1, 20));
            $this->assertSame('0.00', (string) $assign->refresh()->fine_amount);

            app(FineService::class)->accrueAll(CarbonImmutable::create(2026, 1, 21));
            $this->assertSame('100.00', (string) $assign->refresh()->fine_amount);
        });
    }

    /**
     * A day past the end of a short month lands on its last day, not in the
     * next one.
     *
     * The legacy builds this date by pasting strings together - "2026-02-30" -
     * which PHP parses as the 2nd of March. The fine then starts in a month
     * the instalment does not belong to, and nothing on screen says so. The
     * last day of February is what somebody choosing 30 meant.
     */
    public function test_a_fine_day_past_the_end_of_the_month_lands_on_its_last_day(): void
    {
        $this->inTenant(function () {
            $this->seedSettings();
            $member = $this->makeMember();
            $feeSetup = $this->makeFeeSetup();

            $assign = app(FeeAssignService::class)->assign($member->id, $feeSetup, '2026-02', fineDay: 31);

            $this->assertSame('2026-02-28', CarbonImmutable::parse($assign->fine_date)->toDateString());
        });
    }

    /**
     * Left alone, the association's own setting decides - which is what makes
     * the day above an override rather than a question asked every time.
     */
    public function test_without_a_fine_day_the_association_grace_period_applies(): void
    {
        $this->inTenant(function () {
            $this->seedSettings();
            Setting::query()->updateOrCreate(
                ['key' => Setting::FINE_GRACE_DAYS],
                ['value' => 20, 'group' => 'fine']
            );

            $member = $this->makeMember();
            $feeSetup = $this->makeFeeSetup();

            $assign = app(FeeAssignService::class)->assign($member->id, $feeSetup, '2026-01');

            $this->assertSame('2026-01-21', CarbonImmutable::parse($assign->fine_date)->toDateString());
        });
    }

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
