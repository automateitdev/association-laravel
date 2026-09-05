<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\Operator;
use App\Models\OperatorAuditLog;
use App\Models\Tenant\GatewayCredential;
use App\Services\Gateways\FakePaymentGateway;
use App\Services\Gateways\GatewayRegistry;
use App\Services\Gateways\PayflexSpgGateway;
use App\Services\Gateways\SonaliPaymentGateway;
use App\Services\TenantReadiness;
use App\Services\Totp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TenantTestCase;

/**
 * Onboarding an association through the console: the gateway form, the
 * readiness checklist, the operator list and the associations filters.
 *
 * WHAT IS WORTH TESTING HERE is not that the forms submit. It is the handful of
 * ways this could look finished and be wrong:
 *
 *   - a merchant secret reaching an HTML response, which is the whole reason
 *     the surface moved off the association's own settings screen;
 *   - the AR account written from a typo, which does not fail loudly — it
 *     succeeds, and the money goes somewhere else;
 *   - a change to where an association's money lands leaving no trace in the
 *     association's OWN records;
 *   - a readiness checklist that says an association is fine when nobody can
 *     administer it;
 *   - an unreachable tenant taking the whole page down instead of being
 *     reported.
 */
class ConsoleOnboardingTest extends TenantTestCase
{
    private const PAYFLEX = [
        'payflex_base_url' => 'https://payflex.example.test',
        'payflex_username' => 'bcs-client',
        'payflex_password' => 'payflex-secret-value',
        'spg_user' => 'assoc-merchant',
        'spg_password' => 'spg-secret-value',
        'ar_account' => '00987654321098',
        'party_name' => 'Demo Association',
    ];

