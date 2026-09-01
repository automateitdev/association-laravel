<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\FeeAssign;
use App\Models\Tenant\FeeSetup;
use App\Models\Tenant\FineDate;
use App\Models\Tenant\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Assigning fees to members.
 *
 * The rule this service exists to hold: a member owes AT MOST ONE instalment per
 * fee head per calendar month (I-3). The database enforces it via
 * UNIQUE(member_id, fee_setup_id, period); this service makes the violation a
 * skip rather than an error, so a double-clicked Save or a re-run of a bulk
 * assign is harmless.
 *
 * The legacy equivalent guards on the exact assign_date, so 2026-01-01 and
 * 2026-01-15 are two payable instalments for January (defect D-2).
 */
class FeeAssignService
{
    /**
     * Assign one fee head to one member for one period.
     *
     * Idempotent. Returns the existing assignment if there already is one -
     * never a second row, never an exception.
     *
     * @param  string  $period  YYYY-MM
     */
    public function assign(int $memberId, FeeSetup $feeSetup, string $period): FeeAssign
    {
        $this->guardPeriod($period);

        $existing = FeeAssign::query()
            ->where('member_id', $memberId)
            ->where('fee_setup_id', $feeSetup->id)
            ->where('period', $period)
            ->first();

        if ($existing) {
            return $existing;
        }

        $assignDate = CarbonImmutable::createFromFormat('Y-m-d', $period.'-01')->startOfDay();
        $fineDate = $assignDate->addDays($this->graceDays());

        try {
            return DB::transaction(function () use ($memberId, $feeSetup, $period, $assignDate, $fineDate) {
                $assign = FeeAssign::create([
                    'member_id' => $memberId,
                    'fee_setup_id' => $feeSetup->id,
                    'period' => $period,

                    // Always the first of the month. The period column is what
                    // uniqueness keys on, but keeping assign_date normalised too
                    // means reports that group by date agree with it.
                    'assign_date' => $assignDate->toDateString(),
                    'fine_date' => $fineDate->toDateString(),

                    // Copied from the fee head, NOT referenced. A later price
                    // change must not rewrite history (FR-FEE-4).
                    'amount' => $feeSetup->amount,

                    'fine_amount' => '0.00',
                    'status' => FeeAssign::STATUS_UNPAID,
                ]);

                // The first tick of the fine clock (FR-FEE-7).
                FineDate::create([
                    'fee_assign_id' => $assign->id,
                    'fine_date' => $fineDate->toDateString(),
                    'status' => FineDate::STATUS_INCOMPLETE,
                ]);

                return $assign;
            });
        } catch (QueryException $e) {
            // Lost a race against a concurrent assign. The unique index did its
            // job; return the row the winner created rather than failing.
            if ($this->isUniqueViolation($e)) {
                return FeeAssign::query()
                    ->where('member_id', $memberId)
                    ->where('fee_setup_id', $feeSetup->id)
                    ->where('period', $period)
                    ->firstOrFail();
            }

            throw $e;
        }
    }

    /**
     * Assign one fee head to many members across many periods.
     *
     * Returns a summary rather than a bare success (FR-FEE-8): staff need to
     * know that 40 of 200 were skipped as already assigned, and silence about it
     * reads as "all 200 were created".
     *
     * @param  array<int>  $memberIds
     * @param  array<string>  $periods  YYYY-MM
     * @return array{created: int, skipped_duplicate: int, failed: array<string>}
     */
    public function bulkAssign(array $memberIds, FeeSetup $feeSetup, array $periods): array
    {
        $created = 0;
        $skipped = 0;
        $failed = [];

        foreach ($memberIds as $memberId) {
            foreach ($periods as $period) {
                try {
                    $before = FeeAssign::query()
                        ->where('member_id', $memberId)
                        ->where('fee_setup_id', $feeSetup->id)
                        ->where('period', $period)
                        ->exists();

                    $this->assign($memberId, $feeSetup, $period);

                    $before ? $skipped++ : $created++;
                } catch (\Throwable $e) {
                    // One bad member must not abandon the other 199.
                    $failed[] = "member {$memberId}, period {$period}: {$e->getMessage()}";
                }
            }
        }

        return [
            'created' => $created,
            'skipped_duplicate' => $skipped,
            'failed' => $failed,
        ];
    }

    private function graceDays(): int
    {
        return (int) Setting::get(Setting::FINE_GRACE_DAYS, 0);
    }

    private function guardPeriod(string $period): void
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            throw new \InvalidArgumentException(
                "Period must be YYYY-MM, got [{$period}]."
            );
        }
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return $e->getCode() === '23000';
    }
}
