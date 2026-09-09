<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\AssociatorInfo;
use App\Models\Tenant\FeeSetup;
use App\Models\Tenant\Member;
use App\Models\Tenant\MemberShareBalance;
use App\Models\Tenant\PaymentInfo;
use App\Models\Tenant\ShareTransfer;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Minting and moving shares.
 *
 * THE correction here (FR-SHR-2, defect D-8):
 *
 *   shares = floor(ASSIGNED instalment / share price)
 *
 * not the charged amount. The legacy service divides `item.amount`, so a fine
 * folded into that column mints shares nobody bought - credited permanently, and
 * transferable away before anyone notices.
 *
 * Dividing the assigned amount makes the bug unreachable even if an item were
 * somehow written with a merged figure.
 */
class ShareService
{
    /**
     * Credit shares for every share-flagged line of a completed payment.
     *
     * @return int total shares minted
     */
    public function creditForPayment(PaymentInfo $payment): int
    {
        if (! $payment->isCompleted()) {
            throw new \DomainException(
                "Refusing to mint shares for {$payment->invoice_no}: status is {$payment->status}."
            );
        }

        $minted = 0;

        foreach ($payment->items()->with('feeAssign.feeSetup')->get() as $item) {
            $feeSetup = $item->feeAssign->feeSetup;

            if (! $feeSetup->is_share) {
                continue;
            }

            $sharePrice = (string) $feeSetup->amount;

            if (bccomp($sharePrice, '0.00', 2) <= 0) {
                continue;
            }

            // The ASSIGNED amount, not the charged one. This single choice is
            // the whole of D-8's fix.
            $assignedAmount = (string) $item->feeAssign->amount;

            $shares = (int) bcdiv($assignedAmount, $sharePrice, 0);

            if ($shares < 1) {
                continue;
            }

            $this->credit($item->feeAssign->member_id, $feeSetup->id, $shares);
            $minted += $shares;
        }

        return $minted;
    }

    private function credit(int $memberId, int $feeSetupId, int $shares): void
    {
        $balance = MemberShareBalance::query()->firstOrCreate(
            ['member_id' => $memberId, 'fee_setup_id' => $feeSetupId],
            ['shares' => 0]
        );

        $balance->increment('shares', $shares);

        /*
         * Set from the balances, not incremented - see syncTotal.
         *
         * It does NOT throw here, and that is the difference between this and
         * the transfer path. A payment has already been taken; refusing to
         * complete it because a denormalised counter has nowhere to live would
         * lose real money over a number that is recomputable by definition.
         * The balance above is correct either way, and syncTotal logs.
         */
        $this->syncTotal($memberId, expected: $this->storedTotal($memberId) + $shares);
    }

    /**
     * Rewrite a member's denormalised share total from the balances it summarises.
     *
     * WHY SET RATHER THAN INCREMENT. `num_or_shares` is `UNSIGNED`, and this
     * used to be `decrement('num_or_shares', $shares)`. When the column
     * disagreed with `member_share_balances` - which is precisely what D-19
     * did to the legacy data, and what this table is watched for - the
     * subtraction went below zero and MySQL raised
     * `SQLSTATE[22003] value is out of range`. A raw SQL error, a 500, and a
     * transfer refused for a reason no officer could act on. Assigning the sum
     * cannot underflow, is idempotent, and quietly restores the invariant
     * instead of compounding a drift.
     *
     * It is not a licence to let the two disagree: `$expected` is what the old
     * arithmetic would have produced, and any difference is logged as the
     * corruption it is. Absorbing that silently is how D-19 survived for years.
     *
     * @return bool Whether the member has a society record to hold the total.
     */
    private function syncTotal(int $memberId, ?int $expected = null): bool
    {
        $info = AssociatorInfo::query()->where('member_id', $memberId)->first();

        if (! $info) {
            Log::error('ShareService: member has no society record, share total not written', [
                'member_id' => $memberId,
            ]);

            return false;
        }

        $total = $this->balanceFor($memberId);

        if ($expected !== null && $expected !== $total) {
            Log::warning('ShareService: denormalised share total was out of step, corrected', [
                'member_id' => $memberId,
                'stored' => (int) $info->num_or_shares,
                'expected' => $expected,
                'balances' => $total,
            ]);
        }

        $info->forceFill(['num_or_shares' => $total])->save();

        return true;
    }

    /** The denormalised total as it currently stands, for comparison only. */
    private function storedTotal(int $memberId): int
    {
        return (int) AssociatorInfo::query()->where('member_id', $memberId)->value('num_or_shares');
    }

    public function balanceFor(int $memberId): int
    {
        return (int) MemberShareBalance::query()->where('member_id', $memberId)->sum('shares');
    }

