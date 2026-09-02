<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Tenant\GatewayCredential;
use App\Models\Tenant\Setting;
use App\Models\User;
use App\Services\TenantSeedService;
use Tests\Support\TenantFixtures;
use Tests\TenantTestCase;

/**
 * Per-association configuration: the bank account members pay into, and the
 * Sonali Payment Gateway (SPG) credentials.
 *
 * This is the surface that makes onboarding a second association a command
 * rather than a deploy. The legacy system hard-codes all of it - the fine rate
 * as a class constant, and LIVE production gateway credentials as literals in a
 * controller, committed to git (D-10).
 */
class SettingsTest extends TenantTestCase
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

    private function staffToken(string $role = 'superadmin'): string
    {
        return $this->inTenant(function () use ($role) {
            app(TenantSeedService::class)->seedAll();

            static $n = 0;
            $n++;

            $user = User::create([
                'name' => "Admin {$n}",
                'email' => "admin{$n}@assoc.test",
                'password' => 'secret-password',
            ]);
            $user->assignRole($role);

            return $user->createToken('t', $user->getAllPermissions()->pluck('name')->all())
                ->plainTextToken;
        });
    }

    private function memberToken(): string
    {
        return $this->inTenant(function () {
            app(TenantSeedService::class)->seedAll();

            return $this->makeMember(['password' => 'x'])
                ->createToken('t', ['member.dues.view'])->plainTextToken;
        });
    }

    // ---- bank details ----------------------------------------------------

    public function test_staff_can_set_the_association_bank_account(): void
    {
        $token = $this->staffToken();

        $this->withHeaders($this->headers($token))
            ->putJson('/api/v1/staff/settings', [
                'bank' => [
                    'account_name' => 'COCSOL Cooperative Society',
                    'account_number' => '4446102001029',
                    'bank_name' => 'Sonali Bank PLC',
                    'branch' => 'Ramna Corporate',
                    'routing_number' => '200274324',
                    'instructions' => 'Quote your membership number as the reference.',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.bank.account_number', '4446102001029')
            ->assertJsonPath('data.bank.bank_name', 'Sonali Bank PLC');
    }

    /**
     * The member has to be told WHERE to send the money. Without this the
     * manual flow asks for a transfer and names no account.
     */
    public function test_a_member_can_see_where_to_pay(): void
    {
        $staff = $this->staffToken();

        $this->withHeaders($this->headers($staff))
            ->putJson('/api/v1/staff/settings', [
                'bank' => [
                    'account_name' => 'COCSOL Cooperative Society',
                    'account_number' => '4446102001029',
                    'bank_name' => 'Sonali Bank PLC',
                ],
            ])->assertOk();

        $this->withHeaders($this->headers($this->memberToken()))
            ->getJson('/api/v1/fees/payment-instructions')
            ->assertOk()
            ->assertJsonPath('data.manual.available', true)
            ->assertJsonPath('data.manual.bank.account_number', '4446102001029')
            ->assertJsonPath('data.online.available', false);
    }

    /**
     * An association that has not filled the details in must report
     * unavailable, so the app says "contact the office" rather than rendering
     * an empty card that looks like a loading failure.
     */
    public function test_unset_bank_details_report_as_unavailable(): void
    {
        $this->inTenant(fn () => app(TenantSeedService::class)->seedAll());

        $this->withHeaders($this->headers($this->memberToken()))
            ->getJson('/api/v1/fees/payment-instructions')
            ->assertOk()
            ->assertJsonPath('data.manual.available', false);
    }

    // ---- fine policy -----------------------------------------------------

    public function test_changing_the_fine_rate_is_audited(): void
    {
        $token = $this->staffToken();

        $this->withHeaders($this->headers($token))
            ->putJson('/api/v1/staff/settings', ['fine' => ['rate' => '150.00']])
            ->assertOk()
            ->assertJsonPath('data.fine.rate', '150.00');

        // A member asking "why did my fine change?" needs an answer with a date
        // and a name on it (FR-SET-3).
        $this->inTenant(function () {
            $this->assertDatabaseHas('audit_logs', ['action' => 'settings.updated']);
        });
    }

    public function test_nonsense_fine_settings_are_refused(): void
    {
        $token = $this->staffToken();

        $this->withHeaders($this->headers($token))
            ->putJson('/api/v1/staff/settings', ['fine' => ['rate' => -50]])
            ->assertStatus(422);

        $this->withHeaders($this->headers($token))
            ->putJson('/api/v1/staff/settings', ['fine' => ['suspension_threshold' => 0]])
            ->assertStatus(422);
    }

    // ---- gateway credentials ---------------------------------------------

    private function gatewayPayload(array $overrides = []): array
    {
        return array_merge([
            'api_base_url' => 'https://spg.sblesheba.com:6314',
            'redirect_base_url' => 'https://spg.sblesheba.com:6313',
            'username' => 'TESTUSER',
            'password' => 'test-secret',
            'ar_account' => '0002601020871',
            'basic_auth' => 'Basic dGVzdDp0ZXN0',
            'callback_username' => 'callback-user',
            'callback_password' => 'callback-secret',
        ], $overrides);
    }

    public function test_staff_can_configure_the_sonali_gateway(): void
    {
        $token = $this->staffToken();

        $this->withHeaders($this->headers($token))
            ->putJson('/api/v1/staff/settings/gateway', $this->gatewayPayload())
            ->assertOk()
            ->assertJsonPath('data.provider', 'spg')
            ->assertJsonPath('data.configured', true)
            // Enough to confirm WHICH account, not enough to use it.
            ->assertJsonPath('data.ar_account_last4', '0871');
    }

    /**
     * Credentials are WRITE-ONLY. An API that can display a merchant password
     * turns one compromised staff token into a compromised merchant account.
     */
    public function test_gateway_credentials_are_never_readable_through_the_api(): void
    {
        $token = $this->staffToken();

        $this->withHeaders($this->headers($token))
            ->putJson('/api/v1/staff/settings/gateway', $this->gatewayPayload())
            ->assertOk();

        $response = $this->withHeaders($this->headers($token))
            ->getJson('/api/v1/staff/settings')
            ->assertOk();

        $body = $response->getContent();

        foreach (['test-secret', 'callback-secret', 'Basic dGVzdDp0ZXN0', 'TESTUSER'] as $secret) {
            $this->assertStringNotContainsString(
                $secret,
                $body,
                "The settings endpoint leaked [{$secret}]."
            );
        }

        // The full account number must not come back either - only the last 4.
        $this->assertStringNotContainsString('0002601020871', $body);
    }

    /** NFR-SEC-2: encrypted at rest, so a database dump is not a merchant account. */
    public function test_gateway_credentials_are_encrypted_in_the_database(): void
    {
        $token = $this->staffToken();

        $this->withHeaders($this->headers($token))
            ->putJson('/api/v1/staff/settings/gateway', $this->gatewayPayload())
            ->assertOk();

        $this->inTenant(function () {
            $raw = \DB::table('gateway_credentials')->value('credentials');

            $this->assertStringNotContainsString('test-secret', $raw);
            $this->assertStringNotContainsString('TESTUSER', $raw);

            // And it still decrypts through the model.
            $this->assertSame(
                'test-secret',
                GatewayCredential::activeFor('spg')->credential('password')
            );
        });
    }

    public function test_an_invalid_gateway_url_is_refused(): void
    {
        $token = $this->staffToken();

        $this->withHeaders($this->headers($token))
            ->putJson('/api/v1/staff/settings/gateway', $this->gatewayPayload([
                'api_base_url' => 'not-a-url',
            ]))
            ->assertStatus(422);
    }

    // ---- who may change any of this --------------------------------------

    public function test_an_operator_cannot_read_or_change_settings(): void
    {
        $token = $this->staffToken('operator');

        $this->withHeaders($this->headers($token))
            ->getJson('/api/v1/staff/settings')
            ->assertStatus(403);

        $this->withHeaders($this->headers($token))
            ->putJson('/api/v1/staff/settings/gateway', $this->gatewayPayload())
            ->assertStatus(403);
    }

    public function test_a_member_cannot_reach_the_settings_endpoint(): void
    {
        $this->withHeaders($this->headers($this->memberToken()))
            ->getJson('/api/v1/staff/settings')
            ->assertStatus(403);
    }

    /**
     * Online payment stays off until an association turns it on, so a member is
     * never offered a route that is not configured.
     */
    public function test_online_payment_is_off_by_default_and_can_be_enabled(): void
    {
        $this->inTenant(fn () => app(TenantSeedService::class)->seedAll());
        $this->inTenant(fn () => $this->assertFalse((bool) Setting::get(Setting::ONLINE_PAYMENT_ENABLED)));

        $this->withHeaders($this->headers($this->staffToken()))
            ->putJson('/api/v1/staff/settings', ['payment' => ['online_enabled' => true]])
            ->assertOk()
            ->assertJsonPath('data.payment.online_enabled', true);
    }
}
