<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled work
|--------------------------------------------------------------------------
|
| Every task here fans out across active associations and isolates failures
| per tenant (FR-TEN-11). None of them may span tenants in one transaction.
|
| The scheduler must run on EXACTLY ONE instance. Two schedulers means two
| accrual runs; accrual is idempotent so that is survivable, but it is not a
| licence to run two.
|
*/

/*
 * Fine accrual. The single most important scheduled task in the system, and
 * the one whose silent failure is most expensive: nobody notices fines NOT
 * being charged, so a broken run surfaces weeks later as a month of wrong dues
 * that must be explained to every affected member.
 *
 * withoutOverlapping guards against a long run colliding with the next night's.
 * The 23-hour expiry is deliberately just under the daily cadence: a stale lock
 * from a killed process must not silently skip the following night.
 */
Schedule::command('fines:accrue')
    ->dailyAt('01:00')
    ->withoutOverlapping(expiresAt: 23 * 60)
    ->onFailure(function () {
        \Log::error('Scheduled fine accrual failed. Members may not be accruing fines.');
    });

/*
 * Release abandoned payment intents. Hourly rather than daily: an instalment
 * stuck in Requested is invisible to the member - it neither shows as due nor
 * can be paid again - so the window should be short.
 */
Schedule::command('payments:expire-intents')
    ->hourly()
    ->withoutOverlapping();

/*
 * Ask the gateway about pending online payments (ADR-0007).
 *
 * The second completion path, and the one that catches what the first misses. A
 * callback that never arrives is INVISIBLE: the member paid, the bank has the
 * money, our row says pending, and nothing anywhere reports a problem. The only
 * way to find out is to ask.
 *
 * Every ten minutes, not hourly like the expiry above, because the two are
 * racing: `expire-intents` releases an abandoned intent after its TTL, and a
 * payment that actually succeeded must be found first. Ten minutes leaves ample
 * margin inside any sane TTL.
 *
 * Cheap when idle - it queries only pending online payments, and most
 * associations have none - so the frequency costs little.
 */
Schedule::command('payments:reconcile')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->onFailure(function () {
        \Log::error('Payment reconciliation failed. Paid members may still show as pending.');
    });