    /**
     * Move instalments from one member to one or more others (FR-SHR-3).
     *
     * ONE SELLER, MANY BUYERS, ONE DOCUMENT - which is the shape of the act,
     * not a convenience. A member disposing of their holding usually splits it
     * between several people on one day for one reason, and recording that as
     * four unrelated transfers loses the fact that it was one decision. The
     * legacy screen worked this way and the rewrite had narrowed it to a single
     * pair.
     *
     * WHAT THIS REFUSES, AND WHY EACH ONE MATTERS
     *
     *   - More instalments than the seller holds IN THAT FEE HEAD, counted
     *     ACROSS THE WHOLE DOCUMENT. Ten to one buyer and ten to another out of
     *     a holding of twelve is the same overdraft as one row of twenty, and
     *     checking rows one at a time would let it through.
     *   - The same buyer twice for the same head. Two rows that should have
     *     been one, and whichever is read second looks like a correction of the
     *     first.
     *   - A transfer to oneself. It nets to nothing while leaving a record
     *     implying something happened, which is worse than refusing.
     *   - Zero or negative instalments. A transfer of nothing is a mistake, and
     *     a negative one is a theft written backwards.
     *
     * ONE TRANSACTION FOR THE WHOLE DOCUMENT. Balances move and records are
     * written for every row together; a document that half-applied would leave
     * instalments existing twice or not at all, and nothing on the screen would
     * say which half.
     *
     * NO LEDGER POSTING. Whatever passed between the members is between them -
     * the association took no money, so nothing belongs in its accounts.
     *
     * @param  list<array{buyer_id: int, fee_setup_id: int, shares: int}>  $rows
     * @return list<ShareTransfer>
     */
    public function transferMany(
        int $sellerId,
        array $rows,
        ?string $note = null,
        ?string $transferredOn = null,
        ?int $createdBy = null,
    ): array {
        if ($rows === []) {
            throw new DomainException('A transfer needs at least one buyer.');
        }

        $seen = [];

        foreach ($rows as $row) {
            if ($row['shares'] < 1) {
                throw new DomainException('A transfer must move at least one instalment.');
            }

            if ($row['buyer_id'] === $sellerId) {
                throw new DomainException('A member cannot transfer instalments to themselves.');
            }

            $pair = $row['buyer_id'].':'.$row['fee_setup_id'];

            if (isset($seen[$pair])) {
                throw new DomainException(
                    'The same buyer appears twice for the same fee head. Combine them into one row.'
                );
            }

            $seen[$pair] = true;
        }

        // Summed per head first: the holding is what limits the DOCUMENT, not
        // what limits each row of it.
        $wantedByHead = [];

        foreach ($rows as $row) {
            $wantedByHead[$row['fee_setup_id']] = ($wantedByHead[$row['fee_setup_id']] ?? 0) + $row['shares'];
        }

        /*
         * Both sides need a society record before anything moves.
         *
         * The total lives on that row, so without one a transfer would move
         * the balances and leave the figure staff read untouched - the two
         * stores drifting apart by exactly the amount transferred, silently,
         * which is the D-19 failure written fresh. The legacy screen refused
         * this too. Checked here rather than mid-document so the refusal names
         * the member while nothing has been applied.
         */
        $parties = array_values(array_unique(array_merge(
            [$sellerId],
            array_map(fn (array $row) => $row['buyer_id'], $rows),
        )));

        $withRecords = AssociatorInfo::query()
            ->whereIn('member_id', $parties)
            ->pluck('member_id')
            ->all();

        $missing = array_diff($parties, $withRecords);

        if ($missing !== []) {
            $names = Member::query()->whereIn('id', $missing)->pluck('name')->all();

            throw new DomainException(
                'No society record for '.implode(', ', $names)
                .', so instalments cannot be moved to or from them.'
            );
        }

        foreach ($wantedByHead as $feeSetupId => $wanted) {
            $held = (int) MemberShareBalance::query()
                ->where('member_id', $sellerId)
                ->where('fee_setup_id', $feeSetupId)
                ->value('shares');

            if ($held < $wanted) {
                throw new DomainException(
                    "The seller holds {$held} instalment(s) of this fee head, so {$wanted} cannot be transferred."
                );
            }
        }

        /*
         * THE PRICE IS THE ASSOCIATION'S, NOT THE OFFICER'S.
         *
         * `amount` is the value of the instalments that moved - what they cost
         * when they were paid for - and it is computed here from the fee head
         * rather than typed in. The legacy screen shows it as a read-only
         * "Amount (auto)" for the same reason: it is the figure that reaches
         * the member's statement and the paid report, so it has to mean the
         * same thing on every row of every transfer ever recorded. A typed
         * figure means whatever the officer meant that afternoon.
         *
         * Whatever money actually passed between the two members is their
         * business and the association does not record it.
         */
        $prices = FeeSetup::query()
            ->whereIn('id', array_keys($wantedByHead))
            ->pluck('amount', 'id');

        return DB::transaction(function () use ($sellerId, $rows, $prices, $note, $transferredOn, $createdBy) {
            $created = [];

            foreach ($rows as $row) {
                $created[] = $this->applyOne(
                    sellerId: $sellerId,
                    buyerId: $row['buyer_id'],
                    feeSetupId: $row['fee_setup_id'],
                    shares: $row['shares'],
                    amount: bcmul((string) $row['shares'], (string) ($prices[$row['fee_setup_id']] ?? '0'), 2),
                    note: $note,
                    transferredOn: $transferredOn,
                    createdBy: $createdBy,
                );
            }

            return $created;
        });
    }

