<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Models\Tenant\GatewayCredential;
use App\Models\Tenant\Member;
use App\Models\Tenant\PaymentInfo;
use App\Services\Gateways\SonaliPaymentGateway;
use App\Support\Gateway\GatewayVerification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TenantTestCase;

/**
 * SPG directly, on its v2 API (FR-PAY-11).
 *
 * THESE TESTS PIN THE WIRE FORMAT TO THE LEGACY CALL, and that is the whole
 * point of them. The first version of this adapter was written from
 * imagination: it sent `{UserName, Password}` to `GetAccessToken`, a flat body
 * to `CreatePaymentRequest`, `{AccessToken, SessionToken}` to verification, and
 * matched the string "success" for a status SPG reports as the number 200. It
 * would have failed on the first call, and reported every real payment as
 * pending forever if it had not.
 *
 * None of that was catchable by a test against `FakePaymentGateway`, because the
 * fake answers the shape we wrote. The only reference that has ever taken money
 * is `PaymentController` in the legacy repository, so every assertion below is
 * checked against that file rather than against what looks reasonable.
 *
 * If SPG changes its contract these tests will be wrong, and that is correct:
 * they should then fail loudly rather than let a rewrite drift back to a shape
 * nobody has run.
 */
class SonaliPaymentGatewayTest extends TenantTestCase
{
    private const CREDENTIALS = [
        'api_base_url' => 'https://spg.sblesheba.com:6314',
        'redirect_base_url' => 'https://spg.sblesheba.com:6313',
        'username' => 'merchant-user',
        'password' => 'merchant-secret',
        'ar_account' => '4446102001029',
        'basic_auth' => 'Basic bWVyY2hhbnQ6c2VjcmV0',
        'callback_username' => 'callback-user',
        'callback_password' => 'callback-secret',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->inTenant(fn () => GatewayCredential::create([
            'provider' => SonaliPaymentGateway::PROVIDER,
            'credentials' => self::CREDENTIALS,
            'is_active' => true,
        ]));
    }

    private function gateway(): SonaliPaymentGateway
    {
        return app(SonaliPaymentGateway::class);
    }

    private function payment(): PaymentInfo
    {
        return $this->inTenant(function () {
            $member = Member::create([
                'name' => 'Rokeya Begum',
                'mobile' => '01700000001',
                'status' => 'active',
            ]);

            return PaymentInfo::create([
                'invoice_no' => 'INV-2026-0001',
                'member_id' => $member->id,
                'payable_amount' => '900.00',
                'fine_amount' => '100.00',
                'total_amount' => '1000.00',
                'status' => PaymentInfo::STATUS_PENDING,
                'payment_type' => PaymentInfo::TYPE_ONLINE,
            ]);
        });
    }

    private function fakeHappyPath(): void
    {
        Http::fake([
            '*/GetAccessToken' => Http::response(['access_token' => 'AT-123']),
            '*/CreatePaymentRequest' => Http::response(['session_token' => 'ST-456']),
        ]);
    }

    // ------------------------------------------------------- the token call

