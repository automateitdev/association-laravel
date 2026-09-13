<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Tenant\FeeAssign;
use App\Models\Tenant\Ledger;
use App\Models\Tenant\PaymentInfo;
use App\Models\Tenant\Setting;
use App\Models\User;
use App\Services\FeeAssignService;
use App\Services\TenantSeedService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\Support\TenantFixtures;
use Tests\TenantTestCase;

/**
 * A member pays online unless the association opens the offline route.
 *
 * WHY THE DEFAULT IS THIS WAY ROUND. A member filing an offline payment is
 * asserting that money left their account; somebody at the association then
 * reads a photographed slip and decides whether to believe it. It is the one
 * path where the record is created by the person who benefits from it, so an
 * association opts into it rather than finding the door already open.
 *
 * AND WHY THE APP HIDING THE BUTTON IS NOT ENOUGH. The pay screen reads
 * `manual.available` from GET /fees/payment-instructions and offers what it is
 * told. A stale build still installed on somebody's phone, or anything else
 * holding a member token, would file an offline payment against an association
 * that had switched them off. Same lesson as the required slip: a rule
 * enforced in one client is not a rule.
 */
class MemberOfflinePaymentTest extends TenantTestCase
{
    use TenantFixtures;

    private function headers(string $token): array
    {
        return [
            'X-Tenant' => $this->slug(),
            'Accept' => 'application/json',
            'Authorization' => "Bearer {$token}",
            'Idempotency-Key' => (string) Str::uuid(),
        ];
    }

    /** @return array{0: string, 1: FeeAssign} */
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

            $assign = FeeAssign::where('member_id', $member->id)->firstOrFail();

            $token = $member->createToken('test', [
                'member.payments.view', 'member.payments.create', 'member.dues.view',
            ])->plainTextToken;

