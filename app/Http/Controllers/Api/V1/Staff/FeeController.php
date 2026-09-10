<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Staff;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Tenant\AuditLog;
use App\Models\Tenant\FeeAssign;
use App\Models\Tenant\FeeSetup;
use App\Reports\Column;
use App\Reports\ExportsListings;
use App\Reports\Report;
use App\Services\FeeAssignService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fee heads and assignment (FR-FEE-1 … FR-FEE-9).
 */
class FeeController extends Controller
{
    use ExportsListings;

    public function __construct(private readonly FeeAssignService $assigner) {}

    public function indexSetups(): JsonResponse
    {
        $setups = FeeSetup::query()->with(['ledger:id,name', 'fineLedger:id,name'])->orderBy('fee_head')->get();

        return response()->json([
            'data' => $setups->map(fn (FeeSetup $s) => $this->shapeSetup($s)),
        ]);
    }

    /**
     * The fee heads as a file (FR-REP-7).
     *
     * BOTH LEDGERS ARE COLUMNS, for the same reason the screen shows them as a
     * pair: a fee head whose fines post to the wrong account is invisible until
     * someone reads the income statement months later, and by then the postings
     * are history. A printed setup sheet is how that gets checked before it
     * matters.
     */
    public function exportSetups(Request $request): Response
    {
        $format = $this->exportFormat($request);

        $rows = FeeSetup::query()
            ->with(['ledger:id,name', 'fineLedger:id,name'])
            ->orderBy('fee_head')
            ->get()
            ->map(fn (FeeSetup $s) => [
                'fee_head' => $s->fee_head,
                'amount' => $this->money($s->amount),
                'monthly' => $s->monthly ? 'Monthly' : 'One-off',
                'is_share' => $s->is_share ? 'Yes' : 'No',
                'ledger' => $s->ledger?->name ?? '',
                'fine_ledger' => $s->fineLedger?->name ?? '',
                'is_active' => $s->is_active ? 'In use' : 'Deactivated',
            ])
            ->all();

        if (($tooLarge = $this->rejectIfTooLarge($rows)) !== null) {
            return $tooLarge;
        }

        return $this->sendExport(
            new Report(
                title: 'Fee heads',
                association: $this->associationName(),
                columns: [
                    new Column('fee_head', 'Fee head'),
                    new Column('amount', 'Amount', Column::TYPE_MONEY),
                    new Column('monthly', 'Frequency'),
                    new Column('is_share', 'Buys shares'),
                    new Column('ledger', 'Instalment account'),
                    new Column('fine_ledger', 'Fine account'),
                    new Column('is_active', 'Status'),
                ],
                rows: $rows,
                /*
                 * No total on the amount column, deliberately.
                 *
                 * Summing the amounts of every fee head produces a number that
                 * looks like money and means nothing - nobody is charged the
                 * sum of all fee heads. FR-REP-8 asks for totals on summable
                 * columns; this one is not summable in any useful sense.
                 */
                filters: [],
                currency: $this->currency(),
            ),
            $format,
        );
    }

    public function storeSetup(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'fee_head' => ['required', 'string', 'max:255'],
            'monthly' => ['required', 'boolean'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'is_share' => ['sometimes', 'boolean'],

            /*
             * NULL means the association's rate, 0 means this head never
             * fines. Absent is null, so every existing fee head keeps behaving
             * exactly as it did.
             */
            'fine_rate' => ['sometimes', 'nullable', 'numeric', 'min:0'],

            // The income ledger is always required and staff-chosen. The
            // legacy stamps it silently from config, which cannot work across
            // associations with different charts of accounts (FR-FEE-2).
            'ledger_id' => ['required', 'integer', 'exists:ledgers,id'],

            /*
             * The FINE ledger is required only when this head can actually
             * fine. Demanding it for a fee head set to never fine is asking
             * where fine income should post for income that cannot exist.
             */
            'fine_ledger_id' => [
                Rule::requiredIf(fn () => (string) $request->input('fine_rate', '') !== '0'),
                'nullable',
                'integer',
                'exists:ledgers,id',
                'different:ledger_id',
            ],
        ]);

        $setup = FeeSetup::create($validated + ['is_active' => true]);

