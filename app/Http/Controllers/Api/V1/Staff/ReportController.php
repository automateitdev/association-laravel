<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Controllers\Controller;
use App\Models\Tenant\FeeAssign;
use App\Models\Tenant\Member;
use App\Models\Tenant\PaymentInfo;
use App\Models\Tenant\PaymentInfoItem;
use App\Reports\Column;
use App\Reports\ExportsListings;
use App\Reports\Report;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reports (FR-REP-1 … FR-REP-5, FR-REP-7, FR-REP-8).
 *
 * Two rules govern every figure here, and they are the reason the legacy
 * reports are wrong:
 *
 *   FR-REP-3  instalment, fine and total are SEPARATE columns. No column
 *             labelled "savings" or "paid" may silently include a fine.
 *   FR-REP-4  an instalment count is a count of DISTINCT assignments, never a
 *             row count. A duplicated row, a fine-only row and an orphan row
 *             each add one to the legacy count (defect D-7).
 *
 * ONE QUERY PER REPORT, FOUR OUTPUTS.
 * The JSON the screen reads and the CSV, xlsx and PDF a person downloads are
 * all built from the same `…Rows()` method. FR-REP-8 asks for totals on screen
 * AND in every export, which is only worth anything if they agree - and the way
 * to guarantee that is to leave no second implementation that could drift.
 */
class ReportController extends Controller
{
    use ExportsListings;

    // ---------------------------------------------------------------- screens

    /**
     * Memberwise paid: one row per member, cumulative.
     *
     * The legacy version of this report is the one that shows inflated
     * "savings", because it sums payable_amount - which on online payments
     * includes the fine (defect D-1).
     */
    public function memberwisePaid(Request $request): JsonResponse
    {
        $filters = $this->memberwiseFilters($request);
        $result = $this->memberwisePaidRows($filters);

        return response()->json([
            'data' => $result['rows'],
            'meta' => ['members' => count($result['rows'])] + $result['totals'],
        ]);
    }

    /**
     * Outstanding dues (FR-REP-5), for active or suspended members.
     */
    public function dueInfo(Request $request): JsonResponse
    {
        $filters = $this->dueFilters($request);
        $result = $this->dueInfoRows($filters);

        return response()->json([
            'data' => $result['rows'],
            'meta' => [
                'from' => $filters['from'],
                'as_of' => $filters['as_of'],
                'members' => count($result['rows']),
            ] + $result['totals'],
        ]);
    }

    // ---------------------------------------------------------------- exports

    public function exportMemberwisePaid(Request $request): Response
    {
        $filters = $this->memberwiseFilters($request);
        $format = $this->exportFormat($request);
        $result = $this->memberwisePaidRows($filters);

        if (($tooLarge = $this->rejectIfTooLarge($result['rows'])) !== null) {
            return $tooLarge;
        }

        return $this->sendExport(
            new Report(
                title: 'Memberwise paid',
                association: $this->associationName(),
                columns: $this->memberwiseColumns($result['totals']),
                rows: $result['rows'],
                filters: array_filter([
                    'Period' => $this->describePeriod($filters['from'], $filters['to']),
                    'Member' => $filters['q'],
                ]),
                currency: $this->currency(),
            ),
            $format,
        );
    }

    public function exportDueInfo(Request $request): Response
    {
        $filters = $this->dueFilters($request);
        $format = $this->exportFormat($request);
        $result = $this->dueInfoRows($filters);

        if (($tooLarge = $this->rejectIfTooLarge($result['rows'])) !== null) {
            return $tooLarge;
        }

        return $this->sendExport(
            new Report(
                title: 'Outstanding dues',
                association: $this->associationName(),
                columns: $this->dueColumns($result['totals']),
                rows: $result['rows'],
                filters: [
                    /*
                     * Named for what it actually did. "Assigned up to 3 Sep"
                     * and "Assigned 1 Jan to 3 Sep" are different reports, and
                     * a printed sheet headed only "As at" cannot tell you which
                     * of them you are holding.
                     */
                    'Instalments assigned' => $filters['from'] === null
                        ? 'Up to '.$filters['as_of']
                        : $filters['from'].' to '.$filters['as_of'],
                    'Member status' => $filters['member_status'] === null
                        ? 'Active and suspended'
                        : ucfirst($filters['member_status']),
                ] + array_filter(['Member' => $filters['q']]),
                currency: $this->currency(),
            ),
            $format,
        );
    }

    // ------------------------------------------------------------------ rows

