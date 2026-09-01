<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Controllers\Controller;
use App\Models\Tenant\FeeAssign;
use App\Models\Tenant\Member;
use App\Models\Tenant\PaymentInfo;
use App\Models\Tenant\PaymentInfoItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Reports (FR-REP-1 … FR-REP-5).
 *
 * Two rules govern every figure here, and they are the reason the legacy
 * reports are wrong:
 *
 *   FR-REP-3  instalment, fine and total are SEPARATE columns. No column
 *             labelled "savings" or "paid" may silently include a fine.
 *   FR-REP-4  an instalment count is a count of DISTINCT assignments, never a
 *             row count. A duplicated row, a fine-only row and an orphan row
 *             each add one to the legacy count (defect D-7).
 */
class ReportController extends Controller
{
    /**
     * Memberwise paid: one row per member, cumulative.
     *
     * The legacy version of this report is the one that shows inflated
     * "savings", because it sums payable_amount - which on online payments
     * includes the fine (defect D-1).
     */
    public function memberwisePaid(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $rows = DB::table('payment_info_items as pii')
            ->join('payment_infos as pi', 'pi.id', '=', 'pii.payment_info_id')
            ->join('members as m', 'm.id', '=', 'pi.member_id')
            ->where('pi.status', PaymentInfo::STATUS_COMPLETED)
            ->when($validated['from'] ?? null, fn ($q, $d) => $q->whereDate('pi.payment_date', '>=', $d))
            ->when($validated['to'] ?? null, fn ($q, $d) => $q->whereDate('pi.payment_date', '<=', $d))
            ->groupBy('m.id', 'm.name')
            ->select([
                'm.id as member_id',
                'm.name as member_name',

                // DISTINCT assignments, and only lines carrying a real
                // instalment. This is FR-REP-4 expressed in SQL.
                DB::raw('COUNT(DISTINCT CASE WHEN pii.amount > 0 THEN pii.fee_assign_id END) as instalments_paid_count'),

                // Instalments and fines summed apart, never together.
                DB::raw('COALESCE(SUM(pii.amount), 0) as instalments_paid_amount'),
                DB::raw('COALESCE(SUM(pii.fine_amount), 0) as fines_paid_amount'),
            ])
            ->orderBy('m.name')
            ->get();

        $instalmentTotal = '0.00';
        $fineTotal = '0.00';
        $countTotal = 0;

        foreach ($rows as $row) {
            $instalmentTotal = bcadd($instalmentTotal, (string) $row->instalments_paid_amount, 2);
            $fineTotal = bcadd($fineTotal, (string) $row->fines_paid_amount, 2);
            $countTotal += (int) $row->instalments_paid_count;
        }

        return response()->json([
            'data' => $rows->map(fn ($row) => [
                'member_id' => (int) $row->member_id,
                'member_name' => $row->member_name,
                'instalments_paid_count' => (int) $row->instalments_paid_count,
                'instalments_paid_amount' => $this->money($row->instalments_paid_amount),
                'fines_paid_amount' => $this->money($row->fines_paid_amount),
                'total_paid' => bcadd(
                    $this->money($row->instalments_paid_amount),
                    $this->money($row->fines_paid_amount),
                    2
                ),
            ]),

            // FR-REP-8: column totals for every summable column.
            'meta' => [
                'members' => $rows->count(),
                'instalments_paid_count' => $countTotal,
                'instalments_paid_amount' => $instalmentTotal,
                'fines_paid_amount' => $fineTotal,
                'total_paid' => bcadd($instalmentTotal, $fineTotal, 2),
            ],
        ]);
    }

    /**
     * Outstanding dues (FR-REP-5), for active or suspended members.
     */
    public function dueInfo(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'member_status' => ['nullable', 'in:active,suspended,inactive'],
            'as_of' => ['nullable', 'date'],
        ]);

        $asOf = $validated['as_of'] ?? now()->toDateString();

        $rows = DB::table('fee_assigns as fa')
            ->join('members as m', 'm.id', '=', 'fa.member_id')
            ->whereIn('fa.status', [FeeAssign::STATUS_UNPAID, FeeAssign::STATUS_REQUESTED])
            ->whereDate('fa.assign_date', '<=', $asOf)
            ->when(
                $validated['member_status'] ?? null,
                fn ($q, $s) => $q->where('m.status', $s),
                fn ($q) => $q->where('m.status', '!=', Member::STATUS_INACTIVE)
            )
            ->groupBy('m.id', 'm.name', 'm.status')
            ->select([
                'm.id as member_id',
                'm.name as member_name',
                'm.status as member_status',
                DB::raw('COUNT(DISTINCT fa.id) as instalments_due_count'),
                DB::raw('COALESCE(SUM(fa.amount), 0) as instalments_due'),
                DB::raw('COALESCE(SUM(fa.fine_amount), 0) as fines_due'),
            ])
            ->orderBy('m.name')
            ->get();

        $instalmentTotal = '0.00';
        $fineTotal = '0.00';

        foreach ($rows as $row) {
            $instalmentTotal = bcadd($instalmentTotal, (string) $row->instalments_due, 2);
            $fineTotal = bcadd($fineTotal, (string) $row->fines_due, 2);
        }

        return response()->json([
            'data' => $rows->map(fn ($row) => [
                'member_id' => (int) $row->member_id,
                'member_name' => $row->member_name,
                'member_status' => $row->member_status,
                'instalments_due_count' => (int) $row->instalments_due_count,
                'instalments_due' => $this->money($row->instalments_due),
                'fines_due' => $this->money($row->fines_due),
                'total_due' => bcadd($this->money($row->instalments_due), $this->money($row->fines_due), 2),
            ]),
            'meta' => [
                'as_of' => $asOf,
                'members' => $rows->count(),
                'instalments_due' => $instalmentTotal,
                'fines_due' => $fineTotal,
                'total_due' => bcadd($instalmentTotal, $fineTotal, 2),
            ],
        ]);
    }

    /**
     * Headline figures for the staff dashboard.
     */
    public function dashboard(): JsonResponse
    {
        $completedItems = PaymentInfoItem::query()->where('payment_status', PaymentInfo::STATUS_COMPLETED);

        return response()->json([
            'data' => [
                'members' => [
                    'active' => Member::where('status', Member::STATUS_ACTIVE)->count(),
                    'inactive' => Member::where('status', Member::STATUS_INACTIVE)->count(),
                    'suspended' => Member::where('status', Member::STATUS_SUSPENDED)->count(),
                ],
                'collections' => [
                    'instalments' => $this->money((clone $completedItems)->sum('amount')),
                    'fines' => $this->money((clone $completedItems)->sum('fine_amount')),
                ],
                'outstanding' => [
                    'instalments' => $this->money(FeeAssign::outstanding()->sum('amount')),
                    'fines' => $this->money(FeeAssign::outstanding()->sum('fine_amount')),
                ],
                'payments_pending_approval' => PaymentInfo::where('status', PaymentInfo::STATUS_PENDING)->count(),
            ],
        ]);
    }

    /**
     * Money leaves this API as a string with two decimals, never a JSON number
     * - a JSON number is a float, and floats do not reconcile.
     */
    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