        return response()->json(['data' => $this->shapeSetup($setup->fresh(['ledger', 'fineLedger']))], 201);
    }

    public function updateSetup(Request $request, int $feeSetup): JsonResponse
    {
        $setup = FeeSetup::find($feeSetup) ?? throw ApiException::notFound('Fee head');

        $validated = $request->validate([
            'fee_head' => ['sometimes', 'string', 'max:255'],
            'amount' => ['sometimes', 'numeric', 'gt:0'],
            'fine_rate' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'ledger_id' => ['sometimes', 'integer', 'exists:ledgers,id'],
            'fine_ledger_id' => ['sometimes', 'nullable', 'integer', 'exists:ledgers,id'],

            // Deactivate rather than delete once assignments exist (FR-FEE-3).
            'is_active' => ['sometimes', 'boolean'],
        ]);

        // FR-FEE-4: changing the price must not rewrite history. Existing
        // assignments carry their own copied amount and are never touched here.
        $setup->update($validated);

        return response()->json(['data' => $this->shapeSetup($setup->fresh(['ledger', 'fineLedger']))]);
    }

    public function indexAssigns(Request $request): JsonResponse
    {
        $assigns = FeeAssign::query()
            ->with(['feeSetup:id,fee_head', 'member:id,name'])
            ->when($request->query('member_id'), fn ($q, $id) => $q->where('member_id', $id))
            ->when($request->query('period'), fn ($q, $p) => $q->where('period', $p))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))

            /*
             * Only instalments carrying a fine. The fine adjustment screen
             * filtered these out client-side at first, which made the count
             * line lie - "Showing 1-25 of 121" above nine visible rows, because
             * the total came from the server and the rows did not. A filter the
             * server does not know about cannot be paginated honestly.
             */
            ->when(
                filter_var($request->query('fined', 'false'), FILTER_VALIDATE_BOOL),
                fn ($q) => $q->where('fine_amount', '>', 0)
            )
            ->orderByDesc('period')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        $instalmentTotal = '0.00';
        $fineTotal = '0.00';

        foreach ($assigns as $assign) {
            $instalmentTotal = bcadd($instalmentTotal, (string) $assign->amount, 2);
            $fineTotal = bcadd($fineTotal, (string) $assign->fine_amount, 2);
        }

        return response()->json([
            'data' => $assigns->getCollection()->map(fn (FeeAssign $a) => [
                'fee_assign_id' => $a->id,
                'member_id' => $a->member_id,
                'member_name' => $a->member->name,
                'fee_head' => $a->feeSetup->fee_head,
                'period' => $a->period,

                // Separate, always.
                'instalment_amount' => (string) $a->amount,
                'fine_amount' => (string) $a->fine_amount,
                'total_due' => $a->totalDue(),

                'status' => $a->status,
            ]),
            'meta' => [
                'current_page' => $assigns->currentPage(),
                'total' => $assigns->total(),
                'last_page' => $assigns->lastPage(),

                // Page totals, not result-set totals - stated so nobody reads
                // them as the association's outstanding balance.
                'page_instalment_total' => $instalmentTotal,
                'page_fine_total' => $fineTotal,
            ],
        ]);
    }

    /**
     * Bulk assignment (FR-FEE-5, FR-FEE-8).
     *
     * Returns a summary rather than a bare success. Staff need to know that 40
     * of 200 were skipped as already assigned; silence about it reads as "all
     * 200 were created".
     */
    /**
     * What these members already hold, and how much of a proposal duplicates it.
     *
     * WHY IT ANSWERS WITHOUT BEING ASKED A FEE HEAD. The legacy fee-assign
     * screen prints, against every member, each fee head they hold and every
     * date it was assigned on - before anything is chosen, because it is a
     * fact about the member rather than about the form. The first version of
     * this endpoint required a fee head and periods, so the column vanished
     * until somebody filled the form in, which is precisely when they were
     * looking for it.
     *
     * So both questions, in one round trip for a page of members:
     *
     *   heads    - everything they hold, grouped by fee head, always.
     *   matching - how many of the chosen periods of the chosen head they
     *              already have, when a head and periods were given.
     *
     * TWO GROUPED QUERIES AT MOST, whatever the size of the page.
     */
    public function assignCoverage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'member_ids' => ['required', 'string'],
            'fee_setup_id' => ['sometimes', 'nullable', 'integer', 'exists:fee_setups,id'],
            'periods' => ['sometimes', 'nullable', 'string'],
        ]);

        $memberIds = array_slice(array_filter(array_map(
            'intval',
            explode(',', $validated['member_ids'])
        )), 0, 200);

        if ($memberIds === []) {
            return response()->json(['data' => (object) [], 'meta' => ['periods' => 0]]);
        }

        $periods = array_slice(array_filter(array_map(
            'trim',
            explode(',', (string) ($validated['periods'] ?? ''))
        )), 0, 120);

        /*
         * Everything they hold, by fee head. The period range is carried
         * because "15 instalments" and "15 instalments, 2024-10 to 2026-01"
         * are different amounts of help, and the second costs nothing extra
         * from a query already grouping.
         */
        /*
         * THE PERIODS THEMSELVES, not just how many.
         *
         * A count answers "have they got some of this" and nothing else. The
         * legacy column names every month - "October 1 2024, November 1 2024,
         * December 1 2024" - and the naming is the point: instalments have
         * gaps. A member holding January, March and July is not the same as
         * one holding January to March, and a count or a date range reports
         * them identically.
         *
         * GROUP_CONCAT rather than a second query per member: one row per
         * member and fee head, with the periods along for the ride. Bounded to
         * 8kb, which is a thousand periods - eighty years of monthly dues -
         * and MySQL truncates rather than failing past it.
         */
        $held = FeeAssign::query()
            ->join('fee_setups', 'fee_setups.id', '=', 'fee_assigns.fee_setup_id')
            ->whereIn('fee_assigns.member_id', $memberIds)
            ->groupBy('fee_assigns.member_id', 'fee_setups.id', 'fee_setups.fee_head')
            ->selectRaw(
                'fee_assigns.member_id, fee_setups.fee_head, COUNT(*) as held, '
                .'GROUP_CONCAT(fee_assigns.period ORDER BY fee_assigns.period) as periods'
            )
            ->orderByDesc('held')
            ->get()
            ->groupBy('member_id');

        $matching = collect();

        if ($periods !== [] && ! empty($validated['fee_setup_id'])) {
            $matching = FeeAssign::query()
                ->where('fee_setup_id', $validated['fee_setup_id'])
                ->whereIn('member_id', $memberIds)
                ->whereIn('period', $periods)
                ->groupBy('member_id')
                ->selectRaw('member_id, COUNT(*) as matched')
                ->pluck('matched', 'member_id');
        }

        $data = [];

        foreach ($memberIds as $id) {
            $rows = $held->get($id);

            // A member holding nothing is omitted entirely, so a page of
            // members with nothing costs an empty object.
            if ($rows === null && ! $matching->has($id)) {
                continue;
            }

            $data[(string) $id] = [
                'total' => (int) ($rows?->sum('held') ?? 0),
                'heads' => $rows === null ? [] : $rows->map(fn ($r) => [
                    'fee_head' => $r->fee_head,
                    'count' => (int) $r->held,

                    // Oldest first, so a list reads the way a ledger does.
                    'periods' => array_values(array_filter(explode(',', (string) $r->periods))),
                ])->values()->all(),

                // Null when nothing was proposed - which is not the same as
                // zero, and the column says different things for each.
                'matching' => $periods === [] || empty($validated['fee_setup_id'])
                    ? null
                    : (int) ($matching[$id] ?? 0),
            ];
        }

        return response()->json([
            'data' => (object) $data,
            'meta' => ['periods' => count($periods)],
        ]);
    }

    public function storeAssigns(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'fee_setup_id' => ['required', 'integer', 'exists:fee_setups,id'],
            'member_ids' => ['required', 'array', 'min:1'],
            'member_ids.*' => ['integer', 'exists:members,id'],
            'periods' => ['required', 'array', 'min:1'],
            'periods.*' => ['string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],

            /*
             * Optional, and absent means "use the association's grace period".
             * The legacy screen asked for this on every assignment; here the
             * setting answers it unless somebody says otherwise. 31 is allowed
             * and clamped to the length of each month - see fineDateFor.
             */
            'fine_day' => ['sometimes', 'nullable', 'integer', 'between:1,31'],
        ]);

        $setup = FeeSetup::findOrFail($validated['fee_setup_id']);

        if (! $setup->is_active) {
            throw ApiException::conflict(
                'FEE_HEAD_INACTIVE',
                'That fee head is deactivated and cannot be assigned.'
            );
        }

        $summary = $this->assigner->bulkAssign(
            $validated['member_ids'],
            $setup,
            $validated['periods'],
            $validated['fine_day'] ?? null,
        );

        return response()->json(['data' => $summary], 201);
    }

    private function shapeSetup(FeeSetup $setup): array
    {
        return [
            'id' => $setup->id,
            'fee_head' => $setup->fee_head,
            'monthly' => $setup->monthly,
            'amount' => (string) $setup->amount,
            'is_share' => $setup->is_share,
            'is_active' => $setup->is_active,
            'ledger' => ['id' => $setup->ledger_id, 'name' => $setup->ledger?->name],

            /*
             * Null and zero are different answers and the client has to be
             * able to tell them apart: null is "whatever the association
             * charges", zero is "this head never fines".
             */
            'fine_rate' => $setup->fine_rate === null ? null : (string) $setup->fine_rate,

            // Surfaced explicitly. A fee head whose fines land in the wrong
            // account is invisible until someone reads the income statement.
            'fine_ledger' => ['id' => $setup->fine_ledger_id, 'name' => $setup->fineLedger?->name],
        ];
    }

    /**
     * Change the fine on an outstanding instalment (FR-FEE-9).
     *
     * WHY THIS REFUSES TO TOUCH A PAID INSTALMENT
     * -------------------------------------------
     * The legacy screen adjusted a fine wherever it found one - including on
     * instalments already settled - by rewriting `fine_amount` on the
     * assignment AND on the payment items behind it, then recomputing the
     * payment header. That edits the record of money that has already changed
     * hands. It does not refund anybody; it just makes the invoice, the ledger
     * and the member's receipt disagree about what was paid, and the audit
     * (FR-REP-6) would flag every one of them as TOTAL_MISMATCH the same day.
     *
     * A fine that was wrongly charged AND already collected is a refund, not an
     * edit. There is no refund flow yet, so this endpoint refuses that case
     * loudly rather than quietly corrupting the books, and says why.
     *
     * Waiving a fine before it is paid is the operation staff actually reach
     * for, and that is what this does.
     */
    public function adjustFine(Request $request, int $feeAssign): JsonResponse
    {
        $validated = $request->validate([
            'fine_amount' => ['required', 'numeric', 'min:0'],

            /*
             * Required, not optional. A fine is a member-visible figure and
             * this is the one place staff can change it by hand - "who reduced
             * my fine, and why" has to have an answer that is not "somebody,
             * at some point".
             */
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $assign = FeeAssign::with('feeSetup:id,fee_head')->findOrFail($feeAssign);

        if ($assign->status === FeeAssign::STATUS_PAID) {
            throw new ApiException(
                'FINE_ALREADY_PAID',
                'This instalment has been paid, so its fine cannot be edited. Changing it now '
                    . 'would alter the record of money already received without refunding it.',
                422,
            );
        }

        $before = (string) $assign->fine_amount;
        $after = number_format((float) $validated['fine_amount'], 2, '.', '');

        if (bccomp($before, $after, 2) === 0) {
            throw new ApiException(
                'FINE_UNCHANGED',
                'That is the fine already recorded, so there is nothing to change.',
                422,
            );
        }

        DB::transaction(function () use ($assign, $after, $before, $validated, $request) {
            $assign->update(['fine_amount' => $after]);

            AuditLog::create([
                'actor_type' => $request->user()::class,
                'actor_id' => $request->user()->id,
                'subject_type' => FeeAssign::class,
                'subject_id' => $assign->id,
                'action' => 'fee-assign.fine-adjusted',
                'before' => ['fine_amount' => $before],
                'after' => ['fine_amount' => $after],
                'reason' => $validated['reason'],
                'ip' => $request->ip(),
            ]);
        });

        $assign->refresh();

        return response()->json([
            'data' => [
                'fee_assign_id' => $assign->id,
                'member_id' => $assign->member_id,
                'fee_head' => $assign->feeSetup->fee_head,
                'period' => $assign->period,
                'instalment_amount' => (string) $assign->amount,
                'fine_amount' => (string) $assign->fine_amount,
                'total_due' => $assign->totalDue(),
                'status' => $assign->status,
            ],
            'meta' => [
                'previous_fine_amount' => $before,
            ],
        ]);
    }
}
