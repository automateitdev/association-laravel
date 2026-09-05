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

        // The token is issued FOR this invoice, so it cannot be fetched first
        // and reused. See accessToken().
        $token = $this->accessToken($credentials, $payment);

        $response = $this->client($credentials)
            ->post($this->apiUrl($credentials, 'CreatePaymentRequest'), [
                'authentication' => [
                    'apiAccessUserId' => $credentials->credential('username'),
                    'apiAccessToken' => $token,
                ],

                'referenceInfo' => [
                    'InvoiceNo' => $payment->invoice_no,
                    'invoiceDate' => $this->invoiceDate($payment),
                    'returnUrl' => $returnUrl,

                    // What the member actually pays: instalments PLUS fine. The
                    // split is preserved on our side; the bank collects a total.
                    'totalAmount' => (string) $payment->total_amount,

                    /*
                     * SPG's own spelling. "applicent" is wrong English and the
                     * right key - renaming it to `applicantName` because it
                     * looks like a typo is how this call starts failing.
                     */
                    'applicentName' => $payment->member->name,
                    'applicentContactNo' => $payment->member->mobile ?: '010000000',

                    /*
                     * The legacy call hardcodes "2132" here, which is nobody's
                     * reference for anything. Ours carries the payment id, so a
                     * row in SPG's records can be traced back to one here.
                     *
                     * If the sandbox rejects the request, this is the first
                     * field to suspect - it is the only one that deviates from
                     * the call known to work in production.
                     */
                    'extraRefNo' => (string) $payment->id,
                ],

                'creditInformations' => [[
                    'slno' => '1',
                    'crAccount' => $credentials->credential('ar_account'),
                    'crAmount' => (string) $payment->total_amount,
                    'tranMode' => 'TRN',
                ]],
            ]);

        $sessionToken = $response->json('session_token') ?? $response->json('SessionToken');

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

        $response = $this->client($credentials)
            ->post($this->apiUrl($credentials, 'TransactionVerificationWithToken'), [
                /*
                 * `session_Token`, with that capital T in the middle. It is not
                 * a typo here - it is SPG's key, and the call fails silently
                 * against any tidier spelling. No access token is sent: this
                 * endpoint authenticates on the Authorization header alone.
                 */
                'session_Token' => $reference,
            ]);

        $body = $response->json() ?? [];

        if ($response->failed()) {
            /*
             * PENDING, not FAILED. An endpoint that would not answer says
             * nothing about whether the member paid, and treating it as failure
             * releases their instalments underneath a completed payment.
             */
            return new GatewayVerification(
                status: GatewayVerification::PENDING,
                reference: $reference,
                raw: is_array($body) ? $body : ['body' => $response->body()],
            );
        }

        return new GatewayVerification(
            status: $this->mapStatus($body),
            reference: $reference,

            // What SPG says it collected, including its own vat and commission.
            // Recorded for reconciliation, never copied into payable_amount.
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
     * `PaymentStatus` is a NUMBER, and 200 is the only success.
     *
     * The first version of this matched strings - 'success', 'paid',
     * 'completed' - which SPG never sends, so it would have reported every real
     * payment as pending forever. The legacy code, which has been taking money
     * for years, tests `PaymentStatus == 200` and nothing else.
     *
     * Anything unrecognised stays PENDING rather than FAILED. Releasing a
     * member's instalments because we could not parse a response is worse than
     * holding them until the expiry sweep, which is bounded.
     */
    private function mapStatus(array $body): string
    {
        $status = (string) ($body['PaymentStatus'] ?? $body['Status'] ?? '');

        return match (true) {
            $status === '200' => GatewayVerification::PAID,

            // Kept alongside the numeric test rather than instead of it: the
            // UAT endpoint has not been seen, and a wordier answer there should
            // not be read as a failure.
            in_array(strtolower($status), ['success', 'paid', 'completed'], true) => GatewayVerification::PAID,
            in_array(strtolower($status), ['failed', 'failure', 'declined'], true) => GatewayVerification::FAILED,
            in_array(strtolower($status), ['cancel', 'cancelled', 'canceled'], true) => GatewayVerification::CANCELLED,

            default => GatewayVerification::PENDING,
        };
    }

    /**
     * A token for ONE invoice, not a session token for the merchant.
     *
     * This was the first version's worst mistake: it sent only a username and
     * password, as though `GetAccessToken` were a login. SPG wants the invoice,
     * the amount and the credit account in the same call - the token it hands
     * back is scoped to that payment - so it cannot be fetched once and reused,
     * and a cached one would authorise the wrong amount.
     */
    private function accessToken(GatewayCredential $credentials, PaymentInfo $payment): string
    {
        $response = $this->client($credentials)
            ->post($this->apiUrl($credentials, 'GetAccessToken'), [
                'AccessUser' => [
                    'userName' => $credentials->credential('username'),
                    'password' => $credentials->credential('password'),
                ],
                'invoiceNo' => $payment->invoice_no,
                'amount' => (string) $payment->total_amount,
                'invoiceDate' => $this->invoiceDate($payment),
                'accounts' => [[
                    'crAccount' => $credentials->credential('ar_account'),

                    /*
                     * A JSON NUMBER here and a JSON STRING in
                     * `creditInformations` below. That asymmetry is in the call
                     * that works in production, so it is reproduced rather than
                     * tidied - the float cast is for the wire only and never
                     * reaches our own arithmetic, which stays bcmath on strings.
                     */
                    'crAmount' => (float) $payment->total_amount,
                ]],
            ]);

        $token = $response->json('access_token') ?? $response->json('AccessToken');

        if ($response->failed() || ! $token) {
            throw new RuntimeException(
                'Sonali Payment Gateway refused to issue an access token: '.$response->body()
            );
        }

        return (string) $token;
    }

    /**
     * One HTTP client, so every call carries the same auth and timeout.
     *
     * NOTE FOR A FIRST SANDBOX RUN: the legacy integration disables TLS peer
     * verification (`CURLOPT_SSL_VERIFYPEER => false`). This does not, because
     * turning it off would mean nobody could tell SPG from anyone able to
     * intercept the connection. If the sandbox fails with a certificate error,
     * that is why - and the fix is the correct CA bundle on the server, not
     * this line.
     */
    private function client(GatewayCredential $credentials): \Illuminate\Http\Client\PendingRequest
    {
        return Http::asJson()
            ->withHeaders(['Authorization' => (string) $credentials->credential('basic_auth')])
            ->timeout(30);
    }

    /** `Y-m-d`, from the row rather than the wall clock. */
    private function invoiceDate(PaymentInfo $payment): string
    {
        return $payment->created_at?->toDateString() ?? now()->toDateString();
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
