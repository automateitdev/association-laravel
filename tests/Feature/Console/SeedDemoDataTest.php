<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Tenant\FeeAssign;
use App\Models\Tenant\Member;
use Tests\TenantTestCase;

/**
 * The demo seeder earns a test because of how it is used.
 *
 * Nobody reads its output carefully. It is run once, the app is opened, and the
 * screens are judged against whatever appeared. So if a change upstream - the
 * fine engine, the suspension threshold, a fee-assign default - quietly stopped
 * it producing the awkward cases, the demo would still LOOK fine. It would just
 * have become five ordinary members, and the states most likely to be broken in
 * the UI would no longer be on screen to reveal it.
 *
 * These assertions are therefore about the SHAPE of the demo, not the plumbing:
 * the zero fine, the empty state, the pending payment, and a suspension that the
 * fine engine performed rather than the seeder assigned.
 */
class SeedDemoDataTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('tenant:seed-demo', ['slug' => $this->slug()])
            ->assertSuccessful();
    }

    public function test_it_seeds_a_member_with_a_zero_fine_alongside_charged_ones(): void
    {
        $this->inTenant(function () {
            $member = Member::where('mobile', '01711111111')->firstOrFail();

            $fines = FeeAssign::where('member_id', $member->id)
                ->orderBy('period')
                ->pluck('fine_amount', 'period')
                ->all();

            // The zero is the case that matters: an instalment still inside its
            // grace period must render with NO fine line at all. A demo where
            // every row carries a fine cannot show whether that works.
            self::assertSame(
                ['2026-05' => '200.00', '2026-06' => '100.00', '2026-07' => '0.00'],
                $fines,
            );

            // ...and under the threshold, or this member could not sign in.
            self::assertSame(Member::STATUS_ACTIVE, $member->status);
        });
    }

    public function test_it_seeds_a_member_with_nothing_outstanding(): void
    {
        $this->inTenant(function () {
            $member = Member::where('mobile', '01722222222')->firstOrFail();

            $unpaid = FeeAssign::where('member_id', $member->id)
                ->where('status', FeeAssign::STATUS_UNPAID)
                ->count();

            // The empty state is invisible during normal development - every
            // hand-made member owes something - which is exactly why it is the
            // one most often left broken.
            self::assertSame(0, $unpaid);
        });
    }

    public function test_it_seeds_a_payment_still_awaiting_approval(): void
    {
        $this->inTenant(function () {
            $member = Member::where('mobile', '01733333333')->firstOrFail();

            // Requested, not completed: the member must see it pending and must
            // NOT be offered the same instalment to pay a second time.
            self::assertSame(
                1,
                FeeAssign::where('member_id', $member->id)
                    ->where('status', FeeAssign::STATUS_REQUESTED)
                    ->count(),
            );
        });
    }

    public function test_the_suspended_member_was_suspended_by_the_fine_engine(): void
    {
        $this->inTenant(function () {
            $member = Member::where('mobile', '01744444444')->firstOrFail();

            self::assertSame(Member::STATUS_SUSPENDED, $member->status);

            // Suspension has to be a CONSEQUENCE of arrears, not a status the
            // seeder wrote. If the seeder set the column directly, this demo
            // account would keep looking correct even after the fine engine
            // stopped suspending anyone.
            self::assertGreaterThanOrEqual(
                3,
                FeeAssign::where('member_id', $member->id)
                    ->where('status', FeeAssign::STATUS_UNPAID)
                    ->where('fine_amount', '>', 0)
                    ->count(),
            );
        });
    }

    public function test_it_is_idempotent(): void
    {
        // Re-running during development must restore the demo to its documented
        // figures. Accrual recomputes from the fine-date series, so a careless
        // second run would extend the series and change the numbers under a
        // screenshot without anything appearing to fail.
        $this->artisan('tenant:seed-demo', ['slug' => $this->slug()])
            ->assertSuccessful();

        $this->inTenant(function () {
            $member = Member::where('mobile', '01711111111')->firstOrFail();

            self::assertSame(3, FeeAssign::where('member_id', $member->id)->count());
            self::assertSame(
                ['200.00', '100.00', '0.00'],
                FeeAssign::where('member_id', $member->id)
                    ->orderBy('period')
                    ->pluck('fine_amount')
                    ->all(),
            );
        });
    }
}
