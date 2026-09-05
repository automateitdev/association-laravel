<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Staff;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Tenant\AuditLog;
use App\Models\Tenant\Voucher;
use App\Services\VoucherService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Manual accounting documents (FR-ACC-4).
 *
 * Payments post themselves. A voucher is for everything else an association
 * does with money, and it is the one place a person rather than a payment
 * decides what the ledger says - which is why drafting and approving are
 * separate permissions, and why an approved one can only be reversed.
 */
class VoucherController extends Controller
{
    /**
     * Everything a shaped voucher reads from.
     *
     * Named once because `index` loads twenty-five of them: leaving any of
     * these to lazy-load turns one list into a query per row per relation, and
     * the reversal pair is the easiest of them to forget.
     *
     * @var list<string>
     */
    private const WITH = [
        'lines.ledger:id,name',
        'creator:id,name',
        'approver:id,name',
        'reverses:id,voucher_no',
        'reversal:id,reverses_id,voucher_no',
    ];

    public function __construct(private readonly VoucherService $vouchers) {}

    public function index(Request $request): JsonResponse
    {
        $vouchers = Voucher::query()
            ->with(self::WITH)
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('type'), fn ($q, $t) => $q->where('type', $t))
            ->when($request->query('from'), fn ($q, $d) => $q->whereDate('voucher_date', '>=', $d))
            ->when($request->query('to'), fn ($q, $d) => $q->whereDate('voucher_date', '<=', $d))
            ->latest('voucher_date')
            ->latest('id')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return response()->json([
            'data' => $vouchers->getCollection()->map(fn (Voucher $v) => $this->shape($v)),
            'meta' => [
                'current_page' => $vouchers->currentPage(),
                'total' => $vouchers->total(),
                'last_page' => $vouchers->lastPage(),
                'per_page' => $vouchers->perPage(),
                'drafts' => Voucher::where('status', Voucher::STATUS_DRAFT)->count(),
            ],
        ]);
    }

    public function show(int $voucher): JsonResponse
    {
        return response()->json([
            'data' => $this->shape(
                Voucher::with(self::WITH)
                    ->findOrFail($voucher)
            ),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validated($request);

        $voucher = $this->guard(fn () => $this->vouchers->draft(
            $validated,
            $validated['lines'],
            $request->user()->id,
        ));

        return response()->json(['data' => $this->shape($voucher->fresh(self::WITH))], 201);
    }

    public function update(Request $request, int $voucher): JsonResponse
    {
        $record = Voucher::findOrFail($voucher);
        $validated = $this->validated($request);

        $updated = $this->guard(fn () => $this->vouchers->update($record, $validated, $validated['lines']));

        return response()->json(['data' => $this->shape($updated->fresh(self::WITH))]);
    }

    /**
     * Approve or reject.
     *
     * One endpoint, because they are the same decision with two answers, and
     * both are the moment the voucher stops being editable.
     */
    public function decide(Request $request, int $voucher): JsonResponse
    {
        $record = Voucher::with('lines')->findOrFail($voucher);

        $validated = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
        ]);

        $result = $this->guard(fn () => $validated['decision'] === 'approve'
            ? $this->vouchers->approve($record, $request->user()->id)
            : $this->vouchers->reject($record, $request->user()->id));

        $this->audit(
            $request,
            $result,
            $validated['decision'] === 'approve' ? 'voucher.approved' : 'voucher.rejected',
            /*
             * Recorded when the same person wrote and approved it. Not
             * forbidden - a two-person association could post nothing if it
             * were - but an approval performed on one's own work is not the
             * control it appears to be, and the log should not be silent about
             * that.
             */
            ['self_approved' => $result->selfApproved()],
        );

        return response()->json(['data' => $this->shape($result->fresh(self::WITH))]);
    }

    /** Undo an approved voucher by posting its mirror image (FR-ACC-6). */
    public function reverse(Request $request, int $voucher): JsonResponse
    {
        $record = Voucher::findOrFail($voucher);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'reason.required' => 'Say why it is being reversed - the reversal appears in the accounts.',
        ]);

        $reversal = $this->guard(
            fn () => $this->vouchers->reverse($record, $request->user()->id, $validated['reason'])
        );

        $this->audit($request, $record, 'voucher.reversed', [
            'reversal_voucher_no' => $reversal->voucher_no,
            'reason' => $validated['reason'],
        ]);

        return response()->json(['data' => $this->shape($reversal->fresh(self::WITH))], 201);
    }

    /**
     * A draft can be deleted; anything else cannot.
     *
     * An approved voucher's entries are in the ledger and have been reported
     * on. A rejected one is the record that somebody asked and was refused.
     */
    public function destroy(int $voucher): JsonResponse
    {
        $record = Voucher::findOrFail($voucher);

        if (! $record->isDraft()) {
            throw new ApiException(
                'VOUCHER_NOT_A_DRAFT',
                "This voucher is {$record->status} and cannot be deleted. "
                    .($record->isApproved() ? 'Post a reversal instead.' : 'A refusal is itself a record.'),
                422,
            );
        }

        $record->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'type' => ['required', 'in:'.implode(',', Voucher::TYPES)],
            'voucher_date' => ['required', 'date'],
            'narration' => ['sometimes', 'nullable', 'string', 'max:2000'],

            'lines' => ['required', 'array', 'min:2'],
            'lines.*.ledger_id' => ['required', 'integer', 'exists:ledgers,id'],
            'lines.*.debit' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'lines.*.narration' => ['sometimes', 'nullable', 'string', 'max:500'],
        ], [
            'lines.min' => 'A voucher needs at least two lines - something debited and something credited.',
        ]);
    }

    /**
     * Domain refusals belong in front of the user, not as a 500.
     *
     * Every message the service throws names what is wrong and what to do -
     * which line is unbalanced, by how much - so passing it through unchanged
     * is the useful thing.
     */
    private function guard(callable $work): Voucher
    {
        try {
            return $work();
        } catch (DomainException $e) {
            throw new ApiException('VOUCHER_REFUSED', $e->getMessage(), 422);
        }
    }

    /** @param  array<string, mixed>  $after */
    private function audit(Request $request, Voucher $voucher, string $action, array $after): void
    {
        AuditLog::create([
            'actor_type' => $request->user()::class,
            'actor_id' => $request->user()->id,
            'subject_type' => Voucher::class,
            'subject_id' => $voucher->id,
            'action' => $action,
            'after' => $after + ['voucher_no' => $voucher->voucher_no],
            'ip' => $request->ip(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function shape(Voucher $voucher): array
    {
        $debits = '0.00';
        $credits = '0.00';

        foreach ($voucher->lines as $line) {
            $debits = bcadd($debits, (string) $line->debit, 2);
            $credits = bcadd($credits, (string) $line->credit, 2);
        }

        return [
            'id' => $voucher->id,
            'voucher_no' => $voucher->voucher_no,
            'type' => $voucher->type,
            'voucher_date' => $voucher->voucher_date?->toDateString(),
            'narration' => $voucher->narration,
            'status' => $voucher->status,

            /*
             * The other half of a reversal pair, named rather than hinted at.
             *
             * A screen that knows a voucher has already been reversed can stop
             * offering to reverse it - a button whose only outcome is the
             * server's refusal is not a choice, it is a trap.
             */
            'reverses' => $voucher->reverses?->voucher_no,
            'reversed_by' => $voucher->reversal?->voucher_no,

            'lines' => $voucher->lines->map(fn ($line) => [
                'id' => $line->id,
                'ledger_id' => $line->ledger_id,
                'ledger' => $line->ledger?->name,

                // Strings, always. The app displays money and never computes it.
                'debit' => (string) $line->debit,
                'credit' => (string) $line->credit,
                'narration' => $line->narration,
            ]),

            // Totals from the server, so the screen never adds a column itself.
            'total_debit' => $debits,
            'total_credit' => $credits,

            'created_by' => $voucher->creator?->name,
            'approved_by' => $voucher->approver?->name,
            'approved_at' => $voucher->approved_at?->toDateTimeString(),
            'self_approved' => $voucher->selfApproved(),
        ];
    }
}
