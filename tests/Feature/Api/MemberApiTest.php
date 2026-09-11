<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Tenant\FeeAssign;
use App\Models\Tenant\FineDate;
use App\Models\Tenant\Member;
use App\Services\FeeAssignService;
use App\Services\FineService;
use App\Services\TenantSeedService;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\TenantFixtures;
use Tests\TenantTestCase;

/**
 * The member API surface.
 *
 * Covers T-6 (a member cannot touch another member's data) and the response
 * rules that encode the instalment/fine separation.
 */
class MemberApiTest extends TenantTestCase
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
     * @return array{0: Member, 1: string, 2: FeeAssign}
     */
    private function memberWithDues(): array
    {
        return $this->inTenant(function () {
            app(TenantSeedService::class)->seedAll();

            $member = $this->makeMember(['password' => 'correct-horse']);
            $feeSetup = $this->makeFeeSetup();

            app(FeeAssignService::class)->assign($member->id, $feeSetup, '2026-01');
            app(FineService::class)->accrueAll(CarbonImmutable::create(2026, 2, 10));

            $assign = FeeAssign::where('member_id', $member->id)->firstOrFail();

            $token = $member->createToken('test', [
                'member.dues.view', 'member.payments.view', 'member.payments.create',
            ])->plainTextToken;

            return [$member, $token, $assign];
        });
    }

    // ---- tenant resolution ---------------------------------------------

    public function test_a_request_without_a_tenant_is_refused(): void
    {
        $this->postJson('/api/v1/auth/login', ['login' => 'x', 'password' => 'y'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'TENANT_NOT_RESOLVED');
    }

    public function test_an_unknown_tenant_is_refused(): void
    {
        $this->withHeaders(['X-Tenant' => 'no-such-assoc'])
            ->postJson('/api/v1/auth/login', ['login' => 'x', 'password' => 'y'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'TENANT_NOT_RESOLVED');
    }

    /** The lookup endpoint must not become a directory of associations. */
    public function test_tenant_lookup_returns_display_information_only(): void
    {
        $this->getJson('/api/v1/tenants/lookup?slug='.$this->slug())
            ->assertOk()
            ->assertJsonPath('data.slug', $this->slug())
            ->assertJsonStructure(['data' => ['slug', 'name', 'locale', 'currency', 'timezone']])
            ->assertJsonMissingPath('data.db_name')
            ->assertJsonMissingPath('data.member_count');
    }

    // ---- authentication -------------------------------------------------

    public function test_a_member_logs_in_with_their_mobile_number(): void
    {
        [$member] = $this->memberWithDues();

        $this->withHeaders($this->headers())
            ->postJson('/api/v1/auth/login', [
                'login' => $member->mobile,
                'password' => 'correct-horse',
            ])
            ->assertOk()
            ->assertJsonPath('data.role', 'member')
            ->assertJsonPath('data.profile.id', $member->id)
            ->assertJsonStructure(['data' => ['token', 'role', 'permissions', 'profile']]);
    }

    /**
     * FR-AUTH-3. Inactive and suspended are entirely different situations for
     * the member, and a shared message sends both of them to the office to ask
     * which. The distinct codes are what let the app explain.
     */
    public function test_an_inactive_member_is_refused_with_its_own_code(): void
    {
        $member = $this->inTenant(fn () => $this->makeMember([
            'password' => 'correct-horse',
            'status' => Member::STATUS_INACTIVE,
        ]));

        $this->withHeaders($this->headers())
            ->postJson('/api/v1/auth/login', [
                'login' => $member->mobile,
                'password' => 'correct-horse',
            ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'MEMBER_INACTIVE');
    }

    public function test_a_suspended_member_is_refused_with_a_different_code(): void
    {
        $member = $this->inTenant(fn () => $this->makeMember([
            'password' => 'correct-horse',
            'status' => Member::STATUS_SUSPENDED,
        ]));

        $this->withHeaders($this->headers())
            ->postJson('/api/v1/auth/login', [
                'login' => $member->mobile,
                'password' => 'correct-horse',
            ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'MEMBER_SUSPENDED');
    }

    public function test_a_wrong_password_is_refused(): void
    {
        [$member] = $this->memberWithDues();

        $this->withHeaders($this->headers())
            ->postJson('/api/v1/auth/login', [
                'login' => $member->mobile,
                'password' => 'wrong',
            ])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
    }

    public function test_an_unauthenticated_request_gets_a_code_the_app_understands(): void
    {
        $this->withHeaders($this->headers())
            ->getJson('/api/v1/me')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'TOKEN_EXPIRED');
    }

    // ---- dues ------------------------------------------------------------

    /**
     * The response rule that encodes the whole fix: instalment and fine are
     * always separate fields, and the total is computed server-side.
     */
    public function test_dues_return_instalment_and_fine_as_separate_fields(): void
    {
        [$member, $token] = $this->memberWithDues();

        $response = $this->withHeaders($this->headers($token))
            ->getJson('/api/v1/fees/dues')
            ->assertOk();

        $response->assertJsonPath('data.0.instalment_amount', '1000.00');
        $response->assertJsonPath('data.0.fine_amount', '200.00');
        $response->assertJsonPath('data.0.total_due', '1200.00');

        $response->assertJsonPath('meta.instalment_total', '1000.00');
        $response->assertJsonPath('meta.fine_total', '200.00');
        $response->assertJsonPath('meta.grand_total', '1200.00');

        // There must be no single merged "amount" field to reach for.
        $response->assertJsonMissingPath('data.0.amount');
    }

    /**
     * The overdue count and the fine must be reports of the SAME state.
     *
     * They travel in one object and a member reads them together, so a count
     * derived from the wall clock while the fine comes from accrual produces a
     * visible contradiction - an instalment badged "1 month late" showing no
     * fine. That window opens every night between a fine date passing and
     * `fines:accrue` running, and never closes if the job fails.
     *
     * Accrual here stops at 2026-02-10, two fine dates in. Wall-clock counting
     * would keep climbing with the calendar and this assertion would drift;
     * counting what accrual applied pins it.
     */
    public function test_the_overdue_count_agrees_with_the_fine_it_is_sent_with(): void
    {
        [$member, $token] = $this->memberWithDues();

        $response = $this->withHeaders($this->headers($token))
            ->getJson('/api/v1/fees/dues')
            ->assertOk();

        $response->assertJsonPath('data.0.fine_amount', '200.00');

        // 200.00 at a rate of 100.00 is two applied fine periods. Anything else
        // is the two figures disagreeing in front of the member.
        $response->assertJsonPath('data.0.overdue_periods', 2);
    }

    /**
     * An instalment inside its grace period carries no fine, so it must not be
     * reported as late at all. This is the case the demo seeder exists to put on
     * screen, and it is the one wall-clock counting got wrong.
     */
    public function test_an_instalment_with_no_fine_is_not_reported_as_late(): void
    {
        [$member, $token] = $this->memberWithDues();

        $this->inTenant(function () use ($member) {
            $assign = FeeAssign::where('member_id', $member->id)->firstOrFail();

            // Roll accrual back to before the first fine date: dates exist and
            // have elapsed by today's calendar, but none has been applied.
            $assign->fineDates()->update(['status' => FineDate::STATUS_INCOMPLETE]);
            $assign->update(['fine_amount' => '0.00']);
        });

        $response = $this->withHeaders($this->headers($token))
            ->getJson('/api/v1/fees/dues')
            ->assertOk();

        $response->assertJsonPath('data.0.fine_amount', '0.00');
        $response->assertJsonPath('data.0.overdue_periods', 0);
    }

    public function test_the_summary_returns_four_numbers_never_one(): void
    {
        [$member, $token] = $this->memberWithDues();

        $this->withHeaders($this->headers($token))
            ->getJson('/api/v1/fees/summary')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'instalments_paid_count',
                    'instalments_paid_amount',
                    'fines_paid_amount',
                    'shares',
                ],
            ]);
    }

    /**
     * The quote endpoint exists so the APP never adds money up.
     *
     * A member must be told what to transfer before the payment is created, and
     * the only alternative was a client-side sum over decimal strings - the
     * arithmetic FR-MON-6 forbids. Instalment and fine come back apart, as
     * everywhere else.
     */
    public function test_a_quote_totals_the_chosen_instalments_server_side(): void
    {
        [$member, $token, $assign] = $this->memberWithDues();

        $this->withHeaders($this->headers($token))
            ->postJson('/api/v1/fees/quote', ['fee_assign_ids' => [$assign->id]])
            ->assertOk()
            ->assertJsonPath('data.instalment_count', 1)
            ->assertJsonPath('data.instalment_total', '1000.00')
            ->assertJsonPath('data.fine_total', '200.00')
            ->assertJsonPath('data.grand_total', '1200.00');
    }

    /** D-5 again, at a new entry point: quoting someone else's dues is refused. */
    public function test_a_member_cannot_quote_another_members_instalments(): void
    {
        [$owner, $ownerToken, $assign] = $this->memberWithDues();

        $strangerToken = $this->inTenant(fn () => $this->makeMember(['password' => 'x'])
            ->createToken('test', ['member.dues.view'])->plainTextToken);

        $this->withHeaders($this->headers($strangerToken))
            ->postJson('/api/v1/fees/quote', ['fee_assign_ids' => [$assign->id]])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'NOT_OWNER');
    }

    // ---- payments --------------------------------------------------------

    /**
     * A manual payment needs a slip, so every one of these sends one.
     *
     * These tests are about idempotency and ownership rather than proof of
     * payment - the slip is fixture, not subject. It became required on
     * 2026-09-12, matching the legacy rule: a member filing a manual payment is
     * asserting that money left their account, and the slip is the only thing
     * an approver has to check that against. See PaymentSlipRequiredTest.
     *
     * @param  array<string, mixed>  $body
     */
    private function createPayment(string $token, array $body, ?string $key = null)
    {
        Storage::fake('local');

        return $this->withHeaders(
            $this->headers($token) + ['Idempotency-Key' => $key ?? (string) Str::uuid()]
        )->post('/api/v1/payments', $body + ['documents' => [UploadedFile::fake()->image('slip.jpg')]]);
    }

    /**
     * The key is checked on a request that is otherwise VALID.
     *
     * Validation runs first, so a body missing the slip as well now fails on
     * that instead - and this test would have been asserting the wrong refusal.
     */
    public function test_creating_a_payment_requires_an_idempotency_key(): void
    {
        Storage::fake('local');

        [$member, $token, $assign] = $this->memberWithDues();

        // Otherwise valid - slip included - so the missing KEY is the only
        // thing left to refuse. Without it this now fails validation first and
        // the test would be asserting the wrong refusal.
        $this->withHeaders($this->headers($token))
            ->post('/api/v1/payments', [
                'fee_assign_ids' => [$assign->id],
                'documents' => [UploadedFile::fake()->image('slip.jpg')],
            ])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REQUIRED');
    }

    /**
     * FR-PAY-6. A retry over a flaky connection is the most likely source of
     * NEW duplicates once an app exists.
     */
    public function test_the_same_idempotency_key_returns_the_original_payment(): void
    {
        [$member, $token, $assign] = $this->memberWithDues();
        $key = (string) Str::uuid();

        $first = $this->createPayment($token, ['fee_assign_ids' => [$assign->id]], $key)
            ->assertStatus(201);

        $second = $this->createPayment($token, ['fee_assign_ids' => [$assign->id]], $key)
            ->assertStatus(200);

        $this->assertSame(
            $first->json('data.id'),
            $second->json('data.id'),
            'A retry must return the original payment, not create a second one.'
        );

        $this->inTenant(function () use ($member) {
            $this->assertSame(1, \App\Models\Tenant\PaymentInfo::where('member_id', $member->id)->count());
        });
    }

    public function test_the_same_key_with_a_different_body_is_a_conflict(): void
    {
        [$member, $token, $assign] = $this->memberWithDues();
        $key = (string) Str::uuid();

        $this->createPayment($token, ['fee_assign_ids' => [$assign->id]], $key)
            ->assertStatus(201);

        $this->createPayment($token, ['fee_assign_ids' => [$assign->id + 999]], $key)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');
    }

    public function test_a_created_payment_keeps_instalment_and_fine_apart(): void
    {
        [$member, $token, $assign] = $this->memberWithDues();

        $this->createPayment($token, ['fee_assign_ids' => [$assign->id]])
            ->assertStatus(201)
            ->assertJsonPath('data.payable_amount', '1000.00')
            ->assertJsonPath('data.fine_amount', '200.00')
            ->assertJsonPath('data.total_amount', '1200.00')
            ->assertJsonPath('data.items.0.instalment_amount', '1000.00')
            ->assertJsonPath('data.items.0.fine_amount', '200.00');
    }

    /**
     * T-6, and the most serious authorisation defect carried over (D-5).
     *
     * The legacy PaymentCreateRequest::authorize() returns true and validates
     * only that the posted fee_assign_id EXISTS - so a member can post another
     * member's ids and pay, or strand, their dues.
     */
    public function test_a_member_cannot_pay_another_members_instalment(): void
    {
        [$owner, $ownerToken, $ownersAssign] = $this->memberWithDues();

        $strangerToken = $this->inTenant(function () {
            $stranger = $this->makeMember(['password' => 'x']);

            return $stranger->createToken('test', ['member.payments.create'])->plainTextToken;
        });

        $this->createPayment($strangerToken, ['fee_assign_ids' => [$ownersAssign->id]])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'NOT_OWNER');

        // And the owner's assignment is untouched - not even moved to Requested.
        $this->inTenant(function () use ($ownersAssign) {
            $this->assertSame(
                FeeAssign::STATUS_UNPAID,
                FeeAssign::find($ownersAssign->id)->status
            );
        });
    }

    public function test_a_member_cannot_read_another_members_payment(): void
    {
        [$owner, $ownerToken, $assign] = $this->memberWithDues();

        $paymentId = $this->createPayment($ownerToken, ['fee_assign_ids' => [$assign->id]])
            ->assertStatus(201)
            ->json('data.id');

        $strangerToken = $this->inTenant(function () {
            $stranger = $this->makeMember(['password' => 'x']);

            return $stranger->createToken('test', ['member.payments.view'])->plainTextToken;
        });

        $this->withHeaders($this->headers($strangerToken))
            ->getJson("/api/v1/payments/{$paymentId}")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'NOT_OWNER');
    }

    /**
     * FR-AUTH-4: abilities are a second barrier. A token issued without the
     * dues ability cannot read dues even though the route exists and the member
     * is genuinely authenticated.
     */
    public function test_a_token_without_the_ability_is_refused(): void
    {
        $token = $this->inTenant(function () {
            $member = $this->makeMember(['password' => 'x']);

            return $member->createToken('narrow', ['member.profile.view'])->plainTextToken;
        });

        $this->withHeaders($this->headers($token))
            ->getJson('/api/v1/fees/dues')
            ->assertStatus(403);
    }

    public function test_logout_revokes_only_the_current_device(): void
    {
        [$member, $token] = $this->memberWithDues();

        $secondToken = $this->inTenant(
            fn () => Member::find($member->id)->createToken('other-device')->plainTextToken
        );

        $this->withHeaders($this->headers($token))
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        $this->withHeaders($this->headers($token))->getJson('/api/v1/me')->assertStatus(401);
        $this->withHeaders($this->headers($secondToken))->getJson('/api/v1/me')->assertOk();
    }
}
