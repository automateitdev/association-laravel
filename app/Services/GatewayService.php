<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Models\Tenant\GatewayEvent;
use App\Models\Tenant\PaymentInfo;
use App\Support\Gateway\GatewaySession;
use App\Support\Gateway\GatewayVerification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The gateway round trip (ADR-0007).
 *
 * Four rules, and they exist because the legacy flow breaks all of them:
 *
 *   1. The payment row is persisted BEFORE the member is redirected. A dropped
 *      round trip leaves a reconcilable `pending` row, not nothing (D-9).
 *   2. The gateway's callback is AUTHORITATIVE. The app's poll is a
 *      convenience; if the member kills the app mid-payment it still completes.
 *   3. The app NEVER reports its own success. It can be modified; the money is
 *      real.
 *   4. Every callback is recorded RAW before it is acted on, so a dispute
 *      between us and the gateway has evidence rather than opinions.
 */
class GatewayService
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly PaymentService $payments,
    ) {}

    /**
     * Start a hosted payment session for an existing pending payment.
     */
    public function startSession(PaymentInfo $payment, string $returnUrl): GatewaySession
    {
        if (! $payment->isPending()) {
            throw new \DomainException(
                "Payment {$payment->invoice_no} is {$payment->status}; only a pending payment can start a gateway session."
            );
        }

        $session = $this->gateway->createSession($payment, $returnUrl);

        // Stored BEFORE the member leaves. Without this, a callback for an
        // abandoned attempt has nothing to match against.
        $payment->update([
            'gateway_reference' => $session->reference,
            'payment_type' => PaymentInfo::TYPE_ONLINE,
        ]);

        return $session;
    }

    /**
     * Handle an inbound callback.
     *
     * Idempotent by construction: completion delegates to PaymentService, which
     * returns early on an already-completed payment (FR-PAY-13). A redelivered
     * webhook therefore posts nothing twice - the legacy approval path
     * re-dispatches the ledger job and double-posts (D-6).
     *
     * @return string what happened, for the caller to log
     */
    public function handleCallback(array $payload, ?string $rawBody = null, ?string $signature = null): string
    {
        // Recorded first, always - even if the signature turns out to be wrong.
        // A forged callback is itself worth having a record of.
        $event = GatewayEvent::create([
            'provider' => $this->gateway->name(),
            'event_type' => 'callback',
            'reference' => $payload['reference'] ?? $payload['order_id'] ?? null,
            'payload' => $payload,
            'received_at' => now(),
        ]);

        if (! $this->gateway->verifySignature($rawBody ?? json_encode($payload), $signature)) {
            $event->update(['processing_error' => 'Invalid signature.']);

            throw new \DomainException('Invalid gateway signature.');
        }

        $reference = $event->reference;

        if (! $reference) {
            $event->update(['processing_error' => 'No reference in payload.']);

            throw new \DomainException('Callback carried no payment reference.');
        }

        $payment = PaymentInfo::query()->where('gateway_reference', $reference)->first();

        if (! $payment) {
            $event->update(['processing_error' => "No payment matches reference {$reference}."]);

            // Not an error on our side: a callback for a reference we never
            // issued is either a stray retry or an attack. Recorded, refused.
            throw new \DomainException("No payment matches gateway reference {$reference}.");
        }

        $event->update(['payment_info_id' => $payment->id]);

        // We ask the gateway what happened rather than believing the callback
        // body. A callback tells us to LOOK; it does not tell us the answer.
        $verification = $this->gateway->verify($reference);

        $outcome = $this->apply($payment, $verification);

        $event->update(['processed_at' => now()]);

        return $outcome;
    }

    /**
     * An unsigned notice that an invoice is worth asking about.
     *
     * FOR PAYFLEX, WHICH SIGNS NOTHING. It posts its result to us with
     * `Http::post($url, $payload)` and no signature, so `handleCallback` would
     * refuse it - correctly, since for a gateway that DOES sign, an unsigned
     * callback is a forgery.
     *
     * A SEPARATE ENTRY POINT RATHER THAN A FLAG. Adding "skip the signature" to
     * `handleCallback` would put that switch one bad merge away from the signed
     * gateways, and a signature check that can be turned off by a request is not
     * a signature check. This method is reachable only from the PayFlex route.
     *
     * WHAT MAKES IT SAFE is that the body is not evidence of anything. The ONLY
     * thing taken from it is an invoice number - not an amount, not a status,
     * not a transaction id - and then we ask the gateway ourselves, over an
     * authenticated request, and act on that answer. An attacker who guesses the
     * URL and posts an invoice number achieves exactly one thing: we look up a
     * payment we already have and ask the gateway about it, which we would have
     * done anyway on the reconciliation sweep.
     */
    public function handleUnsignedNotice(string $reference, array $payload = []): string
    {
        $event = GatewayEvent::create([
            'provider' => $this->gateway->name(),
            'event_type' => 'callback',
            'reference' => $reference,
            'payload' => $payload,
            'received_at' => now(),
        ]);

        $payment = PaymentInfo::query()->where('gateway_reference', $reference)->first();

        if (! $payment) {
            $event->update(['processing_error' => "No payment matches reference {$reference}."]);

            throw new \DomainException("No payment matches gateway reference {$reference}.");
        }

        $event->update(['payment_info_id' => $payment->id]);

        $outcome = $this->apply($payment, $this->gateway->verify($reference));

        $event->update(['processed_at' => now()]);

        return $outcome;
    }

    /**
     * Reconciliation fallback.
     *
     * If R-5 resolves badly and this merchant account cannot deliver
     * server-to-server callbacks, THIS becomes the primary completion path,
     * driven by a scheduled sweep instead of by the gateway. The design
     * degrades to slower completion, not to incorrect completion.
     *
     * It is worth running even with webhooks working: a webhook that never
     * arrives is invisible otherwise.
     *
     * @return array{checked: int, completed: int, released: int}
     */
    public function reconcilePending(): array
    {
        $pending = PaymentInfo::query()
            ->where('status', PaymentInfo::STATUS_PENDING)
            ->where('payment_type', PaymentInfo::TYPE_ONLINE)
            ->whereNotNull('gateway_reference')
            ->get();

        $completed = 0;
        $released = 0;

        foreach ($pending as $payment) {
            try {
                $verification = $this->gateway->verify($payment->gateway_reference);
                $outcome = $this->apply($payment, $verification);

                $outcome === 'completed' && $completed++;
                $outcome === 'released' && $released++;
            } catch (\Throwable $e) {
                // One unreachable reference must not stop the sweep.
                Log::warning('Reconciliation failed for payment', [
                    'invoice_no' => $payment->invoice_no,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['checked' => $pending->count(), 'completed' => $completed, 'released' => $released];
    }

    /**
     * Act on a verification result.
     */
    private function apply(PaymentInfo $payment, GatewayVerification $verification): string
    {
        if ($verification->isPaid()) {
            DB::transaction(function () use ($payment, $verification) {
                $this->payments->complete(
                    $payment,
                    ledgerId: $payment->ledger_id ?? $this->defaultReceivingLedgerId(),

                    // The gateway's own figure, recorded for reconciliation and
                    // kept well away from payable_amount (D-1).
                    gatewayAmount: $verification->amount,
                );
            });

            return 'completed';
        }

        if ($verification->isFinalFailure()) {
            // Terminal: release the member's instalments rather than leaving
            // them stranded in Requested (D-18).
            $this->payments->suspend(
                $payment,
                'Gateway reported: '.$verification->status,
            );

            return 'released';
        }

        // Still pending at the gateway. Leave it alone; the intent expiry sweep
        // will release it if it never resolves.
        return 'pending';
    }

    /**
     * Online payments land in the association's cash ledger unless the payment
     * already names one.
     */
    private function defaultReceivingLedgerId(): ?int
    {
        return \App\Models\Tenant\Ledger::query()
            ->where('name', 'Cash in Hand')
            ->value('id');
    }
}