    private const CREDENTIALS = [
        'api_base_url' => 'https://sandbox.example.test/api',
        'redirect_base_url' => 'https://pay.example.test/return',
        'username' => 'merchant-user',
        'password' => 'merchant-secret-value',
        'ar_account' => '00123456789012',
        'basic_auth' => 'basic-token-value',
        'callback_username' => 'callback-user',
        'callback_password' => 'callback-secret-value',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'platform.console_enabled' => true,
            'platform.console_ips' => '',
            'session.driver' => 'database',
        ]);
    }

    private function operator(string $email = 'op@platform.test'): Operator
    {
        $operator = Operator::create([
            'name' => 'Operator',
            'email' => $email,
            'password' => 'a-long-enough-password',
            'is_active' => true,
        ]);

        $operator->forceFill([
            'mfa_secret' => app(Totp::class)->generateSecret(),
            'mfa_confirmed_at' => now(),
            'mfa_recovery_codes' => [Hash::make('RECOV-ERY01')],
        ])->save();

        return $operator;
    }

    /** See BreakGlassTest for why the replay marker is cleared rather than waited out. */
    private function signIn(Operator $operator): void
    {
        $this->flushSession();

        Operator::whereKey($operator->id)->update(['mfa_last_used_step' => null]);

        $this->post('/platform/login', [
            'email' => $operator->email,
            'password' => 'a-long-enough-password',
        ])->assertRedirect(route('platform.challenge'));

        $this->post('/platform/challenge', [
            'code' => app(Totp::class)->codeAt($operator->mfa_secret, intdiv(time(), Totp::PERIOD)),
        ])->assertRedirect('/platform');
    }

    private function configure(array $overrides = [], string $provider = 'spg'): \Illuminate\Testing\TestResponse
    {
        $base = $provider === 'payflex_spg' ? self::PAYFLEX : self::CREDENTIALS;
        $payload = array_merge($base, $overrides);

        return $this->post("/platform/tenants/{$this->slug()}/gateway", $payload + [
            'provider' => $provider,
            'ar_account_confirm' => $payload['ar_account'],
        ]);
    }

    // ------------------------------------------------------------ the gateway

    public function test_an_operator_can_configure_the_gateway(): void
    {
        $this->signIn($this->operator());

        $this->configure()->assertRedirect(route('platform.tenant', $this->slug()));

        $stored = $this->inTenant(
            fn () => GatewayCredential::where('provider', SonaliPaymentGateway::PROVIDER)->first()
        );

        $this->assertNotNull($stored);
        $this->assertTrue((bool) $stored->is_active);
        $this->assertSame('00123456789012', $stored->credential('ar_account'));
        $this->assertSame('merchant-secret-value', $stored->credential('password'));
    }

    /**
     * THE ONE THAT MATTERS MOST.
     *
     * The credentials are write-only by design — no endpoint returns them, so a
     * compromised account cannot read them. A console page that rendered one
     * back would quietly undo that, and it would look like a helpful "edit"
     * screen while doing it.
     */
    public function test_no_secret_is_ever_rendered_back(): void
    {
        $this->signIn($this->operator());
        $this->configure();

        $page = $this->get("/platform/tenants/{$this->slug()}");
        $page->assertOk();

        foreach (['merchant-secret-value', 'basic-token-value', 'callback-secret-value'] as $secret) {
            $page->assertDontSee($secret);
        }

        // Not the full account number either — the last four, and no more.
        $page->assertDontSee('00123456789012');
        $page->assertSee('9012');
    }

    /**
     * A typo in the AR account does not fail loudly. It succeeds, and the money
     * goes somewhere else — which is why it is typed twice rather than
     * confirmed with a checkbox somebody learns to tick.
     */
    public function test_a_mistyped_ar_account_writes_nothing(): void
    {
        $this->signIn($this->operator());

        $this->post("/platform/tenants/{$this->slug()}/gateway", self::CREDENTIALS + [
            'provider' => 'spg',
            'ar_account_confirm' => '00123456789013',
        ])->assertSessionHasErrors('gateway');

        $this->assertNull($this->inTenant(
            fn () => GatewayCredential::where('provider', SonaliPaymentGateway::PROVIDER)->first()
        ));
    }

    public function test_a_redirect_url_that_is_not_a_url_is_refused(): void
    {
        $this->signIn($this->operator());

        $this->configure(['redirect_base_url' => 'not a url'])
            ->assertSessionHasErrors('redirect_base_url');
    }

    /**
     * Both logs, and they answer different questions.
     *
     * The association's says their gateway changed — they are entitled to know,
     * even though they cannot make the change. The platform's says which
     * operator changed it, which is the only one of the two any use afterwards.
     */
    public function test_the_change_is_recorded_for_the_association_and_for_us(): void
    {
        $this->signIn($this->operator());
        $this->configure();

        $theirs = $this->inTenant(
            fn () => DB::table('audit_logs')->where('action', 'gateway.credentials.updated')->first()
        );

        $this->assertNotNull($theirs, "The association's own log must record it.");

        $ours = OperatorAuditLog::where('action', 'gateway.credentials.updated')->latest('id')->first();

        $this->assertSame('op@platform.test', $ours->operator_email);

        // Field names and four digits. Never a value.
        $this->assertContains('password', $ours->after['fields']);
        $this->assertSame('9012', $ours->after['ar_account_last4']);
        $this->assertStringNotContainsString('merchant-secret-value', json_encode($ours->after));
    }

    public function test_online_payment_can_be_stopped_and_resumed_without_retyping(): void
    {
        $this->signIn($this->operator());
        $this->configure();

        $this->post("/platform/tenants/{$this->slug()}/gateway/toggle", ['action' => 'disable'])
            ->assertRedirect(route('platform.tenant', $this->slug()));

        $credential = fn () => $this->inTenant(
            fn () => GatewayCredential::where('provider', SonaliPaymentGateway::PROVIDER)->first()
        );

        $this->assertFalse((bool) $credential()->is_active);

        // The credentials survive, which is the point of disable over delete.
        $this->assertSame('merchant-secret-value', $credential()->credential('password'));

        $this->post("/platform/tenants/{$this->slug()}/gateway/toggle", ['action' => 'enable']);

        $this->assertTrue((bool) $credential()->is_active);
    }

    public function test_disabling_a_gateway_that_does_not_exist_says_so(): void
    {
        $this->signIn($this->operator());

        $this->post("/platform/tenants/{$this->slug()}/gateway/toggle", ['action' => 'disable'])
            ->assertSessionHasErrors('gateway');
    }

    // -------------------------------------------------------------- payflex

    /**
     * The second route to the same bank.
     *
     * `SonaliPaymentGateway` talks to SPG's v2 API directly; `PayflexSpgGateway`
     * talks to PayFlex, which talks to SPG v3. Which one an association uses is
     * its own setting, so one can be moved and proved while the rest stay put.
     */
    public function test_an_association_can_be_configured_for_payflex(): void
    {
        $this->signIn($this->operator());

        $this->configure([], 'payflex_spg')
            ->assertRedirect(route('platform.tenant', $this->slug()));

        $stored = $this->inTenant(
            fn () => GatewayCredential::where('provider', 'payflex_spg')->first()
        );

        $this->assertNotNull($stored);
        $this->assertTrue((bool) $stored->is_active);

        // The association's OWN merchant credentials travel with it. Without
        // them PayFlex falls back to its own account, which would collect this
        // association's money into somebody else's.
        $this->assertSame('assoc-merchant', $stored->credential('spg_user'));
        $this->assertSame('spg-secret-value', $stored->credential('spg_password'));
    }

    /**
     * EXACTLY ONE ACTIVE GATEWAY.
     *
     * Two would make "which gateway is this association using" depend on row
     * order, which is how an association ends up collecting through the
     * provider it thought it had left.
     */
    public function test_switching_provider_leaves_only_one_active(): void
    {
        $this->signIn($this->operator());

        $this->configure();                       // direct
        $this->configure([], 'payflex_spg');      // then PayFlex

        $rows = $this->inTenant(fn () => GatewayCredential::get());

        $this->assertCount(2, $rows, 'The old configuration is kept, not deleted.');
        $this->assertSame(['payflex_spg'], $rows->where('is_active', true)->pluck('provider')->all());

        // And it is still there to switch back to, without retyping.
        $this->assertSame(
            'merchant-secret-value',
            $rows->firstWhere('provider', 'spg')->credential('password')
        );
    }

    public function test_the_registry_picks_the_adapter_the_association_configured(): void
    {
        $this->signIn($this->operator());
        $this->configure([], 'payflex_spg');

        config(['services.gateway.driver' => 'auto']);

        $this->inTenant(function () {
            $this->assertInstanceOf(PayflexSpgGateway::class, app(GatewayRegistry::class)->active());
        });
    }

    /**
     * The default takes no real money.
     *
     * `PAYMENT_GATEWAY=fake` wins over whatever an association has configured,
     * so an environment nobody has thought about cannot collect.
     */
    public function test_the_fake_driver_overrides_a_configured_association(): void
    {
        $this->signIn($this->operator());
        $this->configure([], 'payflex_spg');

        config(['services.gateway.driver' => 'fake']);

        $this->inTenant(function () {
            $this->assertInstanceOf(FakePaymentGateway::class, app(GatewayRegistry::class)->active());
        });
    }

    /**
     * An unknown provider must not fall through to somebody else's adapter.
     *
     * A downgrade, or a hand-edited row, should stop payment — not route this
     * association's money through whichever gateway happened to be first.
     */
    public function test_an_unknown_provider_falls_back_to_the_fake(): void
    {
        config(['services.gateway.driver' => 'auto']);

        $this->assertInstanceOf(
            FakePaymentGateway::class,
            app(GatewayRegistry::class)->for('some_gateway_that_does_not_exist')
        );
    }

    public function test_payflex_fields_are_required_and_spg_fields_are_not_asked_for(): void
    {
        $this->signIn($this->operator());

        // An SPG payload submitted as PayFlex is missing everything PayFlex needs.
        $this->post("/platform/tenants/{$this->slug()}/gateway", self::CREDENTIALS + [
            'provider' => 'payflex_spg',
            'ar_account_confirm' => self::CREDENTIALS['ar_account'],
        ])->assertSessionHasErrors(['payflex_base_url', 'payflex_username', 'spg_user']);
    }

    /** Optional means optional: a blank party name is not a refusal. */
    public function test_the_optional_party_name_can_be_left_out(): void
    {
        $this->signIn($this->operator());

        $this->configure(['party_name' => ''], 'payflex_spg')
            ->assertRedirect(route('platform.tenant', $this->slug()));

        $stored = $this->inTenant(fn () => GatewayCredential::where('provider', 'payflex_spg')->first());

        $this->assertNull($stored->credential('party_name'));
    }

    public function test_a_provider_nobody_offers_is_refused(): void
    {
        $this->signIn($this->operator());

        $this->configure([], 'spg')->assertRedirect();

        $this->post("/platform/tenants/{$this->slug()}/gateway", self::CREDENTIALS + [
            'provider' => 'bkash',
            'ar_account_confirm' => self::CREDENTIALS['ar_account'],
        ])->assertSessionHasErrors('provider');
    }

    // ---------------------------------------------------------- the checklist

    /**
     * An association with no administrator is not "provisioned successfully".
     *
     * This is the gap the checklist exists for: provisioning finishes, the page
     * shows green figures, and nobody can log in — discovered by an officer
     * three weeks later.
     */
    public function test_readiness_blocks_on_an_association_nobody_can_administer(): void
    {
        $readiness = app(TenantReadiness::class)->for($this->tenant);

        $this->assertTrue($readiness['reachable']);
        $this->assertFalse($readiness['ready']);

        $staff = collect($readiness['checks'])->firstWhere('key', 'staff');
        $this->assertFalse($staff['ok']);
        $this->assertTrue($staff['blocking']);
    }

    public function test_an_association_with_an_administrator_and_accounts_is_ready(): void
    {
        $this->seedSuperadmin();

        $this->inTenant(fn () => DB::table('ledgers')->insert([
            'account_group_id' => $this->anyAccountGroup(),
            'name' => 'Cash in Hand',
            'opening_balance' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $readiness = app(TenantReadiness::class)->for($this->tenant->fresh());

        $this->assertTrue($readiness['ready'], 'Blocking: '.json_encode(
            collect($readiness['checks'])->where('ok', false)->pluck('label')
        ));
        $this->assertSame(0, $readiness['blocking']);
    }

    /**
     * Advisory items must not be counted as blocking.
     *
     * Otherwise a brand-new association with no members yet reads as broken,
     * and an operator learns to ignore the whole panel — which is how the one
     * real problem ends up unread.
     */
    public function test_having_no_members_yet_is_not_a_blocking_problem(): void
    {
        $this->seedSuperadmin();
        $this->inTenant(fn () => DB::table('ledgers')->insert([
            'account_group_id' => $this->anyAccountGroup(),
            'name' => 'Cash in Hand',
            'opening_balance' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $readiness = app(TenantReadiness::class)->for($this->tenant->fresh());

        $members = collect($readiness['checks'])->firstWhere('key', 'members');

        $this->assertFalse($members['ok']);
        $this->assertFalse($members['blocking']);
        $this->assertTrue($readiness['ready']);
    }

    public function test_the_tenant_page_shows_the_checklist(): void
    {
        $this->signIn($this->operator());

        $this->get("/platform/tenants/{$this->slug()}")
            ->assertOk()
            ->assertSee('An administrator exists')
            ->assertSee('Payment gateway configured');
    }

    // ---------------------------------------------------------- the operators

    /**
     * Break-glass needs two operators. Finding that out during an incident is
     * the wrong moment, so the page says it when there is one.
     */
    public function test_the_operator_list_warns_when_break_glass_cannot_work(): void
    {
        $this->signIn($this->operator());

        $this->get('/platform/operators')
            ->assertOk()
            ->assertSee('cannot be used with fewer than two')
            ->assertSee('op@platform.test');
    }

    public function test_an_operator_without_a_second_factor_is_shown_as_unable_to_sign_in(): void
    {
        $this->signIn($this->operator());

        Operator::create([
            'name' => 'Not Enrolled',
            'email' => 'pending@platform.test',
            'password' => 'a-long-enough-password',
            'is_active' => true,
        ]);

        $this->get('/platform/operators')
            ->assertOk()
            ->assertSee('not enrolled')
            ->assertSee('Cannot sign in');
    }

    /** No create, no disable, no reset — see the view's own note on why. */
    public function test_the_console_cannot_create_an_operator(): void
    {
        $this->signIn($this->operator());

        $this->post('/platform/operators', [
            'email' => 'attacker@platform.test',
            'password' => 'whatever',
        ])->assertStatus(405);

        $this->assertNull(Operator::where('email', 'attacker@platform.test')->first());
    }

    // ------------------------------------------------------------ the filters

    public function test_the_associations_list_can_be_searched(): void
    {
        $this->signIn($this->operator());

        $this->get('/platform?q='.$this->slug())
            ->assertOk()
            ->assertSee($this->slug());

        $this->get('/platform?q=nothing-like-this')
            ->assertOk()
            ->assertSee('Nothing matches that');
    }

    public function test_the_status_filter_narrows_the_list(): void
    {
        $this->signIn($this->operator());

        $this->get('/platform?status=archived')
            ->assertOk()
            ->assertSee('Nothing matches that');

        $this->get('/platform?status=active')
            ->assertOk()
            ->assertSee($this->slug());
    }

    // --------------------------------------------------------------- the shell

    /**
     * The sidebar marks where you are.
     *
     * Worth a test rather than an eyeball: `routeIs()` patterns are the kind of
     * thing that silently stops matching when a route is renamed, and the
     * failure - every item unmarked - looks like a styling choice rather than a
     * bug.
     */
    public function test_the_sidebar_marks_the_section_you_are_in(): void
    {
        $this->signIn($this->operator());

        $cases = [
            '/platform' => 'Associations',
            "/platform/tenants/{$this->slug()}" => 'Associations',
            '/platform/break-glass' => 'Break-glass',
            '/platform/operators' => 'Operators',
            '/platform/audit' => 'Audit',
        ];

        foreach ($cases as $url => $expected) {
            $html = $this->get($url)->assertOk()->getContent();

            preg_match_all('/class="item on"[^>]*>(.*?)<\/a>/s', $html, $matches);

            $marked = array_map(
                fn ($m) => trim(preg_replace('/<[^>]*>|\s+/', ' ', $m)),
                $matches[1],
            );

            $this->assertCount(1, $marked, "{$url} should mark exactly one nav item.");
            $this->assertStringContainsString($expected, $marked[0], "{$url} marked the wrong item.");
        }
    }

    /** An association's page can be left again without the browser's back button. */
    public function test_a_tenant_page_offers_a_way_back_to_the_list(): void
    {
        $this->signIn($this->operator());

        $this->get("/platform/tenants/{$this->slug()}")
            ->assertOk()
            ->assertSee($this->slug())
            ->assertSee('href="'.route('platform.index').'"', false);
    }

    /**
     * Signed out, there is nowhere to navigate to — so there is no navigation.
     *
     * A sidebar on the sign-in page would advertise the console's shape to
     * somebody who has not proved they belong in it.
     */
    public function test_the_sign_in_page_has_no_navigation(): void
    {
        $this->get('/platform/login')
            ->assertOk()
            ->assertDontSee('Break-glass')
            ->assertDontSee('Sign out');
    }

    // ------------------------------------------------------------------ setup

    private function seedSuperadmin(): void
    {
        $this->inTenant(function () {
            $userId = DB::table('users')->insertGetId([
                'name' => 'Chair',
                'email' => 'chair@association.test',
                'password' => Hash::make('irrelevant'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $roleId = DB::table('roles')->where('name', 'superadmin')->value('id')
                ?? DB::table('roles')->insertGetId([
                    'name' => 'superadmin',
                    'guard_name' => 'web',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            DB::table('model_has_roles')->insert([
                'role_id' => $roleId,
                'model_type' => \App\Models\User::class,
                'model_id' => $userId,
            ]);
        });
    }

    private function anyAccountGroup(): int
    {
        return $this->inTenant(function () {
            $existing = DB::table('account_groups')->value('id');

            if ($existing) {
                return (int) $existing;
            }

            $categoryId = DB::table('account_categories')->value('id')
                ?? DB::table('account_categories')->insertGetId([
                    'name' => 'Assets',
                    'type' => 'asset',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            return (int) DB::table('account_groups')->insertGetId([
                'account_category_id' => $categoryId,
                'name' => 'Cash and Bank',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }
}