    /**
     * One buyer, for the callers that only ever have one.
     *
     * A document of a single row, so the refusals and the arithmetic are the
     * same code rather than the same rules written twice.
     */
    public function transfer(
        int $sellerId,
        int $buyerId,
        int $feeSetupId,
        int $shares,
        ?string $note = null,
        ?string $transferredOn = null,
        ?int $createdBy = null,
    ): ShareTransfer {
        return $this->transferMany(
            sellerId: $sellerId,
            rows: [['buyer_id' => $buyerId, 'fee_setup_id' => $feeSetupId, 'shares' => $shares]],
            note: $note,
            transferredOn: $transferredOn,
            createdBy: $createdBy,
        )[0];
    }

    /**
     * One row of a document, inside the caller's transaction.
     *
     * Re-reads and locks the seller's balance even though transferMany checked
     * it: rows earlier in the same document have already drawn it down, and a
     * concurrent transfer could have moved it between the check and here.
     */
    private function applyOne(
        int $sellerId,
        int $buyerId,
        int $feeSetupId,
        int $shares,
        string $amount,
        ?string $note,
        ?string $transferredOn,
        ?int $createdBy,
    ): ShareTransfer {
        // Before anything moves, so the drift check below has something to
        // compare against.
        $sellerTotalBefore = $this->storedTotal($sellerId);
        $buyerTotalBefore = $this->storedTotal($buyerId);

        $sellerBalance = MemberShareBalance::query()
            ->where('member_id', $sellerId)
            ->where('fee_setup_id', $feeSetupId)
            ->lockForUpdate()
            ->first();

        if (! $sellerBalance || $sellerBalance->shares < $shares) {
            throw new DomainException('The seller no longer holds enough instalments.');
        }

        $sellerBalance->decrement('shares', $shares);

        $buyerBalance = MemberShareBalance::query()->firstOrCreate(
            ['member_id' => $buyerId, 'fee_setup_id' => $feeSetupId],
            ['shares' => 0]
        );

        $buyerBalance->increment('shares', $shares);

        /*
         * The denormalised totals staff actually read, kept in step. A report
         * reading one and a profile reading the other must not disagree.
         *
         * Read BEFORE the assignment so `expected` is what the old
         * increment/decrement would have produced - the comparison is the only
         * thing that still notices a drift, now that the arithmetic cannot
         * fail on one.
         */
        $this->syncTotal($sellerId, expected: $sellerTotalBefore - $shares);
        $this->syncTotal($buyerId, expected: $buyerTotalBefore + $shares);

        return ShareTransfer::create([
            'seller_id' => $sellerId,
            'buyer_id' => $buyerId,
            'fee_setup_id' => $feeSetupId,
            'shares' => $shares,
            'amount' => $amount,
            'note' => $note,
            'transferred_on' => $transferredOn ?? now()->toDateString(),
            'created_by' => $createdBy,
        ]);
    }

    /**
     * What a member holds, split by fee head.
     *
     * The split is what a transfer screen needs: "you have 12 shares" is not
     * enough to choose which of them to move.
     *
     * @return list<array{fee_setup_id: int, fee_head: string, shares: int}>
     */
    public function balancesByHead(int $memberId): array
    {
        return MemberShareBalance::query()
            ->with('feeSetup:id,fee_head,amount')
            ->where('member_id', $memberId)
            ->where('shares', '>', 0)
            ->get()
            ->map(fn (MemberShareBalance $b) => [
                'fee_setup_id' => $b->fee_setup_id,
                'fee_head' => $b->feeSetup?->fee_head ?? 'Unknown',
                'shares' => (int) $b->shares,

                // What one instalment of this head cost. The transfer screen
                // multiplies by it rather than asking anybody to.
                'price' => (string) ($b->feeSetup?->amount ?? '0.00'),
            ])
            ->values()
            ->all();
    }
}
