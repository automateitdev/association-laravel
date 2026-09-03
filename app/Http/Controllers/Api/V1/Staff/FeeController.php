<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Staff;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Tenant\FeeAssign;
use App\Models\Tenant\FeeSetup;
use App\Reports\Column;
use App\Reports\ExportsListings;
use App\Reports\Report;
use App\Services\FeeAssignService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

            // Both ledgers are mandatory and staff-chosen. The legacy system
            // stamps the fine ledger silently from config, which cannot work
            // across associations with different charts of accounts (FR-FEE-2).
            'ledger_id' => ['required', 'integer', 'exists:ledgers,id'],
            'fine_ledger_id' => ['required', 'integer', 'exists:ledgers,id', 'different:ledger_id'],
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
            'ledger_id' => ['sometimes', 'integer', 'exists:ledgers,id'],
            'fine_ledger_id' => ['sometimes', 'integer', 'exists:ledgers,id'],

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
    public function storeAssigns(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'fee_setup_id' => ['required', 'integer', 'exists:fee_setups,id'],
            'member_ids' => ['required', 'array', 'min:1'],
            'member_ids.*' => ['integer', 'exists:members,id'],
            'periods' => ['required', 'array', 'min:1'],
            'periods.*' => ['string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
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

            // Surfaced explicitly. A fee head whose fines land in the wrong
            // account is invisible until someone reads the income statement.
            'fine_ledger' => ['id' => $setup->fine_ledger_id, 'name' => $setup->fineLedger?->name],
        ];
    }
}
