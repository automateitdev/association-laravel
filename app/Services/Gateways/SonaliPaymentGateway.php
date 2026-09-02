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
 * Sonali Payment Gateway (SPG).
 *
 * Ported from the legacy PaymentController, which is the only record of how
 * this integration actually behaves. Endpoints and field names below are taken
 * from that working code rather than invented:
 *
 *   API      https://spg.com.bd:6314/          (test: spg.sblesheba.com:6314)
 *   Landing  https://spg.com.bd:6313/SpgLanding/SpgLanding/{session_token}
 *   Methods  api/v2/SpgService/GetAccessToken
 *            api/v2/SpgService/CreatePaymentRequest
 *            api/v2/SpgService/TransactionVerificationWithToken
 *
 * WHAT IS DIFFERENT FROM THE LEGACY INTEGRATION
 * ---------------------------------------------
 * 1. Credentials come from the ASSOCIATION's encrypted row, never from code.
 *    The legacy version has live production credentials - username, password,
 *    AR account and Basic auth header - as literals in a controller, committed
 *    to git (D-10). Those must be rotated with Sonali; moving them here does
 *    not undo the exposure.
 *
 * 2. The payment row exists BEFORE the member is redirected (FR-PAY-7). The
 *    legacy flow keeps the intent in the PHP session and writes the invoice on
 *    return, so a lost session means money taken at the bank with no invoice
 *    here (D-9) - routine once a phone is involved.
 *
 * 3. The callback is authenticated. Sonali posts credentials in the BODY
 *    rather than signing the request, so verifySignature() checks those against
 *    the stored webhook credentials. The legacy endpoint compares them to
 *    hardcoded literals; ours are per association and encrypted.
 *
 * 4. Whatever Sonali reports collecting lands in `gateway_amount`, never in
 *    `payable_amount` (D-1).
 *
 * ============================ UNVERIFIED ============================
 * This has NOT been run against a live or test merchant account in this
 * codebase. The shapes are faithful to the legacy code, but response parsing
 * should be confirmed against a real sandbox transaction before this is
 * enabled for an association. FakePaymentGateway remains the default.
 * ====================================================================
 */
class SonaliPaymentGateway implements PaymentGateway
{
    public const PROVIDER = 'spg';

    public function name(): string
    {
        return self::PROVIDER;
    }

    public function createSession(PaymentInfo $payment, string $returnUrl): GatewaySession
    {
        $credentials = $this->credentials();
        $token = $this->accessToken($credentials);

        $response = Http::asJson()
            ->withHeaders(['Authorization' => $credentials->credential('basic_auth')])
            ->timeout(30)
            ->post($this->apiUrl($credentials, 'CreatePaymentRequest'), [
                'AccessToken' => $token,
                'ARAccount' => $credentials->credential('ar_account'),
                'InvoiceNo' => $payment->invoice_no,

                // What the member actually pays: instalments PLUS fine. The
                // split is preserved on our side; the gateway collects a total.
                'TotalAmount' => (string) $payment->total_amount,

                'CustomerName' => $payment->member->name,
                'CustomerMobile' => $payment->member->mobile ?? '',
                'CustomerEmail' => $payment->member->email ?? '',
                'RedirectUrl' => $returnUrl,
            ]);

        $sessionToken = $response->json('SessionToken') ?? $response->json('session_token');

        if ($response->failed() || ! $sessionToken) {
            throw new RuntimeException(
                'Sonali Payment Gateway refused to create a session: '.$response->body()
            );
        }

        return new GatewaySession(
            url: $this->landingUrl($credentials, (string) $sessionToken),
            reference: (string) $sessionToken,
        );
    }

    public function verify(string $reference): GatewayVerification
    {
        $credentials = $this->credentials();
        $token = $this->accessToken($credentials);

        $response = Http::asJson()
            ->withHeaders(['Authorization' => $credentials->credential('basic_auth')])
            ->timeout(30)
            ->post($this->apiUrl($credentials, 'TransactionVerificationWithToken'), [
                'AccessToken' => $token,
                'SessionToken' => $reference,
            ]);

        $body = $response->json() ?? [];

        return new GatewayVerification(
            status: $this->mapStatus($body),
            reference: $reference,

            // Recorded for reconciliation only. Never payable_amount.
            amount: isset($body['PayAmount']) ? (string) $body['PayAmount'] : null,

            transactionId: $body['TransactionId'] ?? null,
            raw: is_array($body) ? $body : [],
        );
    }

    /**
     * Sonali does not sign callbacks - it posts credentials in the body.
     *
     * So the "signature" here is the Credentials block, checked against the
     * association's stored webhook credentials. The legacy endpoint compares
     * them to hardcoded literals and accepts anything else that guesses the
     * URL (D-16).
     */
    public function verifySignature(string $payload, ?string $signature): bool
    {
        $credentials = $this->credentials();

        $expectedUser = $credentials->credential('callback_username');
        $expectedPassword = $credentials->credential('callback_password');

        if (! $expectedUser || ! $expectedPassword) {
            // Fail CLOSED. An association that has not configured callback
            // credentials cannot have callbacks accepted on its behalf.
            return false;
        }

        $body = json_decode($payload, true);
        $sent = $body['Credentials'] ?? [];

        // hash_equals on both, so neither comparison leaks length by timing.
        return hash_equals((string) $expectedUser, (string) ($sent['userName'] ?? ''))
            && hash_equals((string) $expectedPassword, (string) ($sent['password'] ?? ''));
    }

    // ---- internals -------------------------------------------------------

    /**
     * The legacy code treats a payment as successful on an explicit success
     * status and nothing else. Anything unrecognised is PENDING rather than
     * FAILED: releasing a member's instalments because we could not parse a
     * response would be worse than leaving them held for the expiry sweep.
     */
    private function mapStatus(array $body): string
    {
        $status = strtolower((string) ($body['PaymentStatus'] ?? $body['Status'] ?? ''));

        return match (true) {
            in_array($status, ['success', 'paid', 'completed'], true) => GatewayVerification::PAID,
            in_array($status, ['failed', 'failure', 'declined'], true) => GatewayVerification::FAILED,
            in_array($status, ['cancel', 'cancelled', 'canceled'], true) => GatewayVerification::CANCELLED,
            default => GatewayVerification::PENDING,
        };
    }

    private function accessToken(GatewayCredential $credentials): string
    {
        $response = Http::asJson()
            ->withHeaders(['Authorization' => $credentials->credential('basic_auth')])
            ->timeout(30)
            ->post($this->apiUrl($credentials, 'GetAccessToken'), [
                'UserName' => $credentials->credential('username'),
                'Password' => $credentials->credential('password'),
            ]);

        $token = $response->json('AccessToken') ?? $response->json('access_token');

        if ($response->failed() || ! $token) {
            throw new RuntimeException('Sonali Payment Gateway refused to issue an access token.');
        }

        return (string) $token;
    }

    private function apiUrl(GatewayCredential $credentials, string $method): string
    {
        return rtrim((string) $credentials->credential('api_base_url'), '/')
            .'/api/v2/SpgService/'.$method;
    }

    private function landingUrl(GatewayCredential $credentials, string $sessionToken): string
    {
        return rtrim((string) $credentials->credential('redirect_base_url'), '/')
            .'/SpgLanding/SpgLanding/'.$sessionToken;
    }

    private function credentials(): GatewayCredential
    {
        return GatewayCredential::activeFor(self::PROVIDER)
            ?? throw new RuntimeException(
                'This association has no active Sonali Payment Gateway credentials configured.'
            );
    }
}
