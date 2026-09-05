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
 * Sonali Payment Gateway, reached through the PayFlex middleware (FR-PAY-11).
 *
 * THE SECOND ROUTE TO THE SAME BANK. `SonaliPaymentGateway` talks to SPG
 * directly on its **v2** API; this talks to PayFlex, which talks to SPG's **v3**
 * API on our behalf. Both are "SPG" to an association, and which one an
 * association uses is a per-association setting, not a deployment-wide one - so
 * one association can be moved onto PayFlex and proved before any other is.
 *
 * (The two are sometimes called "version 1" and "version 2" in conversation.
 * They are not: both the legacy system and the direct adapter call SPG v2, and
 * PayFlex calls v3. The provider keys say `spg` and `payflex_spg` so that a
 * reader a year from now is not misled by a version number that was never SPG's.)
 *
 * WHAT PAYFLEX IS FOR. It fronts several gateways - SSLCommerz, bKash, UCB,
 * Upay - behind one request shape, and it is where the newer SPG API and its
 * multi-account disbursement live. Only SPG is wired up here; the rest are a
 * later decision and deliberately not anticipated in this class.
 *
 * THE CALLBACK IS A DOORBELL, NOT A STATEMENT
 * -------------------------------------------
 * PayFlex posts the verification result to us unsigned - `Http::post($url,
 * $payload)` with nothing to authenticate it. So NOTHING in that body is
 * believed. It tells us an invoice is worth asking about, and then we ask
 * PayFlex ourselves over an authenticated request and act only on that answer.
 *
 * That is not a workaround; it is the rule the whole completion design already
 * follows (ADR-0007): the client never reports its own success, because the
 * client can be modified and the money is real. Here the "client" happens to be
 * a service we wrote, which changes nothing - an unauthenticated POST is an
 * unauthenticated POST, and the legacy system accepting one is defect D-16.
 *
 * CREDENTIALS ARE SENT PER REQUEST. PayFlex will fall back to its own configured
 * SPG merchant account if we omit `spg_user`/`spg_password`, and that fallback
 * is exactly wrong here: each association holds its own merchant account (A-1),
 * so a request that forgot to carry them would quietly collect one
 * association's money into another's. They are therefore always sent, and
 * `credentials()` fails loudly rather than letting the fallback happen.
 */
class PayflexSpgGateway implements PaymentGateway
{
    public const PROVIDER = 'payflex_spg';

    /** PayFlex's own name for the method, from its PAY_METHODS table. */
    private const PAY_METHOD = 'SPG';

    public function name(): string
    {
        return self::PROVIDER;
    }

    public function createSession(PaymentInfo $payment, string $returnUrl): GatewaySession
    {
        $credentials = $this->credentials();

        $response = $this->client($credentials)
            ->post($this->url($credentials, '/api/payment/init'), [
                'pay_method' => self::PAY_METHOD,

                // Always ours, never PayFlex's configured fallback. See above.
                'spg_user' => $credentials->credential('spg_user'),
                'spg_password' => $credentials->credential('spg_password'),

                'invoice' => $payment->invoice_no,
                'invoice_date' => $payment->created_at?->toDateString() ?? now()->toDateString(),

                // What the member actually pays: instalments PLUS fine. The
                // split is preserved on our side; the bank collects a total.
                'amount' => (float) $payment->total_amount,

                'applicantName' => $payment->member->name,
                'applicantContact' => $payment->member->mobile ?? '',

                /*
                 * ONE CREDIT ACCOUNT, carrying the whole invoice.
                 *
                 * PayFlex accepts several and SPG v3 will settle to each, which
                 * is much of the reason the newer API is interesting. It is not
                 * used yet on purpose: splitting means every fee head needs a
                 * credit account number the schema does not have, and the parts
                 * must reconcile to the invoice total exactly or SPG rejects the
                 * request. The array shape is here so that becomes a change to
                 * this method rather than to the design.
                 */
                'disbursement' => [[
                    'crAccount' => $credentials->credential('ar_account'),
                    'crAmount' => (float) $payment->total_amount,
                ]],

                /*
                 * PayFlex strips everything from `/api/` onward and appends its
                 * own path, so only the SCHEME AND HOST of this survive. The
                 * association therefore has to be resolvable from the host -
                 * which `ResolveTenant` already does through the `domains`
                 * table - and cannot travel in the path the way it does for the
                 * direct gateway's webhook.
                 */
                'callback_url' => $returnUrl,

                'partyName' => $credentials->credential('party_name') ?: null,
            ]);

        $token = $response->json('token');
        $url = $response->json('RedirectToGateway');

        if ($response->failed() || ! $token || ! $url) {
            throw new RuntimeException(
                'PayFlex refused to create a session: '
                    .($response->json('message') ?? $response->body())
            );
        }

        /*
         * THE REFERENCE IS THE INVOICE, NOT THE TOKEN.
         *
         * `gateway_reference` is whatever `verify()` will later be handed - by
         * the callback, and by the reconciliation sweep - and PayFlex's
         * client-facing verification is keyed on the invoice. Storing the token
         * would give us the gateway's own id and no way to ask about it.
         *
         * It is the better key anyway: a member who abandoned the hosted page
         * and paid at a branch days later has an invoice, and may never have had
         * a token we saw.
         */
        return new GatewaySession(url: (string) $url, reference: $payment->invoice_no);
    }

    /**
     * Ask PayFlex what actually happened, over an authenticated request.
     *
     * BY INVOICE, NOT BY TOKEN. PayFlex's client-facing verification takes the
     * invoice number, and that is the better key anyway: a member who abandoned
     * the hosted page and came back later has an invoice, and may never have had
     * a token we saw.
     */
    public function verify(string $reference): GatewayVerification
    {
        $credentials = $this->credentials();

        $response = $this->client($credentials)
            ->get($this->url($credentials, '/api/spg/invoice-verify'), ['invoice' => $reference]);

        $body = $response->json() ?? [];
        $data = $body['data'] ?? [];

        if ($response->failed()) {
            /*
             * PENDING, not FAILED. PayFlex answers 404 for an invoice it has
             * never seen and 400 when SPG itself would not answer - neither
             * means the member did not pay, and treating them as failure would
             * release the assignments back to Unpaid underneath somebody who is
             * mid-payment.
             */
            return new GatewayVerification(
                status: GatewayVerification::PENDING,
                reference: $reference,
                raw: is_array($body) ? $body : ['body' => $response->body()],
            );
        }

        return new GatewayVerification(
            status: $this->mapStatus((string) ($data['Status'] ?? '')),
            reference: $reference,

            // Recorded for reconciliation only, never copied into
            // payable_amount. SPG reports what the customer paid including its
            // own charges, which is a different number from what was owed.
            amount: isset($data['CustomerPaidAmount']) ? (string) $data['CustomerPaidAmount'] : null,

            transactionId: $data['TransactionId'] ?? null,
            raw: is_array($body) ? $body : [],
        );
    }

    /**
     * There is no signature, and this says so rather than pretending.
     *
     * PayFlex posts its callback unsigned. Returning true here would let the
     * body be acted on; returning false would make the caller discard a
     * notification that is genuinely useful as a trigger. So the callback route
     * does not ask this question at all - it treats the post as a doorbell and
     * calls `verify()`, which is authenticated - and this returns false so that
     * anything which DOES ask gets the honest answer.
     */
    public function verifySignature(string $payload, ?string $signature): bool
    {
        return false;
    }

    /**
     * SPG's own status codes, from PayFlex's `mapStatusCode`.
     *
     * `5017` is a manual payment - the member took a challan to a branch - and
     * is pending rather than failed, sometimes for days. Mapping it to failure
     * would release their assignments while the money is genuinely on its way.
     */
    private function mapStatus(string $code): string
    {
        return match ($code) {
            '200' => GatewayVerification::PAID,
            '401' => GatewayVerification::CANCELLED,
            '400', '201' => GatewayVerification::FAILED,
            '500', '5017' => GatewayVerification::PENDING,

            // 700, 5555 and anything unrecognised. NOT failure: a technical
            // error at SPG says nothing about whether the member paid, and an
            // unknown code is a reason to look rather than to decide.
            default => GatewayVerification::PENDING,
        };
    }

    private function client(GatewayCredential $credentials): \Illuminate\Http\Client\PendingRequest
    {
        return Http::asJson()
            ->withBasicAuth(
                (string) $credentials->credential('payflex_username'),
                (string) $credentials->credential('payflex_password'),
            )
            ->timeout(30);
    }

    private function url(GatewayCredential $credentials, string $path): string
    {
        return rtrim((string) $credentials->credential('payflex_base_url'), '/').$path;
    }

    private function credentials(): GatewayCredential
    {
        return GatewayCredential::activeFor(self::PROVIDER)
            ?? throw new RuntimeException(
                'This association has no active PayFlex configuration, so no online payment can be taken.'
            );
    }
}
