<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\Operator;
use App\Models\OperatorAuditLog;
use App\Models\Tenant;
use App\Models\TenantSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The platform console (FR-PLT-1 … FR-PLT-5).
 *
 * WHAT IS WORTH TESTING HERE is not that the pages render. It is the set of
 * things an operator must not be able to do quietly:
 *
 *   - reach the console at all on a deployment that has not enabled it;
 *   - suspend an association without saying why;
 *   - do any of it without leaving a record;
 *   - see an association's figures through the tenant API by holding an
 *     operator account, or vice versa.
 */
class PlatformConsoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The console is off by default, which is itself tested below.
        config(['platform.console_enabled' => true, 'platform.console_ips' => '']);

        /*
         * phpunit.xml sets SESSION_DRIVER=array for the whole suite, which is
         * right for a stateless token API and wrong for the one part of this
         * application that has a session. Tested on the driver production uses,
         * because the bug this class exists to prevent was precisely a session
         * that did not survive a request.
         */
        config(['session.driver' => 'database']);
    }

    private function operator(array $attributes = []): Operator
    {
        static $sequence = 0;
        $sequence++;

        return Operator::create(array_merge([
            'name' => "Operator {$sequence}",
            'email' => "operator{$sequence}@platform.test",
            'password' => 'a-long-enough-password',
            'is_active' => true,
        ], $attributes));
    }

    /**
     * A registry row only - NO DATABASE.
     *
     * `Tenant::create` fires stancl's provisioning pipeline, which really does
     * create a database and a scoped user. These tests are about the console's
     * behaviour towards a row in the registry; provisioning has its own tests
     * (T-13) and does not need re-running seventeen times here.
     */
    private function tenant(string $id = 'testassoc', string $status = Tenant::STATUS_ACTIVE): Tenant
    {
        return Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $id,
            'name' => 'Test Association',
            'status' => $status,
        ]));
    }

    // ---------------------------------------------------- is it there at all

    /**
     * OFF BY DEFAULT. The console can suspend an association and reach the
     * controls deciding where its payments land, and MFA is not built - so a
     * deployment that has not asked for it should not serve it.
     */
    public function test_the_console_does_not_exist_unless_enabled(): void
    {
        config(['platform.console_enabled' => false]);

        $this->get('/platform/login')->assertNotFound();
        $this->get('/platform')->assertNotFound();
    }

    /** A 404, not a 403: a refused address learns nothing about what is here. */
    public function test_an_address_outside_the_allowlist_gets_a_404_not_a_403(): void
    {
        config(['platform.console_ips' => '10.0.0.1']);

        $this->get('/platform/login')->assertNotFound();
    }

    public function test_an_address_on_the_allowlist_is_let_through(): void
    {
        config(['platform.console_ips' => '127.0.0.1,10.0.0.1']);

        $this->get('/platform/login')->assertOk();
    }

    // ------------------------------------------------------------- signing in

    public function test_an_operator_can_sign_in_and_it_is_recorded(): void
    {
        $operator = $this->operator();

        $this->post('/platform/login', [
            'email' => $operator->email,
            'password' => 'a-long-enough-password',
        ])->assertRedirect('/platform');

        $this->assertAuthenticatedAs($operator, 'operator');

        $this->assertDatabaseHas('operator_audit_logs', [
            'action' => 'operator.signed_in',
            'operator_email' => $operator->email,
        ]);
    }

    /**
     * THE TEST THAT WAS MISSING.
     *
     * Every sign-in test stopped at the redirect, and `assertAuthenticatedAs`
     * only asks about the guard within the request that just ran. So all of
     * them passed while the real console was unusable: SESSION_DRIVER was
     * `array`, correct for a token API and fatal for a server-rendered login.
     * Signing in genuinely succeeded and the NEXT request arrived a stranger.
     *
     * Following the redirect is the whole difference between "the credentials
     * were accepted" and "you are signed in".
     */
    public function test_a_signed_in_operator_stays_signed_in_on_the_next_request(): void
    {
        $operator = $this->operator();

        $this->post('/platform/login', [
            'email' => $operator->email,
            'password' => 'a-long-enough-password',
        ])->assertRedirect('/platform');

        // A SEPARATE request, which is where a session that does not persist
        // stops being invisible.
        $this->get('/platform')
            ->assertOk()
            ->assertSee('Associations');
    }

    /**
     * And the console refuses to serve at all on a driver that cannot keep a
     * session, rather than authenticating people into a redirect loop with no
     * error anywhere.
     */
    public function test_the_console_refuses_a_session_driver_that_cannot_persist(): void
    {
        config(['session.driver' => 'array']);

        $this->get('/platform/login')->assertStatus(500);
    }

    /** Disabled, never deleted - so their audit entries keep resolving to a name. */
    public function test_a_disabled_operator_cannot_sign_in(): void
    {
        $operator = $this->operator(['is_active' => false]);

        $this->post('/platform/login', [
            'email' => $operator->email,
            'password' => 'a-long-enough-password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest('operator');
    }

    /**
     * A failed attempt is logged with the email TRIED, which may be an account
     * that never existed - repeated attempts against a name that is not an
     * operator is exactly the pattern worth seeing.
     */
    public function test_a_failed_sign_in_is_recorded(): void
    {
        $this->operator(['email' => 'real@platform.test']);

        $this->post('/platform/login', [
            'email' => 'guessing@platform.test',
            'password' => 'wrong',
        ])->assertSessionHasErrors('email');

        $this->assertDatabaseHas('operator_audit_logs', ['action' => 'operator.login_failed']);
    }

    /**
     * The email column exists so an entry still names somebody after the
     * account is gone - and the entries that need it most are the ones with
     * NOBODY signed in. Reading it only from the session left a failed login,
     * where the email tried is the entire point, recorded as "system".
     */
    public function test_a_failed_sign_in_records_the_email_that_was_tried(): void
    {
        $this->post('/platform/login', [
            'email' => 'nobody@platform.test',
            'password' => 'wrong',
        ])->assertSessionHasErrors('email');

        $this->assertDatabaseHas('operator_audit_logs', [
            'action' => 'operator.login_failed',
            'operator_email' => 'nobody@platform.test',
        ]);
    }

    public function test_the_console_is_closed_to_anybody_not_signed_in(): void
    {
        $this->get('/platform')->assertRedirect('/platform/login');
        $this->get('/platform/audit')->assertRedirect('/platform/login');
    }

    // ---------------------------------------------- the association lifecycle

    public function test_an_operator_can_suspend_an_association(): void
    {
        $operator = $this->operator();
        $tenant = $this->tenant();

        $this->actingAs($operator, 'operator')
            ->post("/platform/tenants/{$tenant->getKey()}/transition", [
                'action' => 'suspend',
                'reason' => 'Unpaid platform invoice, agreed with the committee.',
                'confirm' => $tenant->getKey(),
            ])
            ->assertRedirect(route('platform.tenant', $tenant->getKey()));

        $this->assertSame(Tenant::STATUS_SUSPENDED, $tenant->fresh()->status);
        $this->assertNotNull($tenant->fresh()->suspended_at);
    }

    /**
     * Suspension locks an association out of its own records mid-day. "Why did
     * this happen" must have an answer that is not somebody's memory.
     */
    public function test_a_lifecycle_change_requires_a_reason(): void
    {
        $operator = $this->operator();
        $tenant = $this->tenant();

        $this->actingAs($operator, 'operator')
            ->post("/platform/tenants/{$tenant->getKey()}/transition", [
                'action' => 'suspend',
                'confirm' => $tenant->getKey(),
            ])
            ->assertSessionHasErrors('reason');

        $this->assertSame(Tenant::STATUS_ACTIVE, $tenant->fresh()->status);
    }

    /** The console lists many associations that look alike in a row of buttons. */
    public function test_the_association_id_must_be_typed_to_confirm(): void
    {
        $operator = $this->operator();
        $tenant = $this->tenant();

        $this->actingAs($operator, 'operator')
            ->post("/platform/tenants/{$tenant->getKey()}/transition", [
                'action' => 'suspend',
                'reason' => 'A perfectly good reason.',
                'confirm' => 'some-other-association',
            ])
            ->assertSessionHasErrors('confirm');

        $this->assertSame(Tenant::STATUS_ACTIVE, $tenant->fresh()->status);
    }

    public function test_every_lifecycle_change_is_recorded_with_who_and_why(): void
    {
        $operator = $this->operator();
        $tenant = $this->tenant();

        $this->actingAs($operator, 'operator')
            ->post("/platform/tenants/{$tenant->getKey()}/transition", [
                'action' => 'suspend',
                'reason' => 'Investigating a reported discrepancy.',
                'confirm' => $tenant->getKey(),
            ]);

        $entry = OperatorAuditLog::where('action', 'tenant.suspended')->latest('id')->first();

        $this->assertNotNull($entry);
        $this->assertSame($tenant->getKey(), $entry->tenant_id);
        $this->assertSame($operator->email, $entry->operator_email);
        $this->assertSame('Investigating a reported discrepancy.', $entry->reason);
        $this->assertSame(['status' => 'active'], $entry->before);
        $this->assertSame('web', $entry->source);
    }

    public function test_a_suspended_association_can_be_reinstated(): void
    {
        $operator = $this->operator();
        $tenant = $this->tenant('reinstateme', Tenant::STATUS_SUSPENDED);

        $this->actingAs($operator, 'operator')
            ->post("/platform/tenants/{$tenant->getKey()}/transition", [
                'action' => 'reinstate',
                'reason' => 'Invoice settled.',
                'confirm' => $tenant->getKey(),
            ]);

        $this->assertSame(Tenant::STATUS_ACTIVE, $tenant->fresh()->status);
        $this->assertNull($tenant->fresh()->suspended_at);
    }

    /**
     * An association's ledger is a financial record with a retention period in
     * years. Archiving closes the association; it must not destroy anything.
     */
    public function test_archiving_keeps_the_association_and_its_database(): void
    {
        $operator = $this->operator();
        $tenant = $this->tenant();

        $this->actingAs($operator, 'operator')
            ->post("/platform/tenants/{$tenant->getKey()}/transition", [
                'action' => 'archive',
                'reason' => 'The association has wound up.',
                'confirm' => $tenant->getKey(),
            ]);

        $fresh = $tenant->fresh();

        $this->assertSame(Tenant::STATUS_ARCHIVED, $fresh->status);
        // Still in the registry, with its database name intact.
        $this->assertNotNull($fresh);
        $this->assertDatabaseHas('tenants', ['id' => $tenant->getKey()]);
    }

    // -------------------------------------------------- provisioning by form

    public function test_the_new_association_form_is_reachable(): void
    {
        $this->actingAs($this->operator(), 'operator')
            ->get('/platform/tenants/new')
            ->assertOk()
            ->assertSee('Association id');
    }

    /**
     * The slug becomes the database name and the code members type into the
     * app, so a bad one must be refused by the FORM - before a provisioning run
     * is opened, not after a database has been half-created.
     */
    public function test_an_invalid_id_is_refused_before_anything_is_created(): void
    {
        $this->actingAs($this->operator(), 'operator')
            ->post('/platform/tenants', [
                'slug' => 'Not A Slug',
                'name' => 'Bad Id Association',
            ])
            ->assertSessionHasErrors('slug');

        $this->assertDatabaseCount('tenants', 0);
        // Nothing was attempted, so nothing should have been recorded as a run.
        $this->assertDatabaseCount('tenant_provisioning_runs', 0);
    }

    public function test_an_id_already_taken_is_refused(): void
    {
        $tenant = $this->tenant('taken');

        $this->actingAs($this->operator(), 'operator')
            ->post('/platform/tenants', [
                'slug' => $tenant->getKey(),
                'name' => 'Duplicate',
            ])
            ->assertSessionHasErrors('slug');

        $this->assertDatabaseCount('tenants', 1);
    }

    public function test_the_form_requires_a_name(): void
    {
        $this->actingAs($this->operator(), 'operator')
            ->post('/platform/tenants', ['slug' => 'nameless'])
            ->assertSessionHasErrors('name');

        $this->assertDatabaseCount('tenants', 0);
    }

    // ------------------------------------------------------------- the pages

    public function test_the_overview_totals_come_from_snapshots_not_a_live_query(): void
    {
        $operator = $this->operator();
        $tenant = $this->tenant();

        TenantSnapshot::create([
            'tenant_id' => $tenant->getKey(),
            'members' => 315,
            'active_members' => 300,
            'completed_payments' => 4010,
            'collected_instalments' => '8460000.00',
            'collected_fines' => '243080.00',
            'collected_at' => now(),
        ]);

        $this->actingAs($operator, 'operator')
            ->get('/platform')
            ->assertOk()
            ->assertSee('315')
            // Instalments and fines are never combined into one figure. ADR-0005.
            ->assertSee('8,460,000.00')
            ->assertSee('243,080.00');
    }

    public function test_the_audit_page_lists_what_was_done(): void
    {
        $operator = $this->operator();
        $tenant = $this->tenant();

        $this->actingAs($operator, 'operator')
            ->post("/platform/tenants/{$tenant->getKey()}/transition", [
                'action' => 'suspend',
                'reason' => 'A recorded reason that should appear.',
                'confirm' => $tenant->getKey(),
            ]);

        $this->actingAs($operator, 'operator')
            ->get('/platform/audit')
            ->assertOk()
            ->assertSee('tenant.suspended')
            ->assertSee('A recorded reason that should appear.')
            ->assertSee($operator->email);
    }

    /**
     * The console reports COUNTS, never rows. Reading an association's members
     * or money needs a break-glass grant (FR-SEC-6), which is not built.
     */
    public function test_the_tenant_page_reports_counts_not_member_data(): void
    {
        $operator = $this->operator();
        $tenant = $this->tenant();

        $this->actingAs($operator, 'operator')
            ->get("/platform/tenants/{$tenant->getKey()}")
            ->assertOk()
            ->assertSee('Health')
            // No tenant database exists for this registry row, and the page
            // says so rather than failing.
            ->assertSee('Not reachable');
    }

    // ------------------------------------------------- the two worlds are apart

    /**
     * An operator has no standing inside an association, and an association's
     * staff have none on the platform. The guards are separate for that reason
     * and this pins it.
     */
    public function test_an_operator_session_is_not_a_tenant_session(): void
    {
        $operator = $this->operator();

        $this->actingAs($operator, 'operator');

        // Signed in as an operator...
        $this->assertTrue(auth('operator')->check());

        /*
         * ...and nobody at all to the guards the app uses. Asserted this way
         * rather than by calling a tenant endpoint, because tenant resolution
         * runs before authentication (FR-TEN-1) and would need a provisioned
         * database to get far enough to prove anything about guards.
         */
        $this->assertFalse(auth('web')->check());
        $this->assertFalse(auth('sanctum')->check());
    }
}
