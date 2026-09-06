<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Tenant\FeeAssign;
use App\Contracts\PaymentGateway;
use Illuminate\Http\Client\ConnectionException;
use App\Models\Tenant\GatewayEvent;
use App\Models\Tenant\LedgerTrace;
use App\Models\Tenant\PaymentInfo;
use App\Services\FeeAssignService;
use App\Services\FineService;
use App\Services\GatewayService;
use App\Services\Gateways\FakePaymentGateway;
use App\Services\PaymentService;
use App\Services\TenantSeedService;
use App\Support\Gateway\GatewayVerification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\TenantFixtures;
use Tests\TenantTestCase;

/**
 * T-7 and T-8 from bcs-docs/08-testing-strategy.md.
 *
 * These are the tests that make the mobile payment flow safe. The legacy design
 * keeps the pending payment in the PHP session and only writes the invoice when
 * the member comes back (D-9) - on the web that is uncommon, on a phone the OS
 * routinely kills a backgrounded app mid-payment and it becomes routine.
 */
class GatewayTest extends TenantTestCase
{
    use TenantFixtures;

    private function headers(?string $token = null): array
    {
        return array_filter([
            'X-Tenant' => $this->slug(),
            'Accept' => 'application/json',
            'Authorization' => $token ? "Bearer {$token}" : null,
        ]);
    }

    /**
     * @return array{0: \App\Models\Tenant\Member, 1: string, 2: FeeAssign}
     */
    private function memberWithDues(): array
    {
        return $this->inTenant(function () {
            app(TenantSeedService::class)->seedAll();

            $ledgers = $this->makeLedgers();
            $setup = $this->makeFeeSetup([
                'ledger_id' => $ledgers['income']->id,
                'fine_ledger_id' => $ledgers['fine']->id,
            ]);

            $member = $this->makeMember(['password' => 'secret']);
            app(FeeAssignService::class)->assign($member->id, $setup, '2026-01');
            app(FineService::class)->accrueAll(CarbonImmutable::create(2026, 2, 10));

            $assign = FeeAssign::where('member_id', $member->id)->firstOrFail();

            $token = $member->createToken('test', [
                'member.payments.view', 'member.payments.create',
            ])->plainTextToken;

            return [$member, $token, $assign];
        });
    }

