<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Staff;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Tenant\AuditLog;
use App\Models\Tenant\Member;
use App\Models\Tenant\ShareTransfer;
use App\Services\ShareService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Share balances and transfers (FR-SHR-3).
 *
 * WHY A TRANSFER IS NOT A PAYMENT. Whatever the buyer paid the seller is
 * between the two members; the association took no money, so nothing here posts
 * to the ledger. The `amount` is recorded because members ask what a transfer
 * was worth, not because it is income.
 *
 * The balances this reads are the ones D-19 corrupted in the legacy system -
 * six members holding shares nobody had bought. They are derived from completed
 * payments and adjusted by transfers, and no endpoint anywhere lets a number be
 * typed into them directly.
 */
class ShareController extends Controller
{
    public function __construct(private readonly ShareService $shares) {}

    /**
     * One member's holdings, split by fee head.
     *
     * The split is what a transfer needs: "twelve shares" is not enough to
     * choose which of them to move.
     */
    public function show(int $member): JsonResponse
    {
        $record = Member::findOrFail($member);

        return response()->json([
            'data' => [
                'member_id' => $record->id,
                'member_name' => $record->name,
                'total' => $this->shares->balanceFor($record->id),
                'by_head' => $this->shares->balancesByHead($record->id),
            ],
        ]);
    }

    /**
     * Every transfer, newest first - the history balances do not keep.
     *
     * Filtered to one member, each row also says WHICH WAY it went for that
     * member. Sent and received are opposite facts about the same row, and a
     * screen showing a member their transfers cannot work them out from the
     * ids without knowing whose page it is on.
     */
    public function index(Request $request): JsonResponse
    {
        $memberId = $request->query('member_id') === null
            ? null
            : (int) $request->query('member_id');

        $transfers = ShareTransfer::query()
            ->with(['seller:id,name', 'buyer:id,name', 'feeSetup:id,fee_head'])
            ->when(
                $memberId,
                fn ($q, $id) => $q->where(fn ($w) => $w->where('seller_id', $id)->orWhere('buyer_id', $id))
            )
            ->latest('transferred_on')
            ->latest('id')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return response()->json([
            'data' => $transfers->getCollection()->map(fn (ShareTransfer $t) => [
                'id' => $t->id,
                'seller_id' => $t->seller_id,
                'seller_name' => $t->seller?->name,
                'buyer_id' => $t->buyer_id,
                'buyer_name' => $t->buyer?->name,
                'fee_head' => $t->feeSetup?->fee_head,
                'shares' => $t->shares,
                'amount' => (string) $t->amount,
                'note' => $t->note,
                'transferred_on' => $t->transferred_on?->toDateString(),

                // Null when the listing is not about anybody in particular.
                'direction' => $memberId === null
                    ? null
                    : ($t->buyer_id === $memberId ? 'received' : 'sent'),
            ]),
            'meta' => [
                'current_page' => $transfers->currentPage(),
                'total' => $transfers->total(),
                'last_page' => $transfers->lastPage(),
                'per_page' => $transfers->perPage(),
            ],
        ]);
    }

    /**
     * Record one transfer document: one seller, one date, one reason, many buyers.
     *
     * The batch is the unit because the act is. A member splitting their
     * holding between three people does it once, for one reason, on one day -
     * and if the third row is refused, the first two must not have happened
     * either. Sending three separate requests cannot promise that.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'seller_id' => ['required', 'integer', 'exists:members,id'],
            'transferred_on' => ['sometimes', 'date'],

            // Printed on both members' statements, not filed away internally -
            // it is the sentence explaining why instalments they paid for now
            // belong to somebody else. One reason for the whole document,
            // because there was one decision behind it.
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],

            'transfers' => ['required', 'array', 'min:1'],
            'transfers.*.buyer_id' => ['required', 'integer', 'exists:members,id', 'different:seller_id'],
            'transfers.*.fee_setup_id' => ['required', 'integer', 'exists:fee_setups,id'],
            'transfers.*.shares' => ['required', 'integer', 'min:1'],
        ], [
            'transfers.*.buyer_id.different' => 'A member cannot transfer instalments to themselves.',
        ]);

        /*
         * NO `amount` IN THE REQUEST, deliberately.
         *
         * It is the value of the instalments that moved, computed from the fee
         * head - the same figure the legacy screen shows as a read-only
         * "Amount (auto)". It reaches the member's statement and the paid
         * report, so it cannot mean whatever the officer typed that afternoon.
         */
        try {
            $created = $this->shares->transferMany(
                sellerId: $validated['seller_id'],
                rows: array_map(fn (array $row) => [
                    'buyer_id' => (int) $row['buyer_id'],
                    'fee_setup_id' => (int) $row['fee_setup_id'],
                    'shares' => (int) $row['shares'],
                ], $validated['transfers']),
                note: $validated['note'] ?? null,
                transferredOn: $validated['transferred_on'] ?? null,
                createdBy: $request->user()->id,
            );
        } catch (DomainException $e) {
            // The service's refusals are the domain rules - not enough held, a
            // transfer to oneself, the same buyer twice - and they belong in
            // front of the user rather than as a 500.
            throw new ApiException('SHARE_TRANSFER_REFUSED', $e->getMessage(), 422);
        }

        /*
         * Audited as one act, listing every row. The transfer rows say what
         * moved; the audit entry says who on the staff made it happen, which
         * they cannot - a member did not do this, an officer did.
         */
        AuditLog::create([
            'actor_type' => $request->user()::class,
            'actor_id' => $request->user()->id,
            'subject_type' => ShareTransfer::class,
            'subject_id' => $created[0]->id,
            'action' => 'shares.transferred',
            'after' => [
                'seller_id' => $validated['seller_id'],
                'transfers' => array_map(fn (ShareTransfer $t) => [
                    'id' => $t->id,
                    'buyer_id' => $t->buyer_id,
                    'fee_setup_id' => $t->fee_setup_id,
                    'shares' => $t->shares,
                    'amount' => (string) $t->amount,
                ], $created),
            ],
            'ip' => $request->ip(),
        ]);

        $total = '0.00';

        foreach ($created as $transfer) {
            $total = bcadd($total, (string) $transfer->amount, 2);
        }

        return response()->json([
            'data' => [
                'seller_id' => $validated['seller_id'],
                'transferred_on' => $created[0]->transferred_on->toDateString(),
                'note' => $created[0]->note,

                'transfers' => array_map(fn (ShareTransfer $t) => [
                    'id' => $t->id,
                    'buyer_id' => $t->buyer_id,
                    'fee_setup_id' => $t->fee_setup_id,
                    'shares' => $t->shares,
                    'amount' => (string) $t->amount,

                    // The buyer's new position, so the screen does not have to
                    // re-fetch to show what just happened.
                    'buyer_balance' => $this->shares->balanceFor($t->buyer_id),
                ], $created),

                'shares' => array_sum(array_map(fn (ShareTransfer $t) => $t->shares, $created)),
                'amount' => $total,
                'seller_balance' => $this->shares->balanceFor($validated['seller_id']),
            ],
        ], 201);
    }
}