    /**
     * The token is issued FOR an invoice, not for the merchant.
     *
     * The first version sent only a username and password, as though this were
     * a login. SPG wants the invoice, the amount and the credit account in the
     * same call, and the token it returns is scoped to that payment.
     */
    public function test_the_access_token_request_carries_the_invoice(): void
    {
        $this->fakeHappyPath();

        $this->inTenant(fn () => $this->gateway()->createSession($this->payment(), 'https://bcs.test/return'));

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'GetAccessToken')) {
                return false;
            }

            $body = $request->data();

            return $body['AccessUser']['userName'] === 'merchant-user'
                && $body['AccessUser']['password'] === 'merchant-secret'
                && $body['invoiceNo'] === 'INV-2026-0001'
                && $body['amount'] === '1000.00'
                && $body['accounts'][0]['crAccount'] === '4446102001029'

                // A JSON number here; a string in creditInformations. The
                // asymmetry is in the call that works, so it is reproduced.
                && $body['accounts'][0]['crAmount'] === 1000.0;
        });
    }

    public function test_every_call_carries_the_configured_authorization_header(): void
    {
        $this->fakeHappyPath();

        $this->inTenant(fn () => $this->gateway()->createSession($this->payment(), 'https://bcs.test/return'));

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', self::CREDENTIALS['basic_auth']));
    }

    // ----------------------------------------------------- the session call

    /**
     * Nested, and spelled SPG's way.
     *
     * `applicentName` is wrong English and the right key. Renaming it to
     * `applicantName` because it looks like a typo is how this call starts
     * failing against a server nobody can debug from here.
     */
    public function test_the_session_request_matches_the_shape_that_works(): void
    {
        $this->fakeHappyPath();
        $payment = $this->payment();

        $this->inTenant(fn () => $this->gateway()->createSession($payment, 'https://bcs.test/return'));

        Http::assertSent(function ($request) use ($payment) {
            if (! str_contains($request->url(), 'CreatePaymentRequest')) {
                return false;
            }

            $body = $request->data();

            return $body['authentication']['apiAccessUserId'] === 'merchant-user'
                && $body['authentication']['apiAccessToken'] === 'AT-123'
                && $body['referenceInfo']['InvoiceNo'] === 'INV-2026-0001'
                && $body['referenceInfo']['returnUrl'] === 'https://bcs.test/return'

                // Instalment PLUS fine: the member pays a total, and the split
                // stays on our side (ADR-0005).
                && $body['referenceInfo']['totalAmount'] === '1000.00'

                && $body['referenceInfo']['applicentName'] === 'Rokeya Begum'
                && $body['referenceInfo']['applicentContactNo'] === '01700000001'
                && $body['referenceInfo']['extraRefNo'] === (string) $payment->id

                && $body['creditInformations'][0]['slno'] === '1'
                && $body['creditInformations'][0]['crAccount'] === '4446102001029'
                && $body['creditInformations'][0]['crAmount'] === '1000.00'
                && $body['creditInformations'][0]['tranMode'] === 'TRN';
        });
    }

    public function test_the_member_is_sent_to_the_landing_page_for_the_session_token(): void
    {
        $this->fakeHappyPath();

        $session = $this->inTenant(
            fn () => $this->gateway()->createSession($this->payment(), 'https://bcs.test/return')
        );

        $this->assertSame('ST-456', $session->reference);
        $this->assertSame(
            'https://spg.sblesheba.com:6313/SpgLanding/SpgLanding/ST-456',
            $session->url,
        );
    }

    public function test_a_refused_session_throws_rather_than_returning_a_broken_one(): void
    {
        Http::fake([
            '*/GetAccessToken' => Http::response(['access_token' => 'AT-123']),
            '*/CreatePaymentRequest' => Http::response(['message' => 'Invalid AR account'], 400),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid AR account');

        $this->inTenant(fn () => $this->gateway()->createSession($this->payment(), 'https://bcs.test/return'));
    }

    // ------------------------------------------------------- verification

    private function verifyReturns(array $body, int $status = 200): GatewayVerification
    {
        Http::fake(['*/TransactionVerificationWithToken' => Http::response($body, $status)]);

        return $this->inTenant(fn () => $this->gateway()->verify('ST-456'));
    }

    /**
     * `session_Token`, with that capital T. It is SPG's key, not a typo here.
     *
     * And no access token: this endpoint authenticates on the Authorization
     * header alone. The first version sent both, under different names.
     */
    public function test_verification_sends_only_the_session_token(): void
    {
        $this->verifyReturns(['PaymentStatus' => 200]);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return ($body['session_Token'] ?? null) === 'ST-456'
                && ! array_key_exists('AccessToken', $body)
                && ! array_key_exists('SessionToken', $body);
        });
    }

    /**
     * 200 IS THE NUMBER, not the word "success".
     *
     * The first version matched strings SPG never sends, so every real payment
     * would have read as pending forever — the member charged, the instalment
     * held, and nothing reporting a fault.
     */
    public function test_payment_status_200_is_paid(): void
    {
        $result = $this->verifyReturns([
            'PaymentStatus' => 200,
            'TransactionId' => 'TXN-9001',
            'PayAmount' => 1015.75,
            'TotalAmount' => 1000,
        ]);

        $this->assertTrue($result->isPaid());
        $this->assertSame('TXN-9001', $result->transactionId);

        // What SPG collected, including its vat and commission. Recorded for
        // reconciliation and never copied into payable_amount (D-1).
        $this->assertSame('1015.75', $result->amount);
    }

    public function test_an_unrecognised_status_is_pending_not_failed(): void
    {
        $result = $this->verifyReturns(['PaymentStatus' => 999]);

        $this->assertSame(GatewayVerification::PENDING, $result->status);
        $this->assertFalse($result->isFinalFailure());
    }

    /**
     * An endpoint that will not answer says nothing about whether they paid.
     *
     * Treating it as failure releases the member's instalments underneath a
     * payment that may well have completed.
     */
    public function test_an_unreachable_endpoint_is_pending(): void
    {
        $result = $this->verifyReturns(['error' => 'gateway down'], 502);

        $this->assertSame(GatewayVerification::PENDING, $result->status);
    }

    // --------------------------------------------------------- the callback

    /**
     * SPG does not sign callbacks — it posts credentials in the body.
     *
     * So the "signature" is that block, checked against what the association
     * configured. The legacy endpoint compares them to hardcoded literals and
     * accepts anything else that guesses the URL (D-16).
     */
    public function test_a_callback_is_accepted_only_with_the_configured_credentials(): void
    {
        $good = json_encode(['Credentials' => ['userName' => 'callback-user', 'password' => 'callback-secret']]);
        $bad = json_encode(['Credentials' => ['userName' => 'callback-user', 'password' => 'wrong']]);

        $this->inTenant(function () use ($good, $bad) {
            $this->assertTrue($this->gateway()->verifySignature($good, null));
            $this->assertFalse($this->gateway()->verifySignature($bad, null));
            $this->assertFalse($this->gateway()->verifySignature('{}', null));
        });
    }

    /** No credentials configured means no callback is ever believed. */
    public function test_an_association_without_callback_credentials_fails_closed(): void
    {
        $this->inTenant(function () {
            DB::table('gateway_credentials')->delete();

            GatewayCredential::create([
                'provider' => SonaliPaymentGateway::PROVIDER,
                'credentials' => ['api_base_url' => 'https://spg.test'],
                'is_active' => true,
            ]);

            $payload = json_encode(['Credentials' => ['userName' => 'x', 'password' => 'y']]);

            $this->assertFalse($this->gateway()->verifySignature($payload, null));
        });
    }
}
