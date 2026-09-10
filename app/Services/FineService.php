<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\AuditLog;
use App\Models\Tenant\FeeAssign;
use App\Models\Tenant\FineDate;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Fine accrual.
 *
 * Two properties matter more than anything else here.
 *
 * IDEMPOTENT (FR-FINE-3). The fine is RECOMPUTED from the fine-date series -
 * elapsed dates x rate - never incremented. Running the job twice in one day
 * changes nothing. The legacy command increments, which is why a re-run there is
 * dangerous.
 *
 * BATCHED PER MEMBER (FR-TEN-12). The legacy command wraps the entire run in one
 * transaction, which is why it has to swallow duplicate-key errors: an uncaught
 * one would roll back every fine for the night. One transaction per member
 * removes the need for that tolerance and contains any single bad row.
 */
class FineService
{
    /**
     * Accrue fines for every member with outstanding assignments.
     *
     * @return array{examined: int, fined: int, suspended: int, failed: array<string>}
     */
    public function accrueAll(?CarbonImmutable $asOf = null): array
    {
        $asOf ??= CarbonImmutable::now()->startOfDay();

        $summary = ['examined' => 0, 'fined' => 0, 'suspended' => 0, 'failed' => []];

        $memberIds = FeeAssign::query()
            ->outstanding()
            ->distinct()
            ->pluck('member_id');

        foreach ($memberIds as $memberId) {
            try {
                $result = $this->accrueForMember((int) $memberId, $asOf);

                $summary['examined'] += $result['examined'];
                $summary['fined'] += $result['fined'];
                $summary['suspended'] += $result['suspended'] ? 1 : 0;
            } catch (\Throwable $e) {
                // Contained: one malformed member does not stop the night's run.
                $summary['failed'][] = "member {$memberId}: {$e->getMessage()}";
            }
        }

        return $summary;
    }

    /**
     * @return array{examined: int, fined: int, suspended: bool}
     */
    public function accrueForMember(int $memberId, ?CarbonImmutable $asOf = null): array
    {
        $asOf ??= CarbonImmutable::now()->startOfDay();

        return DB::transaction(function () use ($memberId, $asOf) {
            $assigns = FeeAssign::query()
                ->where('member_id', $memberId)
                ->outstanding()
                ->lockForUpdate()
                ->get()
                // The rate can differ per fee head now, so the head has to be
                // in hand. Loaded after the lock rather than as part of it:
                // eager loading inside lockForUpdate would lock fee_setups too.
                ->load('feeSetup:id,fine_rate');

            $examined = 0;
            $fined = 0;
            $worstOverdue = 0;

            foreach ($assigns as $assign) {
                $examined++;

                $overdue = $this->recomputeFine($assign, $asOf);

                if ($overdue > 0) {
                    $fined++;
                }

                $worstOverdue = max($worstOverdue, $overdue);
            }

            $suspended = $this->suspendIfPastThreshold($memberId, $worstOverdue);

            return ['examined' => $examined, 'fined' => $fined, 'suspended' => $suspended];
        });
    }

    /**
     * Extend the fine-date series to today, mark elapsed dates complete, and set
     * the fine to (elapsed x rate).
     *
     * @return int the number of overdue periods
     */
    private function recomputeFine(FeeAssign $assign, CarbonImmutable $asOf): int
    {
        $this->extendSeries($assign, $asOf);

        // Elapsed dates are the overdue periods. Recomputed every run from the
        // series, which is what makes a second run of the day a no-op.
        FineDate::query()
            ->where('fee_assign_id', $assign->id)
            ->where('fine_date', '<=', $asOf->toDateString())
            ->update(['status' => FineDate::STATUS_COMPLETE]);

        $overdue = FineDate::query()
            ->where('fee_assign_id', $assign->id)
            ->where('fine_date', '<=', $asOf->toDateString())
            ->count();

        $fine = bcmul($this->rateFor($assign), (string) $overdue, 2);

        // Only write when it actually moved, so an unchanged assignment does not
        // get a new updated_at every night.
        if (bccomp((string) $assign->fine_amount, $fine, 2) !== 0) {
            $assign->update(['fine_amount' => $fine]);
        }

        return $overdue;
    }

    /**
     * Walk the series forward one month at a time until the last date is in the
     * future.
     */
    private function extendSeries(FeeAssign $assign, CarbonImmutable $asOf): void
    {
        $last = FineDate::query()
            ->where('fee_assign_id', $assign->id)
            ->orderByDesc('fine_date')
            ->value('fine_date');

        $cursor = $last
            ? CarbonImmutable::parse($last)
            : CarbonImmutable::parse($assign->fine_date);

        // A guard against a runaway loop on a corrupt date. 600 months is fifty
        // years; anything beyond that is bad data, not a long-overdue member.
        $guard = 0;

        while ($cursor->lessThanOrEqualTo($asOf) && $guard++ < 600) {
            $cursor = $cursor->addMonthNoOverflow();

            FineDate::query()->firstOrCreate(
                ['fee_assign_id' => $assign->id, 'fine_date' => $cursor->toDateString()],
                ['status' => FineDate::STATUS_INCOMPLETE]
            );
        }
    }

    /**
     * Suspend the member once they pass the association's threshold (FR-FINE-2).
     */
    private function suspendIfPastThreshold(int $memberId, int $worstOverdue): bool
    {
        $threshold = $this->suspensionThreshold();

        /*
         * A threshold below one means NEVER SUSPEND, which is what anybody
         * typing zero intends. Without this the comparison reads
         * `0 < 0` - false - and the member is suspended for having no overdue
         * periods at all: measured, an active member with an instalment whose
         * fine had not started went straight to suspended. The settings
         * endpoint refuses anything below 1, so this is only reachable by
         * writing the row directly - a seeder, a migration, a console. That is
         * exactly when nobody is watching.
         */
        if ($threshold < 1 || $worstOverdue < $threshold) {
            return false;
        }

        $member = Member::find($memberId);

        if (! $member || $member->status === Member::STATUS_SUSPENDED) {
            return false;
        }

        $before = $member->status;
        $member->update(['status' => Member::STATUS_SUSPENDED]);

        AuditLog::create([
            'subject_type' => Member::class,
            'subject_id' => $member->id,
            'action' => 'member.suspended.overdue',
            'before' => ['status' => $before],
            'after' => ['status' => Member::STATUS_SUSPENDED],
            'reason' => "{$worstOverdue} overdue periods, threshold {$this->suspensionThreshold()}",
        ]);

        return true;
    }

    /**
     * What one overdue period costs on THIS instalment.
     *
     * The fee head's own rate when it has one, the association's otherwise.
     * NULL is not zero here and the difference is the whole point: null means
     * "whatever the association charges", zero means "this fee head does not
     * fine". A head marked zero stays at zero however the association's rate
     * moves, which is what somebody setting up an admission fee or a voluntary
     * contribution is asking for.
     */
    private function rateFor(FeeAssign $assign): string
    {
        $own = $assign->feeSetup?->fine_rate;

        return $own === null ? $this->rate() : (string) $own;
    }

    private function rate(): string
    {
        return (string) Setting::get(Setting::FINE_RATE, '100.00');
    }

    private function suspensionThreshold(): int
    {
        return (int) Setting::get(Setting::SUSPENSION_THRESHOLD, 3);
    }
}
