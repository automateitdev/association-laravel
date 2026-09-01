<?php

declare(strict_types=1);

namespace App\Services\Gateways;

use App\Contracts\PaymentGateway;
use App\Models\Tenant\PaymentInfo;
use App\Support\Gateway\GatewaySession;
use App\Support\Gateway\GatewayVerification;
use Illuminate\Support\Str;

/**
 * A gateway that does not talk to anyone.
 *
 * Used by the whole test suite and by local development, so the entire payment
 * lifecycle - session, redirect, webhook, verification, ledger, shares - is
 * exercisable without a live merchant account, and without depending on the
 * answer to open risk R-5.
 *
 * The default verification result is PAID because that is the path with the most
 * downstream consequences (ledger posting, share minting, assignment settlement)
 * and therefore the one most worth exercising by default. Tests that need a
 * failure say so explicitly.
 */
class FakePaymentGateway implements PaymentGateway
{
    /** @var array<string, GatewayVerification> */
    private array $results = [];

    private string $defaultStatus = GatewayVerification::PAID;

    public function name(): string
    {
        return 'fake';
    }

    public function createSession(PaymentInfo $payment, string $returnUrl): GatewaySession
    {
        $reference = 'FAKE-'.Str::upper(Str::random(16));

        return new GatewaySession(
            url: "https://gateway.test/pay/{$reference}?return=".urlencode($returnUrl),
            reference: $reference,
        );
    }

    public function verify(string $reference): GatewayVerification
    {
        return $this->results[$reference] ?? new GatewayVerification(
            status: $this->defaultStatus,
            reference: $reference,
            amount: null,
            transactionId: 'TXN-'.$reference,
        );
    }

    /**
     * The fake accepts a fixed signature so signature handling is still
     * exercised end to end rather than skipped in tests.
     */
    public function verifySignature(string $payload, ?string $signature): bool
    {
        return $signature === 'valid-signature';
    }

    // ---- test controls ---------------------------------------------------

    public function willReport(string $reference, string $status, ?string $amount = null): self
    {
        $this->results[$reference] = new GatewayVerification(
            status: $status,
            reference: $reference,
            amount: $amount,
            transactionId: 'TXN-'.$reference,
        );

        return $this;
    }

    public function defaultsTo(string $status): self
    {
        $this->defaultStatus = $status;

        return $this;
    }
}