    /**
     * @param  array{from: ?string, to: ?string, q: ?string}  $filters
     * @return array{rows: list<array<string, string|int>>, totals: array<string, string|int>}
     */
    private function memberwisePaidRows(array $filters): array
    {
        $rows = DB::table('payment_info_items as pii')
            ->join('payment_infos as pi', 'pi.id', '=', 'pii.payment_info_id')
            ->join('members as m', 'm.id', '=', 'pi.member_id')
            ->where('pi.status', PaymentInfo::STATUS_COMPLETED)
            ->when($filters['from'], fn ($q, $d) => $q->whereDate('pi.payment_date', '>=', $d))
            ->when($filters['to'], fn ($q, $d) => $q->whereDate('pi.payment_date', '<=', $d))
            ->tap(fn ($q) => $this->whereMemberMatches($q, $filters['q']))
            ->leftJoin('associators_infos as info', 'info.member_id', '=', 'm.id')
            ->groupBy('m.id', 'm.name', 'info.membership_no')
            ->select([
                'm.id as member_id',
                'm.name as member_name',
                'info.membership_no',

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
        $out = [];

        foreach ($rows as $row) {
            $instalments = $this->money($row->instalments_paid_amount);
            $fines = $this->money($row->fines_paid_amount);

            $instalmentTotal = bcadd($instalmentTotal, $instalments, 2);
            $fineTotal = bcadd($fineTotal, $fines, 2);
            $countTotal += (int) $row->instalments_paid_count;

            $out[] = [
                'member_id' => (int) $row->member_id,
                'membership_no' => $row->membership_no ?? '',
                'member_name' => $row->member_name,
                'instalments_paid_count' => (int) $row->instalments_paid_count,
                'instalments_paid_amount' => $instalments,
                'fines_paid_amount' => $fines,
                'total_paid' => bcadd($instalments, $fines, 2),
            ];
        }

        return [
            'rows' => $out,
            // FR-REP-8: column totals for every summable column.
            'totals' => [
                'instalments_paid_count' => $countTotal,
                'instalments_paid_amount' => $instalmentTotal,
                'fines_paid_amount' => $fineTotal,
                'total_paid' => bcadd($instalmentTotal, $fineTotal, 2),
            ],
        ];
    }

    /**
     * @param  array{member_status: ?string, from: ?string, as_of: string, q: ?string}  $filters
     * @return array{rows: list<array<string, string|int>>, totals: array<string, string|int>}
     */
    private function dueInfoRows(array $filters): array
    {
        $rows = DB::table('fee_assigns as fa')
            ->join('members as m', 'm.id', '=', 'fa.member_id')
            ->whereIn('fa.status', [FeeAssign::STATUS_UNPAID, FeeAssign::STATUS_REQUESTED])
            ->whereDate('fa.assign_date', '<=', $filters['as_of'])
            ->when($filters['from'], fn ($q, $d) => $q->whereDate('fa.assign_date', '>=', $d))
            ->tap(fn ($q) => $this->whereMemberMatches($q, $filters['q']))
            ->when(
                $filters['member_status'],
                fn ($q, $s) => $q->where('m.status', $s),
                fn ($q) => $q->where('m.status', '!=', Member::STATUS_INACTIVE)
            )
            ->leftJoin('associators_infos as info', 'info.member_id', '=', 'm.id')
            ->groupBy('m.id', 'm.name', 'm.status', 'info.membership_no')
            ->select([
                'm.id as member_id',
                'm.name as member_name',
                'm.status as member_status',
                'info.membership_no',
                DB::raw('COUNT(DISTINCT fa.id) as instalments_due_count'),
                DB::raw('COALESCE(SUM(fa.amount), 0) as instalments_due'),
                DB::raw('COALESCE(SUM(fa.fine_amount), 0) as fines_due'),
            ])
            ->orderBy('m.name')
            ->get();

        $instalmentTotal = '0.00';
        $fineTotal = '0.00';
        $countTotal = 0;
        $out = [];

        foreach ($rows as $row) {
            $instalments = $this->money($row->instalments_due);
            $fines = $this->money($row->fines_due);

            $instalmentTotal = bcadd($instalmentTotal, $instalments, 2);
            $fineTotal = bcadd($fineTotal, $fines, 2);
            $countTotal += (int) $row->instalments_due_count;

            $out[] = [
                'member_id' => (int) $row->member_id,
                'membership_no' => $row->membership_no ?? '',
                'member_name' => $row->member_name,
                'member_status' => $row->member_status,
                'instalments_due_count' => (int) $row->instalments_due_count,
                'instalments_due' => $instalments,
                'fines_due' => $fines,
                'total_due' => bcadd($instalments, $fines, 2),
            ];
        }

        return [
            'rows' => $out,
            'totals' => [
                // The count total was missing here while the paid report had
                // one. FR-REP-8 says every summable column, and a count of
                // instalments is as summable as the money beside it.
                'instalments_due_count' => $countTotal,
                'instalments_due' => $instalmentTotal,
                'fines_due' => $fineTotal,
                'total_due' => bcadd($instalmentTotal, $fineTotal, 2),
            ],
        ];
    }


    /**
     * Narrow a report to one member, by name or membership number.
     *
     * A report of three hundred rows is not read end to end - it is opened to
     * answer a question about somebody, and the number is how the office
     * identifies them. Both fields, because staff who have the number use it
     * and staff who have the person in front of them do not.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     */
    private function whereMemberMatches($query, ?string $term): void
    {
        if ($term === null || $term === '') {
            return;
        }

        $query->where(function ($inner) use ($term) {
            $inner->where('m.name', 'like', "%{$term}%")
                ->orWhereExists(function ($exists) use ($term) {
                    $exists->selectRaw('1')
                        ->from('associators_infos as ai')
                        ->whereColumn('ai.member_id', 'm.id')
                        ->where('ai.membership_no', 'like', "%{$term}%");
                });
        });
    }

    // --------------------------------------------------------------- columns

    /**
     * @param  array<string, string|int>  $totals
     * @return list<Column>
     */
    private function memberwiseColumns(array $totals): array
    {
        return [
            new Column('membership_no', 'No.'),
            new Column('member_name', 'Member'),
            new Column('instalments_paid_count', 'Instalments paid', Column::TYPE_INTEGER, (string) $totals['instalments_paid_count']),
            new Column('instalments_paid_amount', 'Instalments', Column::TYPE_MONEY, (string) $totals['instalments_paid_amount']),
            new Column('fines_paid_amount', 'Fines', Column::TYPE_MONEY, (string) $totals['fines_paid_amount']),
            new Column('total_paid', 'Total paid', Column::TYPE_MONEY, (string) $totals['total_paid']),
        ];
    }

    /**
     * @param  array<string, string|int>  $totals
     * @return list<Column>
     */
    private function dueColumns(array $totals): array
    {
        return [
            new Column('membership_no', 'No.'),
            new Column('member_name', 'Member'),
            new Column('member_status', 'Status'),
            new Column('instalments_due_count', 'Instalments due', Column::TYPE_INTEGER, (string) $totals['instalments_due_count']),
            new Column('instalments_due', 'Instalments', Column::TYPE_MONEY, (string) $totals['instalments_due']),
            new Column('fines_due', 'Fines', Column::TYPE_MONEY, (string) $totals['fines_due']),
            new Column('total_due', 'Total due', Column::TYPE_MONEY, (string) $totals['total_due']),
        ];
    }

    // -------------------------------------------------------------- dashboard

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
                /*
                 * What the approval queue actually holds - so the figure that
                 * sends somebody to that screen matches what they find there.
                 * Payments with a gateway_reference are the bank's to resolve
                 * and are not offered for approval (ADR-0007).
                 */
                'payments_pending_approval' => PaymentInfo::where('status', PaymentInfo::STATUS_PENDING)
                    ->whereNull('gateway_reference')
                    ->count(),
            ],
        ]);
    }

    // ---------------------------------------------------------------- filters

    /** @return array{from: ?string, to: ?string, q: ?string} */
    private function memberwiseFilters(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'q' => ['nullable', 'string', 'max:255'],
        ]);

        return [
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
            'q' => $validated['q'] ?? null,
        ];
    }

    /**
     * @return array{member_status: ?string, from: ?string, as_of: string, q: ?string}
     *
     * `as_of` is the upper bound and keeps its name: it is what the report has
     * always meant, and it is what the JSON, the exports and the tests already
     * call it. `from` is new and OPTIONAL, which is what keeps the two readings
     * of this report compatible:
     *
     *   - with no `from`, it is the snapshot it has always been - everything
     *     still unpaid as at a date;
     *   - with one, it narrows to instalments ASSIGNED in that window and still
     *     unpaid, which is a different and also useful question.
     *
     * Absent both, it is today's snapshot, exactly as before.
     */
    private function dueFilters(Request $request): array
    {
        $validated = $request->validate([
            'member_status' => ['nullable', 'in:active,suspended,inactive'],
            'from' => ['nullable', 'date'],
            'as_of' => ['nullable', 'date', 'after_or_equal:from'],
            'q' => ['nullable', 'string', 'max:255'],
        ]);

        return [
            'member_status' => $validated['member_status'] ?? null,
            'from' => $validated['from'] ?? null,
            'as_of' => $validated['as_of'] ?? now()->toDateString(),
            'q' => $validated['q'] ?? null,
        ];
    }
}
