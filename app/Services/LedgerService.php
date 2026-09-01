<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\LedgerTrace;
use App\Models\Tenant\PaymentInfo;
use Illuminate\Support\Collection;

/**
 * Double-entry posting.
 *
 * Every posting balances or it is refused (FR-ACC-6). Traces are append-only; a
 * correction is a reversing entry (FR-ACC-9).
 *
 * The instalment and the fine go to DIFFERENT credit accounts. That separation
 * is the accounting half of ADR-0005, and it is the reason the legacy ledger
 * stayed correct while the legacy reports drifted: the two were computed in
 * different files from different columns.
 */
class LedgerService
{
    /**
     * Post a completed payment.
     *
     * Per item, up to two balanced pairs:
     *   instalment: debit cash/bank, credit the fee head's ledger
     *   fine:       debit cash/bank, credit the fee head's FINE ledger
     */
    public function postPayment(PaymentInfo $payment): Collection
    {
        if (! $payment->isCompleted()) {
            throw new \DomainException(
                "Refusing to post payment {$payment->invoice_no}: status is {$payment->status}, not completed."
            );
        }

        $receivingLedgerId = $payment->ledger_id;

        if (! $receivingLedgerId) {
            throw new \DomainException(
                "Payment {$payment->invoice_no} has no receiving ledger; cannot post."
            );
        }

        $rows = collect();
        $postedOn = ($payment->payment_date ?? $payment->created_at)->toDateString();

        foreach ($payment->items()->with('feeAssign.feeSetup')->get() as $item) {
            $feeSetup = $item->feeAssign->feeSetup;

            // The instalment.
            $rows->push(...$this->pair(
                debitLedgerId: $receivingLedgerId,
                creditLedgerId: $feeSetup->ledger_id,
                amount: (string) $item->amount,
                payment: $payment,
                postedOn: $postedOn,
                narration: "Instalment {$item->period} - {$feeSetup->fee_head}",
            ));

            // The fine, to its own account. Only when one was actually charged.
            if (bccomp((string) $item->fine_amount, '0.00', 2) > 0) {
                $rows->push(...$this->pair(
                    debitLedgerId: $receivingLedgerId,
                    // NOT NULL on the fee head, and staff-chosen. No config
                    // fallback, unlike the legacy system (FR-FEE-2).
                    creditLedgerId: $feeSetup->fine_ledger_id,
                    amount: (string) $item->fine_amount,
                    payment: $payment,
                    postedOn: $postedOn,
                    narration: "Fine {$item->period} - {$feeSetup->fee_head}",
                ));
            }
        }

        $this->assertBalanced($rows);

        return $rows;
    }

    /**
     * @return array<LedgerTrace>
     */
    private function pair(
        int $debitLedgerId,
        int $creditLedgerId,
        string $amount,
        PaymentInfo $payment,
        string $postedOn,
        string $narration,
    ): array {
        $common = [
            'source_type' => PaymentInfo::class,
            'source_id' => $payment->id,
            'reference' => $payment->invoice_no,
            'posted_on' => $postedOn,
            'narration' => $narration,
        ];

        return [
            LedgerTrace::create($common + [
                'ledger_id' => $debitLedgerId,
                'debit' => $amount,
                'credit' => '0.00',
            ]),
            LedgerTrace::create($common + [
                'ledger_id' => $creditLedgerId,
                'debit' => '0.00',
                'credit' => $amount,
            ]),
        ];
    }

    /**
     * FR-ACC-6. An unbalanced document is rejected, not stored - and because
     * this runs inside the completing transaction, rejection means the whole
     * payment rolls back rather than half-posting.
     */
    private function assertBalanced(Collection $rows): void
    {
        $debits = $rows->reduce(fn ($carry, $row) => bcadd($carry, (string) $row->debit, 2), '0.00');
        $credits = $rows->reduce(fn ($carry, $row) => bcadd($carry, (string) $row->credit, 2), '0.00');

        if (bccomp($debits, $credits, 2) !== 0) {
            throw new \DomainException(
                "Unbalanced posting: debits {$debits}, credits {$credits}."
            );
        }
    }

    /**
     * Has this document already been posted? Completion must be a no-op the
     * second time (FR-PAY-13) - the legacy approval screen re-dispatches the
     * ledger job and posts the entries again (defect D-6).
     */
    public function alreadyPosted(PaymentInfo $payment): bool
    {
        return LedgerTrace::query()
            ->where('source_type', PaymentInfo::class)
            ->where('source_id', $payment->id)
            ->exists();
    }
}
