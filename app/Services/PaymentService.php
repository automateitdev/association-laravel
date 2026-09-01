<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\AuditLog;
use App\Models\Tenant\FeeAssign;
use App\Models\Tenant\PaymentInfo;
use App\Models\Tenant\PaymentInfoItem;
use App\Models\Tenant\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of payment state.
 *
 * Manual creation, gateway completion, approval, suspension and expiry all
 * funnel through here, so the money invariants have exactly one place to be
 * enforced. A second write path is how the legacy `payable_amount` drifted from
 * the sum of its items.
 *
 * The arithmetic, stated once:
 *
 *     payable_amount = SUM(item.amount)        instalments ONLY
 *     fine_amount    = SUM(item.fine_amount)
 *     total_amount   = payable_amount + fine_amount
 *
 * The gateway's own figure goes to `spg_pay_amount` and is never allowed near
 * `payable_amount` (defect D-1).
 */
class PaymentService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly ShareService $shares,
    ) {}

    /**
     * Create a payment covering one or more assignments.
     *
     * @param  array<int>  $feeAssignIds
     *
     * @throws \DomainException when an assignment is already settled or already
     *                          has a live attempt against it
     */
    public function create(
        int $memberId,
        array $feeAssignIds,
        string $paymentType = PaymentInfo::TYPE_MANUAL,
        ?int $ledgerId = null,
        ?int $createdBy = null,
    ): PaymentInfo {
        if ($feeAssignIds === []) {
            throw new \DomainException('A payment must cover at least one assignment.');
        }

        return DB::transaction(function () use ($memberId, $feeAssignIds, $paymentType, $ledgerId, $createdBy) {
            $assigns = FeeAssign::query()
                ->whereIn('id', $feeAssignIds)
                ->lockForUpdate()
                ->get();

            $this->guardAssignments($assigns, $feeAssignIds, $memberId);

            // Instalments and fines totalled SEPARATELY, and never merged.
            $payable = $assigns->reduce(
                fn (string $carry, FeeAssign $a) => bcadd($carry, (string) $a->amount, 2),
                '0.00'
            );
            $fine = $assigns->reduce(
                fn (string $carry, FeeAssign $a) => bcadd($carry, (string) $a->fine_amount, 2),
                '0.00'
            );

            $payment = PaymentInfo::create([
                'invoice_no' => $this->nextInvoiceNumber(),
                'member_id' => $memberId,
                'ledger_id' => $ledgerId,
                'payable_amount' => $payable,
                'fine_amount' => $fine,
                'total_amount' => bcadd($payable, $fine, 2),
                'status' => PaymentInfo::STATUS_PENDING,
                'payment_type' => $paymentType,
                'expires_at' => CarbonImmutable::now()->addMinutes($this->intentTtlMinutes()),
                'created_by' => $createdBy,
            ]);

            foreach ($assigns as $assign) {
                PaymentInfoItem::create([
                    'payment_info_id' => $payment->id,
                    'fee_assign_id' => $assign->id,
                    'period' => $assign->period,

                    // The item carries the assignment's own figures, unmerged.
                    'amount' => $assign->amount,
                    'fine_amount' => $assign->fine_amount,

                    'payment_status' => PaymentInfo::STATUS_PENDING,
                ]);

                $assign->update(['status' => FeeAssign::STATUS_REQUESTED]);
            }

            $this->assertInvariants($payment->fresh('items'));

            return $payment->fresh('items');
        });
    }

    /**
     * Complete a payment: mark it paid, settle its assignments, post the ledger
     * and mint any shares - all in ONE transaction (FR-PAY-12).
     *
     * Re-completing an already-completed payment is a NO-OP (FR-PAY-13). The
     * legacy approval path re-dispatches the ledger job and posts the entries a
     * second time (defect D-6).
     */
    public function complete(
        PaymentInfo $payment,
        ?int $ledgerId = null,
        ?int $decidedBy = null,
        ?string $gatewayAmount = null,
    ): PaymentInfo {
        if ($payment->isCompleted()) {
            return $payment;
        }

        return DB::transaction(function () use ($payment, $ledgerId, $decidedBy, $gatewayAmount) {
            $payment = PaymentInfo::query()->lockForUpdate()->findOrFail($payment->id);

            // Re-check under the lock: two approvals racing must not both post.
            if ($payment->isCompleted()) {
                return $payment;
            }

            $before = $payment->status;

            $payment->update([
                'status' => PaymentInfo::STATUS_COMPLETED,
                'ledger_id' => $ledgerId ?? $payment->ledger_id,
                'payment_date' => $payment->payment_date ?? CarbonImmutable::now()->toDateString(),
                'decided_by' => $decidedBy,
                'decided_at' => CarbonImmutable::now(),

                // What the gateway said it took. Recorded for reconciliation and
                // deliberately NOT written to payable_amount (defect D-1).
                'spg_pay_amount' => $gatewayAmount ?? $payment->spg_pay_amount,
            ]);

            // Drives the generated column that enforces I-1: at most one
            // completed item per assignment, ever. A second completed payment
            // for the same assignment fails here, at the database.
            $payment->items()->update(['payment_status' => PaymentInfo::STATUS_COMPLETED]);

            FeeAssign::query()
                ->whereIn('id', $payment->items()->pluck('fee_assign_id'))
                ->update(['status' => FeeAssign::STATUS_PAID]);

            $payment->refresh();

            if (! $this->ledger->alreadyPosted($payment)) {
                $this->ledger->postPayment($payment);
            }

            $this->shares->creditForPayment($payment);

            AuditLog::create([
                'subject_type' => PaymentInfo::class,
                'subject_id' => $payment->id,
                'action' => 'payment.completed',
                'before' => ['status' => $before],
                'after' => [
                    'status' => PaymentInfo::STATUS_COMPLETED,
                    'payable_amount' => (string) $payment->payable_amount,
                    'fine_amount' => (string) $payment->fine_amount,
                ],
            ]);

            return $payment;
        });
    }

    /**
     * Reject a payment: assignments go back to Unpaid, with a reason recorded.
     */
    public function suspend(PaymentInfo $payment, string $reason, ?int $decidedBy = null): PaymentInfo
    {
        if ($payment->isCompleted()) {
            throw new \DomainException(
                "Payment {$payment->invoice_no} is already completed; post a reversal instead of suspending it."
            );
        }

        return DB::transaction(function () use ($payment, $reason, $decidedBy) {
            $payment->update([
                'status' => PaymentInfo::STATUS_SUSPENDED,
                'reason' => $reason,
                'decided_by' => $decidedBy,
                'decided_at' => CarbonImmutable::now(),
            ]);

            $payment->items()->update(['payment_status' => PaymentInfo::STATUS_SUSPENDED]);

            $this->releaseAssignments($payment);

            AuditLog::create([
                'subject_type' => PaymentInfo::class,
                'subject_id' => $payment->id,
                'action' => 'payment.suspended',
                'after' => ['status' => PaymentInfo::STATUS_SUSPENDED],
                'reason' => $reason,
            ]);

            return $payment;
        });
    }

    /**
     * Release intents that were never confirmed (FR-PAY-8).
     *
     * Nothing in the legacy system ever returns a stale Requested assignment to
     * Unpaid, so an abandoned online attempt strands it forever (defect D-18).
     *
     * @return int payments expired
     */
    public function expireStaleIntents(?CarbonImmutable $asOf = null): int
    {
        $asOf ??= CarbonImmutable::now();

        $stale = PaymentInfo::query()
            ->where('status', PaymentInfo::STATUS_PENDING)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', $asOf)
            ->get();

        foreach ($stale as $payment) {
            DB::transaction(function () use ($payment) {
                $payment->update(['status' => PaymentInfo::STATUS_EXPIRED]);
                $payment->items()->update(['payment_status' => PaymentInfo::STATUS_EXPIRED]);
                $this->releaseAssignments($payment);
            });
        }

        return $stale->count();
    }

    /**
     * Return an intent's assignments to Unpaid - but only those not already
     * settled by some other payment.
     */
    private function releaseAssignments(PaymentInfo $payment): void
    {
        FeeAssign::query()
            ->whereIn('id', $payment->items()->pluck('fee_assign_id'))
            ->where('status', FeeAssign::STATUS_REQUESTED)
            ->update(['status' => FeeAssign::STATUS_UNPAID]);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, FeeAssign>  $assigns
     * @param  array<int>  $requestedIds
     */
    private function guardAssignments($assigns, array $requestedIds, int $memberId): void
    {
        if ($assigns->count() !== count(array_unique($requestedIds))) {
            throw new \DomainException('One or more assignments do not exist.');
        }

        foreach ($assigns as $assign) {
            // Ownership. The API layer checks this too, but a service that
            // trusts its caller is how defect D-5 happened: the legacy request
            // object's authorize() returns true, so a member can pay against
            // another member's dues.
            if ($assign->member_id !== $memberId) {
                throw new \DomainException(
                    "Assignment {$assign->id} does not belong to member {$memberId}."
                );
            }

            if ($assign->status === FeeAssign::STATUS_PAID) {
                throw new \DomainException("Assignment {$assign->id} is already paid.");
            }

            if ($assign->status === FeeAssign::STATUS_REQUESTED) {
                throw new \DomainException(
                    "Assignment {$assign->id} already has a payment awaiting confirmation."
                );
            }
        }
    }

    /**
     * The invariants, checked before the transaction commits. Belt to the
     * database's braces: these catch an arithmetic mistake in this class, which
     * a CHECK constraint cannot see.
     */
    private function assertInvariants(PaymentInfo $payment): void
    {
        $itemSum = $payment->items->reduce(
            fn (string $carry, PaymentInfoItem $i) => bcadd($carry, (string) $i->amount, 2),
            '0.00'
        );

        if (bccomp((string) $payment->payable_amount, $itemSum, 2) !== 0) {
            throw new \DomainException(
                "I-5 violated: payable_amount {$payment->payable_amount} != item sum {$itemSum}."
            );
        }

        $expectedTotal = bcadd((string) $payment->payable_amount, (string) $payment->fine_amount, 2);

        if (bccomp((string) $payment->total_amount, $expectedTotal, 2) !== 0) {
            throw new \DomainException(
                "I-6 violated: total_amount {$payment->total_amount} != payable + fine {$expectedTotal}."
            );
        }
    }

    /**
     * Unique per tenant, generated inside the creating transaction (FR-PAY-15).
     */
    private function nextInvoiceNumber(): string
    {
        $format = (string) Setting::get(Setting::INVOICE_FORMAT, 'INV-{YYYY}-{SEQ:6}');
        $year = CarbonImmutable::now()->format('Y');

        $prefix = str_replace('{YYYY}', $year, explode('{SEQ', $format)[0]);

        $width = 6;
        if (preg_match('/\{SEQ:(\d+)\}/', $format, $matches)) {
            $width = (int) $matches[1];
        }

        $last = PaymentInfo::query()
            ->where('invoice_no', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderByDesc('invoice_no')
            ->value('invoice_no');

        $next = $last
            ? ((int) substr($last, strlen($prefix))) + 1
            : 1;

        return $prefix.str_pad((string) $next, $width, '0', STR_PAD_LEFT);
    }

    private function intentTtlMinutes(): int
    {
        return (int) Setting::get(Setting::PAYMENT_INTENT_TTL_MINUTES, 60);
    }
}
