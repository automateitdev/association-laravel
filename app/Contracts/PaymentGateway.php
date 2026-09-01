<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\Tenant\PaymentInfo;
use App\Support\Gateway\GatewaySession;
use App\Support\Gateway\GatewayVerification;

/**
 * A payment gateway, as this platform needs to see one.
 *
 * Deliberately small. The whole point of the interface is that the completion
 * design in ADR-0007 does not depend on shurjoPay specifically - and, more
 * urgently, does not depend on the ANSWER to open risk R-5 (whether this
 * merchant account supports server-to-server webhooks).
 *
 * If R-5 resolves badly, `verify()` gets called by a scheduled reconciliation
 * poll instead of by a webhook handler. Nothing else moves.
 */
interface PaymentGateway
{
    /**
     * Ask the gateway for a hosted payment session.
     *
     * Called AFTER the payment row exists (FR-PAY-7). The legacy flow keeps the
     * intent in the PHP session and creates the row on return, so a lost session
     * means money taken at the bank with no invoice here (D-9).
     */
    public function createSession(PaymentInfo $payment, string $returnUrl): GatewaySession;

    /**
     * Ask the gateway what actually happened.
     *
     * The single source of truth for completion. The client never reports its
     * own success - it can be modified, and the money is real.
     */
    public function verify(string $reference): GatewayVerification;

    /**
     * Is this inbound callback genuinely from the gateway?
     *
     * The legacy equivalents of this endpoint are unauthenticated (D-16).
     */
    public function verifySignature(string $payload, ?string $signature): bool;

    public function name(): string;
}
