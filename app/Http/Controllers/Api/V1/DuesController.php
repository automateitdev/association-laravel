<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Tenant\FeeAssign;
use App\Models\Tenant\Member;
use App\Models\Tenant\PaymentInfo;
use App\Models\Tenant\PaymentInfoItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What a member owes, and what they have paid.
 *
 * Three response rules apply to everything here, and they are the whole point
 * of the exercise (bcs-docs/05-api-contract.md section 1.6):
 *
 *   1. instalment_amount and fine_amount are ALWAYS separate fields
 *   2. an instalment count is a count of DISTINCT assignments, not of rows
 *   3. the server computes; the app displays
 */
class DuesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $member = $this->member($request);

        $assigns = FeeAssign::query()
            ->with('feeSetup:id,fee_head')
            ->where('member_id', $member->id)
            ->outstanding()
            ->orderBy('period')
            ->get();

        $data = $assigns->map(fn (FeeAssign $assign) => [
            'fee_assign_id' => $assign->id,
            'fee_head' => $assign->feeSetup->fee_head,
            'period' => $assign->period,

            // Never merged. A single "amount" field would be the legacy bug in
            // a new coat.
            'instalment_amount' => (string) $assign->amount,
            'fine_amount' => (string) $assign->fine_amount,

            // Convenience only, computed here so the app never adds money.
            'total_due' => $assign->totalDue(),

            'status' => $assign->status,
            'overdue_periods' => $this->overduePeriods($assign),
        ]);

        $instalmentTotal = $assigns->reduce(
            fn (string $carry, FeeAssign $a) => bcadd($carry, (string) $a->amount, 2),
            '0.00'
        );
        $fineTotal = $assigns->reduce(
            fn (string $carry, FeeAssign $a) => bcadd($carry, (string) $a->fine_amount, 2),
            '0.00'
        );

        return response()->json([
            'data' => $data,
            'meta' => [
                'instalment_total' => $instalmentTotal,
                'fine_total' => $fineTotal,
                'grand_total' => bcadd($instalmentTotal, $fineTotal, 2),
            ],
        ]);
    }

    /**
     * Four numbers, never one.
     *
     * The legacy reports collapse these into a single "savings" figure that
     * silently includes fines and counts duplicate rows as instalments.
     */
    public function summary(Request $request): JsonResponse
    {
        $member = $this->member($request);

        $completedItems = PaymentInfoItem::query()
            ->where('payment_status', PaymentInfo::STATUS_COMPLETED)
            ->whereHas('feeAssign', fn ($q) => $q->where('member_id', $member->id));

        // FR-MON-4: DISTINCT assignments, with amount > 0. Counting rows is how
        // the legacy instalment counts became an upper bound rather than a
        // count (defect D-7).
        $instalmentsPaidCount = (clone $completedItems)
            ->where('amount', '>', 0)
            ->distinct('fee_assign_id')
            ->count('fee_assign_id');

        $instalmentsPaidAmount = (clone $completedItems)->sum('amount');
        $finesPaidAmount = (clone $completedItems)->sum('fine_amount');

        $member->loadMissing('associatorInfo');

        return response()->json([
            'data' => [
                'instalments_paid_count' => $instalmentsPaidCount,
                'instalments_paid_amount' => number_format((float) $instalmentsPaidAmount, 2, '.', ''),
                'fines_paid_amount' => number_format((float) $finesPaidAmount, 2, '.', ''),
                'shares' => (int) ($member->associatorInfo?->num_or_shares ?? 0),
            ],
        ]);
    }

    /**
     * How many fine periods have elapsed against this assignment.
     */
    private function overduePeriods(FeeAssign $assign): int
    {
        return $assign->fineDates()
            ->where('fine_date', '<=', now()->toDateString())
            ->count();
    }

    /**
     * The member is ALWAYS the authenticated one. Never a request field.
     *
     * This is defect D-5's fix at the entry point: the legacy controller takes
     * member_id from the request body and validates only that it exists.
     */
    private function member(Request $request): Member
    {
        $account = $request->user();

        abort_unless($account instanceof Member, 403);

        return $account;
    }
}
