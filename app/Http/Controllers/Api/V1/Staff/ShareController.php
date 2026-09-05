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

    /** Every transfer, newest first - the history balances do not keep. */
    public function index(Request $request): JsonResponse
    {
        $transfers = ShareTransfer::query()
            ->with(['seller:id,name', 'buyer:id,name', 'feeSetup:id,fee_head'])
            ->when(
                $request->query('member_id'),
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
                'transferred_on' => $t->transferred_on?->toDateString(),
            ]),
            'meta' => [
                'current_page' => $transfers->currentPage(),
                'total' => $transfers->total(),
                'last_page' => $transfers->lastPage(),
                'per_page' => $transfers->perPage(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'seller_id' => ['required', 'integer', 'exists:members,id'],
            'buyer_id' => ['required', 'integer', 'exists:members,id', 'different:seller_id'],
            'fee_setup_id' => ['required', 'integer', 'exists:fee_setups,id'],
            'shares' => ['required', 'integer', 'min:1'],

            // Optional and defaulted to zero: shares are sometimes gifted or
            // moved between family members, and forcing a price would invent one.
            'amount' => ['sometimes', 'numeric', 'min:0'],
            'transferred_on' => ['sometimes', 'date'],
        ], [
            'buyer_id.different' => 'A member cannot transfer shares to themselves.',
        ]);

        try {
            $transfer = $this->shares->transfer(
                sellerId: $validated['seller_id'],
                buyerId: $validated['buyer_id'],
                feeSetupId: $validated['fee_setup_id'],
                shares: $validated['shares'],
                amount: number_format((float) ($validated['amount'] ?? 0), 2, '.', ''),
                transferredOn: $validated['transferred_on'] ?? null,
                createdBy: $request->user()->id,
            );
        } catch (DomainException $e) {
            // The service's refusals are the domain rules - not enough shares,
            // a transfer to oneself - and they belong in front of the user
            // rather than as a 500.
            throw new ApiException('SHARE_TRANSFER_REFUSED', $e->getMessage(), 422);
        }

        /*
         * Audited in the association's own log as well as recorded as a
         * transfer. The transfer row says what moved; the audit entry says who
         * on the staff made it happen, which the transfer row cannot - a member
         * did not do this, an officer did.
         */
        AuditLog::create([
            'actor_type' => $request->user()::class,
            'actor_id' => $request->user()->id,
            'subject_type' => ShareTransfer::class,
            'subject_id' => $transfer->id,
            'action' => 'shares.transferred',
            'after' => [
                'seller_id' => $transfer->seller_id,
                'buyer_id' => $transfer->buyer_id,
                'shares' => $transfer->shares,
            ],
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'data' => [
                'id' => $transfer->id,
                'seller_id' => $transfer->seller_id,
                'buyer_id' => $transfer->buyer_id,
                'shares' => $transfer->shares,
                'amount' => (string) $transfer->amount,
                'transferred_on' => $transfer->transferred_on->toDateString(),

                // The new positions, so the screen does not have to re-fetch to
                // show what just happened.
                'seller_balance' => $this->shares->balanceFor($transfer->seller_id),
                'buyer_balance' => $this->shares->balanceFor($transfer->buyer_id),
            ],
        ], 201);
    }
}
