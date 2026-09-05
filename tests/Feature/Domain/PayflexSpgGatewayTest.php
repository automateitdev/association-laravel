<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Models\Tenant\GatewayCredential;
use App\Models\Tenant\Member;
use App\Models\Tenant\PaymentInfo;
use App\Services\Gateways\PayflexSpgGateway;
use App\Support\Gateway\GatewayVerification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TenantTestCase;

/**
 * SPG through the PayFlex middleware (FR-PAY-11).
 *
 * WHAT IS WORTH TESTING is not that an HTTP call is made. It is the handful of
 * ways this integration could be wrong in a manner nobody notices until money
 * has moved:
 *
 *   - the association's own SPG credentials not travelling with the request, so
 *     PayFlex silently falls back to its own merchant account and this
 *     association's money is collected into somebody else's;
 *   - the disbursement not adding up to the invoice;
 *   - a technical error at SPG being read as "the member did not pay", which
 *     releases their assignments while the money is on its way;
 *   - a manual branch payment (5017) being treated as failure for the same
 *     reason;
 *   - the stored reference being one PayFlex cannot be asked about later.
 */
class PayflexSpgGatewayTest extends TenantTestCase
{
    private const CREDENTIALS = [
        'payflex_base_url' => 'https://payflex.test',
        'payflex_username' => 'bcs-client',
        'payflex_password' => 'payflex-secret',
        'spg_user' => 'assoc-merchant',
        'spg_password' => 'spg-secret',
        'ar_account' => '00987654321098',
        'party_name' => 'Demo Association',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->inTenant(fn () => GatewayCredential::create([
            'provider' => PayflexSpgGateway::PROVIDER,
            'credentials' => self::CREDENTIALS,
            'is_active' => true,
        ]));
    }

    private function payment(string $invoice = 'INV-2026-0001'): PaymentInfo
    {
        return $this->inTenant(function () use ($invoice) {
            $member = Member::create([
                'name' => 'Rokeya Begum',
                'mobile' => '01700000001',
                'status' => 'active',
            ]);

            return PaymentInfo::create([
                'invoice_no' => $invoice,
                'member_id' => $member->id,

                // Where the cash lands. Completion refuses to post without one,
                // which is the D-20 guard doing its job.
                'ledger_id' => $this->receivingLedgerId(),
                'payable_amount' => '900.00',
                'fine_amount' => '100.00',
                'total_amount' => '1000.00',
                'status' => PaymentInfo::STATUS_PENDING,
                'payment_type' => PaymentInfo::TYPE_ONLINE,
            ]);
        });
    }