    private function createOnlinePayment(string $token, int $assignId): array
    {
        $payment = $this->withHeaders($this->headers($token) + ['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/payments', [
                'fee_assign_ids' => [$assignId],
                'payment_type' => 'online',
            ])
            ->assertStatus(201)
            ->json('data');

        $session = $this->withHeaders($this->headers($token))
            ->postJson("/api/v1/payments/{$payment['id']}/gateway-session")
            ->assertOk()
            ->json('data');

        return [$payment, $session];
    }

    // ---- when the gateway cannot be reached ------------------------------

    /**
     * A gateway that will not answer must not read as "something went wrong".
     *
     * This is the failure a member actually met. The session call threw a
     * connection exception, it left the controller as a 500, and the app - which
     * only has a generic line for a response carrying no envelope - told them
     * "Something went wrong. Please try again." about their money.
     *
     * Worse, the payment screen then said "waiting for the bank to confirm"
     * about a payment the bank had never been sent. A member reads that, waits,
     * and eventually pays twice.
     */
    public function test_an_unreachable_gateway_says_so_and_says_nothing_was_charged(): void
    {
        [$member, $token, $assign] = $this->memberWithDues();

        $payment = $this->withHeaders($this->headers($token) + ['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/payments', ['fee_assign_ids' => [$assign->id], 'payment_type' => 'online'])
            ->assertStatus(201)
            ->json('data');

        $this->mock(PaymentGateway::class, function ($mock) {
            $mock->shouldReceive('createSession')
                ->andThrow(new ConnectionException('cURL error 77: error setting certificate file'));
        });

        $response = $this->withHeaders($this->headers($token))
            ->postJson("/api/v1/payments/{$payment['id']}/gateway-session")
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'GATEWAY_UNREACHABLE');

        // The member is told the one thing that matters about their money.
        $this->assertStringContainsString('Nothing has been charged', $response->json('error.message'));

        // And the cURL detail - a fact about OUR server - is not handed to them.
        $this->assertStringNotContainsString('certificate', $response->json('error.message'));
    }

    /**
     * A payment that never reached the gateway must be distinguishable.
     *
     * `gateway_started` is what lets the app stop claiming a bank is confirming
     * something it was never sent. A boolean rather than the reference itself:
     * a gateway session token is no use to a client.
     */
    public function test_a_payment_reports_whether_it_ever_reached_the_gateway(): void
    {
        [$member, $token, $assign] = $this->memberWithDues();

        $payment = $this->withHeaders($this->headers($token) + ['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/payments', ['fee_assign_ids' => [$assign->id], 'payment_type' => 'online'])
            ->json('data');

        $this->withHeaders($this->headers($token))
            ->getJson("/api/v1/payments/{$payment['id']}")
            ->assertOk()
            ->assertJsonPath('data.gateway_started', false);

        $this->withHeaders($this->headers($token))
            ->postJson("/api/v1/payments/{$payment['id']}/gateway-session")
            ->assertOk();

        $this->withHeaders($this->headers($token))
            ->getJson("/api/v1/payments/{$payment['id']}")
            ->assertOk()
            ->assertJsonPath('data.gateway_started', true)

            // The reference itself stays on our side.
            ->assertJsonMissingPath('data.gateway_reference');
    }

    /**
     * A gateway that answered and refused is a different fact from one that
     * could not be reached, and the member is told a different thing: tell your
     * association, rather than try again in a moment.
     */
    public function test_a_refused_session_is_reported_separately_from_an_unreachable_one(): void
    {
        [$member, $token, $assign] = $this->memberWithDues();

        $payment = $this->withHeaders($this->headers($token) + ['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/payments', ['fee_assign_ids' => [$assign->id], 'payment_type' => 'online'])
            ->json('data');

        $this->mock(PaymentGateway::class, function ($mock) {
            $mock->shouldReceive('createSession')
                ->andThrow(new RuntimeException('SPG refused: invalid AR account 000123'));
        });

        $response = $this->withHeaders($this->headers($token))
            ->postJson("/api/v1/payments/{$payment['id']}/gateway-session")
            ->assertStatus(502)
            ->assertJsonPath('error.code', 'GATEWAY_SESSION_REFUSED');

        // The bank's own words are for the log, not for a member: they are
        // written for whoever integrated the gateway and carry raw responses.
        $this->assertStringNotContainsString('AR account', $response->json('error.message'));
        $this->assertStringContainsString('Nothing has been charged', $response->json('error.message'));
    }

    // ---- session ---------------------------------------------------------

    /**
     * FR-PAY-7: the payment row exists BEFORE the member is sent anywhere.
     */
    public function test_the_payment_row_exists_before_the_member_is_redirected(): void
    {
        [$member, $token, $assign] = $this->memberWithDues();

        [$payment, $session] = $this->createOnlinePayment($token, $assign->id);

        $this->assertNotEmpty($session['url']);
        $this->assertNotEmpty($session['reference']);

        $this->inTenant(function () use ($payment, $session) {
            $record = PaymentInfo::find($payment['id']);

            $this->assertSame(PaymentInfo::STATUS_PENDING, $record->status);
            $this->assertSame(
                $session['reference'],
                $record->gateway_reference,
                'The reference must be stored before redirect, or a callback has nothing to match.'
            );
        });
    }

    public function test_a_member_cannot_start_a_session_for_someone_elses_payment(): void
    {
        [$owner, $ownerToken, $assign] = $this->memberWithDues();
        [$payment] = $this->createOnlinePayment($ownerToken, $assign->id);

        $strangerToken = $this->inTenant(fn () => $this->makeMember(['password' => 'x'])
            ->createToken('test', ['member.payments.create'])->plainTextToken);

        $this->withHeaders($this->headers($strangerToken))
            ->postJson("/api/v1/payments/{$payment['id']}/gateway-session")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'NOT_OWNER');
    }

    // ---- T-7: the webhook is authoritative -------------------------------

    /**
     * T-7. The member's app is gone; the payment completes anyway.
     *
     * Nothing in this test simulates the app coming back. The callback alone
     * settles the assignment, posts the ledger and mints shares.
     */
    public function test_a_webhook_completes_the_payment_even_though_the_app_never_returns(): void
    {
        [$member, $token, $assign] = $this->memberWithDues();
        [$payment, $session] = $this->createOnlinePayment($token, $assign->id);

        $this->postJson(
            "/api/v1/webhooks/".$this->slug()."/gateway",
            ['reference' => $session['reference'], 'status' => 'paid'],
            ['X-Gateway-Signature' => 'valid-signature'],
        )->assertOk()->assertJsonPath('data.outcome', 'completed');

        $this->inTenant(function () use ($payment, $assign) {
            $record = PaymentInfo::find($payment['id']);

            $this->assertSame(PaymentInfo::STATUS_COMPLETED, $record->status);
            $this->assertSame(FeeAssign::STATUS_PAID, FeeAssign::find($assign->id)->status);

            // The ledger posted, and it balances.
            $this->assertGreaterThan(0, LedgerTrace::count());
            $this->assertSame((float) LedgerTrace::sum('debit'), (float) LedgerTrace::sum('credit'));
        });
    }

    /**
     * D-1, at the gateway boundary. The gateway collected 1200.00 - instalment
     * plus fine - and that figure must NOT become payable_amount.
     */
    public function test_the_gateway_amount_is_recorded_but_never_becomes_payable_amount(): void
    {
        [$member, $token, $assign] = $this->memberWithDues();
        [$payment, $session] = $this->createOnlinePayment($token, $assign->id);

        app(FakePaymentGateway::class)->willReport(
            $session['reference'],
            GatewayVerification::PAID,
            amount: '1200.00',
        );

        $this->postJson(
            "/api/v1/webhooks/".$this->slug()."/gateway",
            ['reference' => $session['reference']],
            ['X-Gateway-Signature' => 'valid-signature'],
        )->assertOk();

        $this->inTenant(function () use ($payment) {
            $record = PaymentInfo::find($payment['id']);

            $this->assertSame('1200.00', $record->gateway_amount, 'Recorded for reconciliation.');
            $this->assertSame('1000.00', $record->payable_amount, 'Instalments only, still.');
            $this->assertSame('200.00', $record->fine_amount);
        });
    }

    // ---- T-8: replay ------------------------------------------------------

    /**
     * T-8. Gateways retry. A redelivered callback must change nothing.
     */
    public function test_a_replayed_webhook_posts_the_ledger_only_once(): void
    {
        [$member, $token, $assign] = $this->memberWithDues();
        [$payment, $session] = $this->createOnlinePayment($token, $assign->id);

        $call = fn () => $this->postJson(
            "/api/v1/webhooks/".$this->slug()."/gateway",
            ['reference' => $session['reference']],
            ['X-Gateway-Signature' => 'valid-signature'],
        );

        $call()->assertOk();
        $tracesAfterFirst = $this->inTenant(fn () => LedgerTrace::count());

        $call()->assertOk();
        $call()->assertOk();

        $tracesAfterThree = $this->inTenant(fn () => LedgerTrace::count());

        $this->assertSame(
            $tracesAfterFirst,
            $tracesAfterThree,
            'Redelivery must not post the ledger again (D-6).'
        );

        // Every delivery is still recorded, even the no-op ones.
        $this->inTenant(fn () => $this->assertSame(3, GatewayEvent::count()));
    }

    // ---- authenticity -----------------------------------------------------

    /**
     * The legacy callback routes accept anything (D-16).
     */
    public function test_a_webhook_without_a_valid_signature_changes_nothing(): void
    {
        [$member, $token, $assign] = $this->memberWithDues();
        [$payment, $session] = $this->createOnlinePayment($token, $assign->id);

        $this->postJson(
            "/api/v1/webhooks/".$this->slug()."/gateway",
            ['reference' => $session['reference']],
            ['X-Gateway-Signature' => 'forged'],
        )->assertOk()->assertJsonPath('data.outcome', 'refused');

        $this->inTenant(function () use ($payment) {
            $this->assertSame(PaymentInfo::STATUS_PENDING, PaymentInfo::find($payment['id'])->status);

            // Recorded anyway - a forged callback is worth a record.
            $this->assertSame(1, GatewayEvent::count());
            $this->assertNotNull(GatewayEvent::first()->processing_error);
        });
    }

    public function test_a_webhook_for_an_unknown_reference_is_refused_and_recorded(): void
    {
        $this->inTenant(fn () => app(TenantSeedService::class)->seedAll());

        $this->postJson(
            "/api/v1/webhooks/".$this->slug()."/gateway",
            ['reference' => 'NOT-A-REAL-REFERENCE'],
            ['X-Gateway-Signature' => 'valid-signature'],
        )->assertOk()->assertJsonPath('data.outcome', 'refused');

        $this->inTenant(function () {
            $this->assertSame(1, GatewayEvent::count());
            $this->assertStringContainsString('No payment matches', GatewayEvent::first()->processing_error);
        });
    }

    /**
     * A refused callback still answers 200, deliberately: gateways retry
     * non-2xx, and a retry storm against a payload we have already stored and
     * refused helps nobody. The refusal is visible in gateway_events.
     */
    public function test_a_refused_webhook_still_answers_200_to_stop_retry_storms(): void
    {
        $this->inTenant(fn () => app(TenantSeedService::class)->seedAll());

        $this->postJson(
            "/api/v1/webhooks/".$this->slug()."/gateway",
            ['reference' => 'UNKNOWN'],
            ['X-Gateway-Signature' => 'forged'],
        )->assertStatus(200);
    }

    // ---- the R-5 fallback -------------------------------------------------

    /**
     * If the payment gateway cannot deliver server-to-server callbacks for this merchant
     * account (open risk R-5), reconciliation becomes the PRIMARY completion
     * path rather than a safety net. Proving it works now means that answer
     * changes a trigger, not a design.
     */
    public function test_reconciliation_completes_a_payment_with_no_webhook_at_all(): void
    {
        [$member, $token, $assign] = $this->memberWithDues();
        [$payment, $session] = $this->createOnlinePayment($token, $assign->id);

        // No callback is ever delivered.
        $summary = $this->inTenant(fn () => app(GatewayService::class)->reconcilePending());

        $this->assertSame(1, $summary['checked']);
        $this->assertSame(1, $summary['completed']);

        $this->inTenant(function () use ($payment, $assign) {
            $this->assertSame(PaymentInfo::STATUS_COMPLETED, PaymentInfo::find($payment['id'])->status);
            $this->assertSame(FeeAssign::STATUS_PAID, FeeAssign::find($assign->id)->status);
        });
    }

    /**
     * The command exists, and reaches the association.
     *
     * `reconcilePending()` had tests from the day it was written and NO WAY TO
     * RUN IT — nothing scheduled it and no command invoked it — so ADR-0007's
     * promise that the design "degrades to slower completion, not incorrect
     * completion" was not actually kept. A payment whose callback never arrived
     * stayed pending forever.
     *
     * Tested through the command rather than the service so the fan-out is
     * covered too: the service is per-tenant, and a command that forgot to
     * enter the tenant would pass every service test while reconciling nothing.
     */
    public function test_the_reconcile_command_completes_a_pending_payment(): void
    {
        [$member, $token, $assign] = $this->memberWithDues();
        [$payment] = $this->createOnlinePayment($token, $assign->id);

        $this->artisan('payments:reconcile', ['--tenant' => $this->slug()])
            ->assertSuccessful();

        $this->inTenant(function () use ($payment) {
            $this->assertSame(PaymentInfo::STATUS_COMPLETED, PaymentInfo::find($payment['id'])->status);
        });
    }

    /**
     * A terminal failure at the gateway releases the member's instalments
     * rather than stranding them in Requested (D-18).
     */
    public function test_a_failed_payment_releases_the_instalment(): void
    {
        [$member, $token, $assign] = $this->memberWithDues();
        [$payment, $session] = $this->createOnlinePayment($token, $assign->id);

        app(FakePaymentGateway::class)->willReport($session['reference'], GatewayVerification::FAILED);

        $this->postJson(
            "/api/v1/webhooks/".$this->slug()."/gateway",
            ['reference' => $session['reference']],
            ['X-Gateway-Signature' => 'valid-signature'],
        )->assertOk()->assertJsonPath('data.outcome', 'released');

        $this->inTenant(function () use ($assign) {
            $this->assertSame(
                FeeAssign::STATUS_UNPAID,
                FeeAssign::find($assign->id)->status,
                'A failed payment must give the instalment back.'
            );
        });
    }
}
