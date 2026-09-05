<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\AssociatorInfo;
use App\Models\Tenant\MemberShareBalance;
use App\Models\Tenant\PaymentInfo;
use App\Models\Tenant\ShareTransfer;
use DomainException;
use Illuminate\Support\Facades\DB;

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

        // The denormalised running total staff actually look at.
        AssociatorInfo::query()
            ->where('member_id', $memberId)
            ->increment('num_or_shares', $shares);
    }

    public function balanceFor(int $memberId): int
    {
        return (int) MemberShareBalance::query()->where('member_id', $memberId)->sum('shares');
    }

    /**
     * Move shares from one member to another (FR-SHR-3).
     *
     * WHAT THIS REFUSES, AND WHY EACH ONE MATTERS
     *
     *   - More shares than the seller holds IN THAT FEE HEAD. Balances are per
     *     head, so a member with ten Monthly Savings shares and two of another
     *     kind cannot transfer twelve of either.
     *   - A transfer to oneself. It would net to nothing while leaving a record
     *     implying something happened, which is worse than refusing.
     *   - Zero or negative shares. A transfer of nothing is a mistake, and a
     *     negative one is a theft written backwards.
     *
     * ONE TRANSACTION. Two balances move and a record is written; any of the
     * three failing alone would leave shares that exist twice or not at all.
     *
     * NO LEDGER POSTING. Whatever the buyer paid the seller is between them -
     * the association took no money, so nothing belongs in its accounts. The
     * `amount` is recorded because members ask, not because it is income.
     */
    public function transfer(
        int $sellerId,
        int $buyerId,
        int $feeSetupId,
        int $shares,
        string $amount = '0.00',
        ?string $transferredOn = null,
        ?int $createdBy = null,
    ): ShareTransfer {
        if ($shares < 1) {
            throw new DomainException('A transfer must move at least one share.');
        }

        if ($sellerId === $buyerId) {
            throw new DomainException('A member cannot transfer shares to themselves.');
        }

        $held = (int) MemberShareBalance::query()
            ->where('member_id', $sellerId)
            ->where('fee_setup_id', $feeSetupId)
            ->value('shares');

        if ($held < $shares) {
            throw new DomainException(
                "The seller holds {$held} share(s) of this fee head, so {$shares} cannot be transferred."
            );
        }

        return DB::transaction(function () use (
            $sellerId, $buyerId, $feeSetupId, $shares, $amount, $transferredOn, $createdBy
        ) {
            /*
             * Decrement first. If the seller's row somehow moved between the
             * check above and here, the guard below catches it rather than
             * letting a negative balance exist for even one statement.
             */
            $sellerBalance = MemberShareBalance::query()
                ->where('member_id', $sellerId)
                ->where('fee_setup_id', $feeSetupId)
                ->lockForUpdate()
                ->first();

            if (! $sellerBalance || $sellerBalance->shares < $shares) {
                throw new DomainException('The seller no longer holds enough shares.');
            }

            $sellerBalance->decrement('shares', $shares);

            $buyerBalance = MemberShareBalance::query()->firstOrCreate(
                ['member_id' => $buyerId, 'fee_setup_id' => $feeSetupId],
                ['shares' => 0]
            );

            $buyerBalance->increment('shares', $shares);

            // The denormalised totals staff actually read, kept in step. A
            // report reading one and a profile reading the other must not
            // disagree.
            AssociatorInfo::query()->where('member_id', $sellerId)->decrement('num_or_shares', $shares);
            AssociatorInfo::query()->where('member_id', $buyerId)->increment('num_or_shares', $shares);

            return ShareTransfer::create([
                'seller_id' => $sellerId,
                'buyer_id' => $buyerId,
                'fee_setup_id' => $feeSetupId,
                'shares' => $shares,
                'amount' => $amount,
                'transferred_on' => $transferredOn ?? now()->toDateString(),
                'created_by' => $createdBy,
            ]);
        });
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
            ->with('feeSetup:id,fee_head')
            ->where('member_id', $memberId)
            ->where('shares', '>', 0)
            ->get()
            ->map(fn (MemberShareBalance $b) => [
                'fee_setup_id' => $b->fee_setup_id,
                'fee_head' => $b->feeSetup?->fee_head ?? 'Unknown',
                'shares' => (int) $b->shares,
            ])
            ->values()
            ->all();
    }
}
