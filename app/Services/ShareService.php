<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\AssociatorInfo;
use App\Models\Tenant\MemberShareBalance;
use App\Models\Tenant\PaymentInfo;

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
}