    /** A cash ledger for completion to post into. */
    private function receivingLedgerId(): int
    {
        $existing = DB::table('ledgers')->value('id');

        if ($existing) {
            return (int) $existing;
        }

        $categoryId = DB::table('account_categories')->value('id')
            ?? DB::table('account_categories')->insertGetId([
                'name' => 'Assets', 'type' => 'asset',
                'created_at' => now(), 'updated_at' => now(),
            ]);

        $groupId = DB::table('account_groups')->value('id')
            ?? DB::table('account_groups')->insertGetId([
                'account_category_id' => $categoryId, 'name' => 'Cash and Bank',
                'created_at' => now(), 'updated_at' => now(),
            ]);

        return (int) DB::table('ledgers')->insertGetId([
            'account_group_id' => $groupId,
            'name' => 'Cash in Hand',
            'opening_balance' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function gateway(): PayflexSpgGateway
    {
        return app(PayflexSpgGateway::class);
    }

    // ------------------------------------------------------------- the session

    /**
     * THE ONE THAT MATTERS MOST.
     *
     * PayFlex falls back to its OWN configured SPG merchant account when
     * `spg_user`/`spg_password` are absent. An association whose request forgot
     * them would have its members' money collected into somebody else's
     * account, and nothing on either side would report an error.
     */
    public function test_the_associations_own_merchant_credentials_travel_with_the_request(): void
    {
        Http::fake(['payflex.test/api/payment/init' => Http::response([
            'token' => 'tok-abc', 'RedirectToGateway' => 'https://spg.test/pay/tok-abc',
        ])]);

        $this->inTenant(fn () => $this->gateway()->createSession($this->payment(), 'https://demo.bcsapp.test/return'));

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $body['spg_user'] === 'assoc-merchant'
                && $body['spg_password'] === 'spg-secret'
                && $body['pay_method'] === 'SPG';
        });
    }

    public function test_the_request_is_authenticated_as_our_client_domain(): void
    {
        Http::fake(['payflex.test/*' => Http::response([
            'token' => 'tok-abc', 'RedirectToGateway' => 'https://spg.test/pay/tok-abc',
        ])]);

        $this->inTenant(fn () => $this->gateway()->createSession($this->payment(), 'https://demo.bcsapp.test/return'));

        Http::assertSent(fn ($request) => $request->hasHeader(
            'Authorization',
            'Basic '.base64_encode('bcs-client:payflex-secret')
        ));
    }

    /**
     * The disbursement must reconcile to the invoice exactly, or SPG rejects it.
     *
     * One entry for now — see the note in the adapter on why splitting waits.
     * What is asserted here is the total, which is the part a later split must
     * not break.
     */
    public function test_the_disbursement_carries_the_whole_invoice_to_the_ar_account(): void
    {
        Http::fake(['payflex.test/*' => Http::response([
            'token' => 'tok-abc', 'RedirectToGateway' => 'https://spg.test/pay/tok-abc',
        ])]);

        $this->inTenant(fn () => $this->gateway()->createSession($this->payment(), 'https://demo.bcsapp.test/return'));

        Http::assertSent(function ($request) {
            $body = $request->data();
            $credited = array_sum(array_column($body['disbursement'], 'crAmount'));

            return $body['amount'] === 1000.0
                // Instalment PLUS fine: the member pays a total, and the split
                // stays on our side (ADR-0005).
                && $credited === 1000.0
                && $body['disbursement'][0]['crAccount'] === '00987654321098';
        });
    }

    /**
     * The reference has to be something PayFlex can be asked about later.
     *
     * `gateway_reference` is what `verify()` gets handed — by the callback and
     * by the reconciliation sweep — and PayFlex's client verification is keyed
     * on the invoice, not on the token it returns.
     */
    public function test_the_session_is_referenced_by_invoice_not_token(): void
    {
        Http::fake(['payflex.test/*' => Http::response([
            'token' => 'tok-abc', 'RedirectToGateway' => 'https://spg.test/pay/tok-abc',
        ])]);

        $session = $this->inTenant(
            fn () => $this->gateway()->createSession($this->payment('INV-2026-0042'), 'https://demo.bcsapp.test/return')
        );

        $this->assertSame('INV-2026-0042', $session->reference);
        $this->assertSame('https://spg.test/pay/tok-abc', $session->url);
    }

    public function test_a_refused_initiation_throws_rather_than_returning_a_broken_session(): void
    {
        Http::fake(['payflex.test/*' => Http::response(['message' => 'Invalid spg crAccount at index 0.'], 400)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid spg crAccount');

        $this->inTenant(fn () => $this->gateway()->createSession($this->payment(), 'https://demo.bcsapp.test/return'));
    }

    public function test_an_association_with_no_payflex_configuration_cannot_take_payment(): void
    {
        $this->inTenant(fn () => GatewayCredential::query()->delete());

        $this->expectException(RuntimeException::class);

        $this->inTenant(fn () => $this->gateway()->createSession($this->payment(), 'https://demo.bcsapp.test/return'));
    }

    // ----------------------------------------------------------- verification

    private function verifyReturns(string $status): GatewayVerification
    {
        Http::fake(['payflex.test/api/spg/invoice-verify*' => Http::response([
            'state' => 'payment_verify',
            'message' => 'Information updated successfully.',
            'data' => [
                'Status' => $status,
                'TransactionId' => '2508159000167167',
                'InvoiceNo' => 'INV-2026-0001',
                'CustomerPaidAmount' => 1005.75,
                'RequestTotalAmount' => 1000,
            ],
        ])]);

        return $this->inTenant(fn () => $this->gateway()->verify('INV-2026-0001'));
    }

    public function test_a_successful_verification_is_paid(): void
    {
        $result = $this->verifyReturns('200');

        $this->assertTrue($result->isPaid());
        $this->assertSame('2508159000167167', $result->transactionId);

        // What SPG collected, including its own charges. Recorded for
        // reconciliation and never copied into payable_amount (D-1).
        $this->assertSame('1005.75', $result->amount);
    }

    public function test_a_cancelled_payment_is_cancelled_not_failed(): void
    {
        $this->assertSame(GatewayVerification::CANCELLED, $this->verifyReturns('401')->status);
    }

    /**
     * A manual branch payment is PENDING, sometimes for days.
     *
     * Treating 5017 as failure would release the member's assignments back to
     * Unpaid while their money is genuinely on its way to the bank.
     */
    public function test_a_manual_branch_payment_is_pending(): void
    {
        $result = $this->verifyReturns('5017');

        $this->assertSame(GatewayVerification::PENDING, $result->status);
        $this->assertFalse($result->isFinalFailure());
    }

    /**
     * A technical error at SPG says nothing about whether the member paid.
     *
     * Nor does a code this build has never seen. Both are a reason to look
     * again, not a reason to decide.
     */
    public function test_a_technical_error_and_an_unknown_code_are_pending(): void
    {
        $this->assertFalse($this->verifyReturns('700')->isFinalFailure());
        $this->assertFalse($this->verifyReturns('9999')->isFinalFailure());
    }

    /**
     * PayFlex answering 404 does not mean the member did not pay.
     *
     * It means PayFlex has never heard of the invoice — a race against its own
     * write, or a request that never arrived. Failing here would release
     * assignments underneath somebody mid-payment.
     */
    public function test_an_unreachable_verification_is_pending_not_failed(): void
    {
        Http::fake(['payflex.test/*' => Http::response(['error' => 'Invalid Invoice'], 404)]);

        $result = $this->inTenant(fn () => $this->gateway()->verify('INV-2026-0001'));

        $this->assertSame(GatewayVerification::PENDING, $result->status);
        $this->assertFalse($result->isFinalFailure());
    }

    // -------------------------------------------------------------- signature

    /**
     * PayFlex signs nothing, and this says so rather than pretending.
     *
     * The callback route does not ask this question — it treats the post as a
     * doorbell and verifies over an authenticated request — but anything that
     * DOES ask must get the honest answer.
     */
    public function test_there_is_no_signature_to_verify(): void
    {
        $this->assertFalse($this->gateway()->verifySignature('{"any":"body"}', 'any-signature'));
    }

    // --------------------------------------------------------- the whole path

    /**
     * The doorbell, end to end.
     *
     * The notice carries a wrong amount and a wrong status on purpose: neither
     * is read. Completion comes from the authenticated verification, and the
     * payment must complete on SPG's answer rather than on the body's claim.
     */
    public function test_an_unsigned_notice_completes_the_payment_from_the_verification_not_the_body(): void
    {
        Http::fake([
            'payflex.test/api/payment/init' => Http::response([
                'token' => 'tok-abc', 'RedirectToGateway' => 'https://spg.test/pay/tok-abc',
            ]),
            'payflex.test/api/spg/invoice-verify*' => Http::response([
                'data' => [
                    'Status' => '200',
                    'TransactionId' => 'txn-real',
                    'InvoiceNo' => 'INV-2026-0001',
                    'CustomerPaidAmount' => 1000,
                ],
            ]),
        ]);

        /*
         * The registry defaults to the fake, so this has to opt in - which is
         * the safety default doing its job: an environment nobody has thought
         * about cannot collect money, and a test that forgets this gets the
         * fake rather than silently exercising a live adapter.
         */
        config(['services.gateway.driver' => 'auto']);

        $payment = $this->payment();

        $this->inTenant(function () use ($payment) {
            $session = $this->gateway()->createSession($payment, 'https://demo.bcsapp.test/return');
            $payment->update(['gateway_reference' => $session->reference]);

            app(\App\Services\GatewayService::class)->handleUnsignedNotice(
                $session->reference,
                // Deliberately lying: a smaller amount and a failed status.
                ['data' => ['InvoiceNo' => 'INV-2026-0001', 'Status' => '400', 'CustomerPaidAmount' => 1]],
            );
        });

        $this->inTenant(function () use ($payment) {
            $fresh = PaymentInfo::find($payment->id);

            $this->assertSame(PaymentInfo::STATUS_COMPLETED, $fresh->status);

            // The gateway's figure, not the body's.
            $this->assertSame(0, bccomp((string) $fresh->gateway_amount, '1000', 2));
        });
    }

    /** A notice for an invoice we never issued is recorded and refused. */
    public function test_a_notice_for_an_unknown_invoice_is_refused(): void
    {
        config(['services.gateway.driver' => 'auto']);

        $this->expectException(\DomainException::class);

        $this->inTenant(fn () => app(\App\Services\GatewayService::class)
            ->handleUnsignedNotice('INV-DOES-NOT-EXIST', []));

        $this->assertSame(1, $this->inTenant(fn () => DB::table('gateway_events')->count()));
    }
}
