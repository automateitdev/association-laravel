<?php

declare(strict_types=1);

namespace App\Services\Gateways;

use App\Contracts\PaymentGateway;
use App\Models\Tenant\GatewayCredential;
use App\Models\Tenant\PaymentInfo;
use App\Support\Gateway\GatewaySession;
use App\Support\Gateway\GatewayVerification;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * shurjoPay.
 *
 * Credentials come from the ASSOCIATION's own row, never from config. That is
 * what makes onboarding a second association a command rather than a deploy
 * (G-5), and it is the direct fix for defect D-10: the legacy client has live
 * credentials as literals in a controller, committed to git.
 *
 * ============================ UNVERIFIED ============================
 * The request/response shapes below follow shurjoPay's published plugin, but
 * NOTHING HERE HAS BEEN RUN AGAINST A LIVE MERCHANT ACCOUNT. Two things must be
 * confirmed before this is trusted with real money:
 *
 *   1. R-5 - does this merchant account support a server-to-server webhook at
 *      all? If not, GatewayService must be driven by a scheduled reconciliation
 *      poll instead. The interface does not change; the trigger does.
 *   2. The exact field names and the signature scheme in verifySignature(),
 *      which is currently a reasonable guess.
 *
 * Until both are settled, FakePaymentGateway is the only implementation any
 * test or local environment uses.
 * ====================================================================
 */
class ShurjoPayGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'spg';
    }

    public function createSession(PaymentInfo $payment, string $returnUrl): GatewaySession
    {
        $credentials = $this->credentials();
        $token = $this->accessToken($credentials);

        $response = Http::asJson()
            ->timeout(20)
            ->post($this->endpoint($credentials, 'secret-pay'), [
                'token' => $token['token'],
                'store_id' => $token['store_id'],

                // What the member actually pays: instalments PLUS fine.
                // The split is preserved on our side; the gateway only needs a
                // total to collect.
                'amount' => (string) $payment->total_amount,

                'order_id' => $payment->invoice_no,
                'currency' => 'BDT',
                'customer_name' => $payment->member->name,
                'customer_phone' => $payment->member->mobile ?? '',
                'customer_email' => $payment->member->email ?? '',
                'customer_address' => $payment->member->present_address ?? '',
                'customer_city' => '',
                'client_ip' => request()->ip(),
                'return_url' => $returnUrl,
                'cancel_url' => $returnUrl,
            ]);

        if ($response->failed() || ! $response->json('checkout_url')) {
            throw new RuntimeException(
                'shurjoPay refused to create a payment session: '.$response->body()
            );
        }

        return new GatewaySession(
            url: (string) $response->json('checkout_url'),
            reference: (string) $response->json('sp_order_id'),
        );
    }

    public function verify(string $reference): GatewayVerification
    {
        $credentials = $this->credentials();
        $token = $this->accessToken($credentials);

        $response = Http::asJson()
            ->withToken($token['token'])
            ->timeout(20)
            ->post($this->endpoint($credentials, 'verification'), ['order_id' => $reference]);

        $body = $response->json();
        $row = $body[0] ?? $body;

        return new GatewayVerification(
            status: $this->mapStatus((string) ($row['sp_code'] ?? '')),
            reference: $reference,
            amount: isset($row['received_amount']) ? (string) $row['received_amount'] : null,
            transactionId: $row['bank_trx_id'] ?? null,
            raw: is_array($row) ? $row : [],
        );
    }

    public function verifySignature(string $payload, ?string $signature): bool
    {
        $secret = $this->credentials()->webhook_secret;

        if (! $secret || ! $signature) {
            // Fail CLOSED. An unverifiable callback is refused, not accepted -
            // the legacy routes accept anything (D-16).
            return false;
        }

        return hash_equals(hash_hmac('sha256', $payload, $secret), $signature);
    }

    // ---- internals -------------------------------------------------------

    /**
     * shurjoPay's sp_code: '1000' is success. Anything else is not, and the
     * distinction between "failed" and "still pending" matters because only a
     * terminal failure may release the member's instalments.
     */
    private function mapStatus(string $code): string
    {
        return match ($code) {
            '1000' => GatewayVerification::PAID,
            '1001', '1002', '1005' => GatewayVerification::PENDING,
            default => GatewayVerification::FAILED,
        };
    }

    private function accessToken(GatewayCredential $credentials): array
    {
        $response = Http::asJson()
            ->timeout(20)
            ->post($this->endpoint($credentials, 'get_token'), [
                'username' => $credentials->credential('username'),
                'password' => $credentials->credential('password'),
            ]);

        if ($response->failed() || ! $response->json('token')) {
            throw new RuntimeException('shurjoPay refused to issue an access token.');
        }

        return [
            'token' => (string) $response->json('token'),
            'store_id' => $response->json('store_id'),
        ];
    }

    private function endpoint(GatewayCredential $credentials, string $path): string
    {
        return rtrim((string) $credentials->credential('base_url'), '/').'/api/'.$path;
    }

    private function credentials(): GatewayCredential
    {
        return GatewayCredential::activeFor($this->name())
            ?? throw new RuntimeException(
                'This association has no active shurjoPay credentials configured.'
            );
    }
}
