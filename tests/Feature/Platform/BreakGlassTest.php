<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\BreakGlassGrant;
use App\Models\Operator;
use App\Models\OperatorAuditLog;
use App\Notifications\BreakGlassGranted;
use App\Services\Totp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TenantTestCase;

/**
 * Break-glass (FR-SEC-6, NFR-SEC-6).
 *
 * WHAT IS WORTH TESTING is not that a grant can be created. It is the set of
 * ways this feature could look identical in a screenshot and be worthless:
 *
 *   - one operator asking and approving, which is a form rather than a control;
 *   - a grant that opens data before anyone has been told, which removes the
 *     only thing making operator access detectable;
 *   - a grant that outlives its window because expiry was left to a job;
 *   - a grant for payments quietly opening members;
 *   - reads that happen without leaving a record.
 *
 * Each of those is a test below, and each breaks the thing on purpose rather
 * than asserting the happy path from a distance.
 *
 * `TenantTestCase`, not the console's own base class, because half of this
 * feature only exists against a REAL tenant database: the superadmin lookup,
 * the row written into the association's own audit log, and the rows an
 * operator actually reads. A registry-only fixture would let every one of those
 * pass while being untested.
 */
class BreakGlassTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'platform.console_enabled' => true,
            'platform.console_ips' => '',

            // As in PlatformConsoleTest: the console is the one part of this
            // application with a session, and `array` is not a session.
            'session.driver' => 'database',
        ]);

        Notification::fake();
    }

    // ------------------------------------------------------------- fixtures

    private function operator(string $email): Operator
    {
        $operator = Operator::create([
            'name' => 'Operator '.$email,
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

    /**
     * Sign in through the real two-step form.
     *
     * NOT `actingAs`: TenantTestCase forgets the guards before every request,
     * so a faked user does not survive to the controller. The console has a
     * session and these tests use it.
     *
     * The replay marker is cleared first. These tests switch between two
     * operators and back, and the console - correctly - refuses a TOTP code
     * already used within its window, while `Totp::verify()` reads the real
     * clock, so `travel()` cannot reach the next code. Clearing it is how a
     * person waiting thirty seconds is expressed here; the guard itself is
     * tested in PlatformConsoleTest, where it is the subject rather than an
     * obstacle.
     */
    private function signIn(Operator $operator): void
    {
        $this->flushSession();

        // A direct update, not `forceFill(...)->save()`: this instance was never
        // reloaded after the controller stamped the step, so the model believes it
        // is already null and saves nothing.
        Operator::whereKey($operator->id)->update(['mfa_last_used_step' => null]);

        $this->post('/platform/login', [
            'email' => $operator->email,
            'password' => 'a-long-enough-password',
        ])->assertRedirect(route('platform.challenge'));

        $totp = app(Totp::class);

        $this->post('/platform/challenge', [
            'code' => $totp->codeAt($operator->mfa_secret, intdiv(time(), Totp::PERIOD)),
        ])->assertRedirect('/platform');
    }

    /** A superadmin with an email, so there is somebody to notify. */
    private function seedSuperadmin(string $email = 'chair@association.test'): void
    {
        $this->inTenant(function () use ($email) {
            $userId = DB::table('users')->insertGetId([
                'name' => 'Association Chair',
                'email' => $email,
                'password' => Hash::make('irrelevant-for-this-test'),
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

    private function request(Operator $operator, string $scope = 'members'): BreakGlassGrant
    {
        $this->signIn($operator);

        $this->post("/platform/tenants/{$this->slug()}/break-glass", [
            'scope' => $scope,
            'minutes' => 30,
            'reason' => 'Invoice INV-2026-0912 shows completed and the member says no receipt arrived.',
        ])->assertRedirect(route('platform.break-glass'));

        return BreakGlassGrant::latest('id')->firstOrFail();
    }

    /** Request, then have a second operator approve — the whole happy path. */
    private function liveGrant(string $scope = 'members'): array
    {
        $this->seedSuperadmin();

        $asker = $this->operator('asker@platform.test');
        $approver = $this->operator('approver@platform.test');

        $grant = $this->request($asker, $scope);

        $this->signIn($approver);
        $this->post("/platform/break-glass/{$grant->id}/decide", ['decision' => 'approve'])
            ->assertRedirect(route('platform.break-glass'));

        // Back to the operator who asked: the grant is theirs, not the approver's.
        $this->signIn($asker);

        return [$grant->fresh(), $asker, $approver];
    }

    // ------------------------------------------------------- the two-person rule

    /**
     * THE ONE THAT MATTERS.
     *
     * An operator who can both ask and grant is not a control. Unlike voucher
     * self-approval — allowed and merely recorded, because the approver there is
     * still inside the association whose money it is — here the data owner is
     * not in the room at all.
     */
    public function test_an_operator_cannot_approve_their_own_request(): void
    {
        $asker = $this->operator('asker@platform.test');
        $grant = $this->request($asker);

        $this->post("/platform/break-glass/{$grant->id}/decide", ['decision' => 'approve'])
            ->assertSessionHasErrors('break_glass');

        $this->assertSame(BreakGlassGrant::STATUS_PENDING, $grant->fresh()->status);
        $this->assertFalse($grant->fresh()->isLive());
    }

    public function test_a_second_operator_can_approve(): void
    {
        [$grant] = $this->liveGrant();

        $this->assertSame(BreakGlassGrant::STATUS_APPROVED, $grant->status);
        $this->assertSame('approver@platform.test', $grant->decided_by_email);
        $this->assertTrue($grant->isLive());
    }

    // ------------------------------------------------- notification is the control

    /**
     * Approval alone opens nothing.
     *
     * With no superadmin to tell, the grant is approved and shut — which is the
     * right way round. The opposite ordering is the same feature with the only
     * detection removed, and it would look identical on screen.
     */
    public function test_a_grant_nobody_can_be_notified_of_opens_nothing(): void
    {
        $asker = $this->operator('asker@platform.test');
        $approver = $this->operator('approver@platform.test');

        $grant = $this->request($asker);

        $this->signIn($approver);
        $this->post("/platform/break-glass/{$grant->id}/decide", ['decision' => 'approve']);

        $grant = $grant->fresh();

        $this->assertSame(BreakGlassGrant::STATUS_APPROVED, $grant->status);
        $this->assertNull($grant->notified_at);
        $this->assertFalse($grant->isLive());
        $this->assertStringContainsString('no superadmin', $grant->notify_error);

        $this->signIn($asker);
        $this->get("/platform/tenants/{$this->slug()}/data/members")->assertForbidden();
    }

    public function test_approval_emails_the_association_superadmins(): void
    {
        [$grant] = $this->liveGrant();

        $this->assertSame(['chair@association.test'], $grant->notified_to);

        Notification::assertSentOnDemand(
            BreakGlassGranted::class,
            fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === ['chair@association.test'],
        );
    }

    /**
     * The association's OWN records say it happened.
     *
     * The durable half of the notification. Mail bounces, inboxes are
     * forwarded, addresses go stale — and a notice held only by the party being
     * watched is not evidence of anything.
     */
    public function test_the_association_audit_log_records_the_grant(): void
    {
        [$grant] = $this->liveGrant();

        $entry = $this->inTenant(fn () => DB::table('audit_logs')
            ->where('action', 'break_glass.granted')
            ->first());

        $this->assertNotNull($entry, "The association's own audit log must record it.");
        $this->assertSame((string) $grant->id, (string) $entry->subject_id);
        $this->assertStringContainsString('no receipt arrived', $entry->reason);
    }

    // --------------------------------------------------------------- the reads

    public function test_a_live_grant_shows_member_rows(): void
    {
        $this->inTenant(fn () => DB::table('members')->insert([
            'name' => 'Rokeya Begum',
            'mobile' => '01700000001',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        [$grant] = $this->liveGrant('members');

        $this->get("/platform/tenants/{$this->slug()}/data/members")
            ->assertOk()
            ->assertSee('Rokeya Begum')
            // The banner is part of the feature, not decoration.
            ->assertSee('break-glass grant', false);

        $this->assertSame(1, $grant->fresh()->reads);
    }

    /**
     * A grant names ONE area.
     *
     * Otherwise "read-only access to payments" is read-only access to
     * everything, and the reason written on the request describes something
     * other than what was done.
     */
    public function test_a_payments_grant_does_not_open_members(): void
    {
        $this->liveGrant('payments');

        $this->get("/platform/tenants/{$this->slug()}/data/payments")->assertOk();
        $this->get("/platform/tenants/{$this->slug()}/data/members")->assertForbidden();
    }

    /** Signed in, MFA passed, no grant: still nothing. */
    public function test_an_operator_without_a_grant_sees_no_rows(): void
    {
        $this->signIn($this->operator('nosy@platform.test'));

        $this->get("/platform/tenants/{$this->slug()}/data/members")->assertForbidden();
        $this->get("/platform/tenants/{$this->slug()}/data/payments")->assertForbidden();
    }

    /**
     * A grant belongs to the operator who asked for it.
     *
     * Otherwise one approved request is a key the whole team holds, and the
     * name in the association's notice is not the name of whoever read the data.
     */
    public function test_another_operator_cannot_ride_someone_elses_grant(): void
    {
        [, , $approver] = $this->liveGrant('members');

        $this->signIn($approver);
        $this->get("/platform/tenants/{$this->slug()}/data/members")->assertForbidden();
    }

    // ------------------------------------------------------------- it closes

    /**
     * EXPIRY IS ASKED OF THE CLOCK, not of a scheduled sweep.
     *
     * No job runs in this test. The grant simply stops working, which is the
     * only design where a queue worker falling over does not silently leave an
     * association's records open.
     */
    public function test_a_grant_stops_working_when_its_time_is_up(): void
    {
        [$grant] = $this->liveGrant('members');

        $this->get("/platform/tenants/{$this->slug()}/data/members")->assertOk();

        $this->travel(31)->minutes();

        $this->get("/platform/tenants/{$this->slug()}/data/members")->assertForbidden();
        $this->assertTrue($grant->fresh()->hasExpired());
    }

    public function test_a_live_grant_can_be_ended_early(): void
    {
        [$grant] = $this->liveGrant('members');

        $this->post("/platform/break-glass/{$grant->id}/revoke", [
            'note' => 'Question answered, no longer needed.',
        ])->assertRedirect(route('platform.break-glass'));

        $this->assertSame(BreakGlassGrant::STATUS_REVOKED, $grant->fresh()->status);
        $this->get("/platform/tenants/{$this->slug()}/data/members")->assertForbidden();

        // The window it cut short, not the window as it stands after revoking -
        // "ended 40 minutes early" is the fact worth having in a review.
        $entry = OperatorAuditLog::where('action', 'break_glass.revoked')->latest('id')->first();

        $this->assertSame($grant->expires_at->toDateTimeString(), $entry->before['expires_at']);
    }

    // ------------------------------------------------------------ the record

    /** Every step, in the operator trail — asked, approved, notified, read. */
    public function test_the_whole_lifecycle_is_recorded(): void
    {
        [$grant] = $this->liveGrant('members');

        $this->get("/platform/tenants/{$this->slug()}/data/members")->assertOk();

        $actions = OperatorAuditLog::where('tenant_id', $this->slug())->pluck('action');

        foreach ([
            'break_glass.requested',
            'break_glass.approved',
            'break_glass.notified',
            'break_glass.read_members',
        ] as $action) {
            $this->assertContains($action, $actions->all(), "Missing audit entry: {$action}");
        }

        $read = OperatorAuditLog::where('action', 'break_glass.read_members')->latest('id')->first();

        $this->assertSame($grant->id, $read->after['grant_id']);
        $this->assertSame('asker@platform.test', $read->operator_email);
    }

    /**
     * What was SEARCHED for, not only that a page was opened.
     *
     * "Read the member list" and "searched the member list for a surname" are
     * different acts, and only one of them looks like somebody answering a
     * support ticket.
     */
    public function test_the_search_term_is_recorded(): void
    {
        $this->liveGrant('members');

        $this->get("/platform/tenants/{$this->slug()}/data/members?q=Rokeya")->assertOk();

        $read = OperatorAuditLog::where('action', 'break_glass.read_members')->latest('id')->first();

        $this->assertSame('Rokeya', $read->after['search']);
    }

    // ------------------------------------------------------------ the refusals

    public function test_a_reason_that_says_nothing_is_refused(): void
    {
        $this->signIn($this->operator('asker@platform.test'));

        $this->post("/platform/tenants/{$this->slug()}/break-glass", [
            'scope' => 'members',
            'minutes' => 30,
            'reason' => 'support',
        ])->assertSessionHasErrors('reason');

        $this->assertSame(0, BreakGlassGrant::count());
    }

    /** A duration typed rather than chosen is not a duration this offers. */
    public function test_an_arbitrary_duration_is_refused(): void
    {
        $this->signIn($this->operator('asker@platform.test'));

        $this->post("/platform/tenants/{$this->slug()}/break-glass", [
            'scope' => 'members',
            'minutes' => 10080,
            'reason' => 'I would like access for a week please, it is more convenient.',
        ])->assertSessionHasErrors('minutes');
    }

    public function test_a_refusal_needs_a_reason_of_its_own(): void
    {
        $asker = $this->operator('asker@platform.test');
        $grant = $this->request($asker);

        $this->signIn($this->operator('approver@platform.test'));

        $this->post("/platform/break-glass/{$grant->id}/decide", ['decision' => 'deny'])
            ->assertSessionHasErrors('note');

        $this->post("/platform/break-glass/{$grant->id}/decide", [
            'decision' => 'deny',
            'note' => 'Ask the association to check their own records first.',
        ])->assertRedirect(route('platform.break-glass'));

        $this->assertSame(BreakGlassGrant::STATUS_DENIED, $grant->fresh()->status);
    }

    public function test_a_denied_grant_cannot_be_approved_afterwards(): void
    {
        $asker = $this->operator('asker@platform.test');
        $grant = $this->request($asker);

        $approver = $this->operator('approver@platform.test');
        $this->signIn($approver);

        $this->post("/platform/break-glass/{$grant->id}/decide", [
            'decision' => 'deny',
            'note' => 'Not a question that needs member data.',
        ]);

        $this->post("/platform/break-glass/{$grant->id}/decide", ['decision' => 'approve'])
            ->assertSessionHasErrors('break_glass');

        $this->assertSame(BreakGlassGrant::STATUS_DENIED, $grant->fresh()->status);
    }

    public function test_one_open_request_at_a_time_per_area(): void
    {
        $asker = $this->operator('asker@platform.test');
        $this->request($asker);

        $this->post("/platform/tenants/{$this->slug()}/break-glass", [
            'scope' => 'members',
            'minutes' => 30,
            'reason' => 'The same question again, asked a second time by mistake.',
        ])->assertSessionHasErrors('break_glass');

        $this->assertSame(1, BreakGlassGrant::count());
    }

    // ------------------------------------------------------------- the pages

    /**
     * The two pages actually render.
     *
     * Worth its own test because everything else here asserts on redirects and
     * database rows, and a Blade file that throws on a live console is a
     * feature that works perfectly and cannot be used. One did, during this
     * build - an `@if` with no space in front of it.
     */
    public function test_the_queue_and_the_tenant_page_render(): void
    {
        $asker = $this->operator('asker@platform.test');
        $this->request($asker);

        $this->get('/platform/break-glass')
            ->assertOk()
            // Their own request, with the reason it was asked for.
            ->assertSee('no receipt arrived')
            // And the reason there is no button on it.
            ->assertSee('You asked for this one');

        $this->get("/platform/tenants/{$this->slug()}")
            ->assertOk()
            ->assertSee("Reading this association's records", false);

        // A second operator sees the decision, not the excuse.
        $this->signIn($this->operator('approver@platform.test'));

        $this->get('/platform/break-glass')
            ->assertOk()
            ->assertSee('Approve, notify and open');
    }

    /** The console is a door; this is the door inside it. Both stay shut. */
    public function test_the_data_routes_do_not_exist_when_the_console_is_off(): void
    {
        config(['platform.console_enabled' => false]);

        $this->get("/platform/tenants/{$this->slug()}/data/members")->assertNotFound();
        $this->get('/platform/break-glass')->assertNotFound();
    }
}