            return [$token, $assign];
        });
    }

    private function offline(bool $on): void
    {
        $this->inTenant(fn () => Setting::put(Setting::MEMBER_OFFLINE_PAYMENT_ENABLED, $on));
    }

    private function slip(): UploadedFile
    {
        return UploadedFile::fake()->image('slip.jpg', 400, 600);
    }

    // ---- the refusal ------------------------------------------------------

    public function test_a_member_cannot_file_an_offline_payment_when_it_is_switched_off(): void
    {
        [$token, $assign] = $this->memberWithDues();
        $this->offline(false);

        $this->withHeaders($this->headers($token))
            ->post('/api/v1/payments', [
                'fee_assign_ids' => [$assign->id],
                'payment_type' => 'manual',
                'documents' => [$this->slip()],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'OFFLINE_PAYMENT_DISABLED');

        // Refused, not recorded. A pending row would hold the instalment shut
        // against the member's next attempt, by the right route.
        $this->inTenant(fn () => self::assertSame(0, PaymentInfo::query()->count()));
    }

    /**
     * A SLIP DOES NOT BUY THE ROUTE.
     *
     * The refusal is about who may file the record, not about whether the
     * evidence looks good - so it has to land before the documents rule. A
     * member told "the documents field is required" would go off and photograph
     * a slip that was never going to be accepted.
     */
    public function test_the_refusal_does_not_depend_on_the_slip_being_missing(): void
    {
        [$token, $assign] = $this->memberWithDues();
        $this->offline(false);

        $this->withHeaders($this->headers($token))
            ->postJson('/api/v1/payments', [
                'fee_assign_ids' => [$assign->id],
                'payment_type' => 'manual',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'OFFLINE_PAYMENT_DISABLED');
    }

    // ---- the opening ------------------------------------------------------

    public function test_a_member_can_file_one_once_the_association_turns_it_on(): void
    {
        [$token, $assign] = $this->memberWithDues();
        $this->offline(true);

        $this->withHeaders($this->headers($token))
            ->post('/api/v1/payments', [
                'fee_assign_ids' => [$assign->id],
                'payment_type' => 'manual',
                'documents' => [$this->slip()],
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.payment_type', PaymentInfo::TYPE_MANUAL);
    }

    /** Opening the route did not relax the proof. */
    public function test_an_open_route_still_requires_a_slip(): void
    {
        [$token, $assign] = $this->memberWithDues();
        $this->offline(true);

        $this->withHeaders($this->headers($token))
            ->postJson('/api/v1/payments', [
                'fee_assign_ids' => [$assign->id],
                'payment_type' => 'manual',
            ])
            ->assertStatus(422)
            /*
             * `error.details`, not Laravel's `errors` - so NOT
             * assertJsonValidationErrors, which looks for the framework's own
             * envelope and reports "response does not have JSON validation
             * errors" against a response that plainly does. This API wraps
             * every failure in `error`; see PaymentSlipRequiredTest, which
             * asserts the same path for the same rule.
             */
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath('error.details.documents.0', 'The documents field is required.');
    }

    // ---- the omitted choice -----------------------------------------------

    /**
     * AN OMITTED TYPE IS NOT A CHOICE, so it resolves to whatever is open.
     *
     * It used to default to `manual` unconditionally. Left that way, every
     * client that does not send the field would now be refused by an
     * association with offline closed - a confusing answer to a request that
     * expressed no preference at all.
     */
    public function test_an_omitted_type_becomes_online_when_offline_is_shut(): void
    {
        [$token, $assign] = $this->memberWithDues();
        $this->offline(false);

        $this->withHeaders($this->headers($token))
            ->postJson('/api/v1/payments', ['fee_assign_ids' => [$assign->id]])
            ->assertStatus(201)
            ->assertJsonPath('data.payment_type', PaymentInfo::TYPE_ONLINE);
    }

    public function test_an_omitted_type_stays_manual_where_offline_is_open(): void
    {
        [$token, $assign] = $this->memberWithDues();
        $this->offline(true);

        $this->withHeaders($this->headers($token))
            ->post('/api/v1/payments', [
                'fee_assign_ids' => [$assign->id],
                'documents' => [$this->slip()],
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.payment_type', PaymentInfo::TYPE_MANUAL);
    }

    // ---- what the app is told ---------------------------------------------

    public function test_payment_options_close_the_manual_card_when_it_is_off(): void
    {
        [$token] = $this->memberWithDues();

        $this->inTenant(function () {
            Setting::put(Setting::MEMBER_OFFLINE_PAYMENT_ENABLED, false);
            Setting::put(Setting::BANK_ACCOUNT_NUMBER, '1234567890');
        });

        $this->withHeaders($this->headers($token))
            ->getJson('/api/v1/fees/payment-instructions')
            ->assertOk()
            ->assertJsonPath('data.manual.available', false)
            ->assertJsonPath('data.manual.reason', 'disabled');
    }

    /**
     * TWO DIFFERENT CLOSURES, TWO DIFFERENT ANSWERS - which is why `reason` is
     * on the wire at all. "The office has not published its account details"
     * sends a member to ask for them; "this association does not take offline
     * payments" does not.
     */
    public function test_a_switched_on_route_with_no_bank_account_says_which_is_missing(): void
    {
        [$token] = $this->memberWithDues();

        $this->inTenant(function () {
            Setting::put(Setting::MEMBER_OFFLINE_PAYMENT_ENABLED, true);
            Setting::put(Setting::BANK_ACCOUNT_NUMBER, '');
        });

        $this->withHeaders($this->headers($token))
            ->getJson('/api/v1/fees/payment-instructions')
            ->assertOk()
            ->assertJsonPath('data.manual.available', false)
            ->assertJsonPath('data.manual.reason', 'no_bank_details');
    }

    public function test_both_answered_opens_the_card_with_no_reason(): void
    {
        [$token] = $this->memberWithDues();

        $this->inTenant(function () {
            Setting::put(Setting::MEMBER_OFFLINE_PAYMENT_ENABLED, true);
            Setting::put(Setting::BANK_ACCOUNT_NUMBER, '1234567890');
        });

        $this->withHeaders($this->headers($token))
            ->getJson('/api/v1/fees/payment-instructions')
            ->assertOk()
            ->assertJsonPath('data.manual.available', true)
            ->assertJsonPath('data.manual.reason', null);
    }

    // ---- the seeded default -----------------------------------------------

    /** A new association starts closed, which is the policy being asked for. */
    public function test_a_new_association_has_offline_payment_off(): void
    {
        $this->inTenant(function () {
            app(TenantSeedService::class)->seedAll();

            self::assertFalse(
                (bool) Setting::get(Setting::MEMBER_OFFLINE_PAYMENT_ENABLED),
                'A freshly provisioned association should not accept member-filed offline payments.',
            );
        });
    }

    // ---- staff are unaffected ---------------------------------------------

    /**
     * THIS SWITCH IS ABOUT WHO MAY CREATE THE RECORD, not about whether the
     * association takes money offline at all.
     *
     * A cashier recording a counter payment goes through the staff collection
     * endpoint under `collections.create`. Closing the member's route must not
     * close theirs - an association that turns this off precisely because it
     * wants collection to go through the office would otherwise have shut the
     * office out too.
     */
    public function test_staff_can_still_record_a_collection_while_it_is_off(): void
    {
        [, $assign] = $this->memberWithDues();
        $this->offline(false);

        $cash = $this->inTenant(fn () => Ledger::query()->where('name', 'Cash')->firstOrFail()->id);

        $token = $this->inTenant(function () {
            $user = User::create([
                'name' => 'Cashier',
                'email' => 'cashier-offline@assoc.test',
                'password' => 'secret-password',
            ]);
            $user->assignRole('admin');

            return $user->createToken('test', $user->getAllPermissions()->pluck('name')->all())
                ->plainTextToken;
        });

        $this->withHeaders($this->headers($token))
            ->postJson('/api/v1/staff/collections', [
                'member_id' => $assign->member_id,
                'fee_assign_ids' => [$assign->id],
                'ledger_id' => $cash,
            ])
            ->assertStatus(201);
    }
}
