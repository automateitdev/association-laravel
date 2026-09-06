<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Tenant\Member;
use App\Models\User;
use App\Services\TenantSeedService;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Staff accounts and roles (FR-RBAC-1, FR-RBAC-2).
 *
 * MOST OF THIS FILE IS ABOUT LOCKOUT
 * ----------------------------------
 * Creating a user is the easy half and barely worth a test. What is worth
 * testing is every path that could leave an association with nobody able to
 * administer it, because there is no support desk to ring and no cross-tenant
 * administrator: recovery means someone editing the database by hand.
 *
 * So: the last superadmin cannot be deleted or demoted, you cannot delete your
 * own account, the seeded roles cannot be removed, superadmin cannot be
 * narrowed, and a role somebody holds cannot be deleted out from under them.
 */
class StaffAdminTest extends TenantTestCase
{
    private function headers(?string $token = null): array
    {
        return array_filter([
            'X-Tenant' => $this->slug(),
            'Accept' => 'application/json',
            'Authorization' => $token ? "Bearer {$token}" : null,
        ]);
    }

    /** @return array{token: string, id: int} */
    private function superadmin(): array
    {
        return $this->inTenant(function () {
            app(TenantSeedService::class)->seedAll();

            static $sequence = 0;
            $sequence++;

            $user = User::create([
                'name' => "Admin {$sequence}",
                'email' => "admin{$sequence}@assoc.test",
                'password' => 'secret-password',
            ]);
            $user->assignRole('superadmin');

            return [
                'token' => $user->createToken('test', $user->getAllPermissions()->pluck('name')->all())
                    ->plainTextToken,
                'id' => $user->id,
            ];
        });
    }

    // ---- onboarding an office --------------------------------------------

    public function test_a_second_staff_account_can_be_created_and_can_sign_in(): void
    {
        $admin = $this->superadmin();

        $created = $this->withHeaders($this->headers($admin['token']))
            ->postJson('/api/v1/staff/users', [
                'name' => 'Counter Clerk',
                'email' => 'clerk@assoc.test',
                'password' => 'clerk-password',
                'role' => 'operator',
            ])
            ->assertCreated()
            ->assertJsonPath('data.role', 'operator')
            ->json('data.id');

        $this->assertNotNull($created);

        /*
         * The account is only real if it can get in. Creating a row that cannot
         * authenticate would satisfy the endpoint and not the requirement.
         */
        $this->withHeaders($this->headers())
            ->postJson('/api/v1/auth/login', [
                'login' => 'clerk@assoc.test',
                'password' => 'clerk-password',
            ])
            ->assertSuccessful();
    }

    public function test_a_new_account_can_do_only_what_its_role_allows(): void
    {
        $admin = $this->superadmin();
        $headers = $this->headers($admin['token']);

        $this->withHeaders($headers)->postJson('/api/v1/staff/roles', [
            'name' => 'cashier',
            'permissions' => ['dashboard.view', 'members.view'],
        ])->assertCreated();

        $this->withHeaders($headers)->postJson('/api/v1/staff/users', [
            'name' => 'Cashier',
            'email' => 'cashier@assoc.test',
            'password' => 'cashier-password',
            'role' => 'cashier',
        ])->assertCreated();

        $token = $this->withHeaders($this->headers())
            ->postJson('/api/v1/auth/login', [
                'login' => 'cashier@assoc.test',
                'password' => 'cashier-password',
            ])
            ->json('data.token');

        $this->withHeaders($this->headers($token))
            ->getJson('/api/v1/staff/members')
            ->assertSuccessful();

        // Not granted, so refused - the role is doing real work rather than
        // being a label on the account.
        $this->withHeaders($this->headers($token))
            ->getJson('/api/v1/staff/fee-setups')
            ->assertForbidden();
    }

    // ---- one person, two accounts ----------------------------------------

    private function member(string $email, string $name = 'Rokeya Begum'): Member
    {
        return $this->inTenant(function () use ($email, $name) {
            $member = Member::create([
                'name' => $name,
                'email' => $email,
                'mobile' => '0170000'.random_int(1000, 9999),
                'status' => 'active',
            ]);

            // The number is on associators_infos and assigned by the office
            // after the fact - which is why the notice has to go looking for it
            // rather than reading a column off the member.
            $member->associatorInfo()->create(['membership_no' => 'M-0042']);

            return $member;
        });
    }

    /**
     * THE COLLISION IS ALLOWED, and that is the requirement (SRS OD-4).
     *
     * A treasurer is a member of the association whose books she keeps. Refusing
     * the staff account because a member already holds that email would refuse
     * the ordinary case, and there is no second email to fall back on.
     */
    public function test_an_email_that_is_already_a_members_is_not_refused(): void
    {
        $admin = $this->superadmin();
        $this->member('treasurer@assoc.test');

        $this->withHeaders($this->headers($admin['token']))
            ->postJson('/api/v1/staff/users', [
                'name' => 'Rokeya Begum',
                'email' => 'treasurer@assoc.test',
                'password' => 'staff-password',
                'role' => 'operator',
            ])
            ->assertCreated()
            ->assertJsonPath('data.email', 'treasurer@assoc.test');
    }

    /**
     * ...but it is SAID, because of what it costs at sign-in.
     *
     * Both accounts are tried and the password picks one, so two different
     * passwords resolve silently. One password opening both is ambiguous, and
     * she is then asked which she means every single time - which the person
     * creating the account can prevent, but only while choosing this password.
     */
    public function test_creating_one_warns_that_the_email_is_already_a_members(): void
    {
        $admin = $this->superadmin();
        $this->member('treasurer@assoc.test');

        $notice = $this->withHeaders($this->headers($admin['token']))
            ->postJson('/api/v1/staff/users', [
                'name' => 'Rokeya Begum',
                'email' => 'treasurer@assoc.test',
                'password' => 'staff-password',
                'role' => 'operator',
            ])
            ->assertCreated()
            ->json('meta.notice');

        $this->assertNotNull($notice, 'The collision was created with nothing said about it.');

        // WHO, so it can be recognised as the right person rather than a
        // stranger who happens to share an address.
        $this->assertStringContainsString('Rokeya Begum', (string) $notice);
        $this->assertStringContainsString('M-0042', (string) $notice);

        // And what to do about it, which is the only actionable part.
        $this->assertStringContainsString('DIFFERENT', (string) $notice);
    }

    /** No collision, nothing said. A notice shown always is a notice ignored. */
    public function test_an_ordinary_account_is_created_with_no_notice(): void
    {
        $admin = $this->superadmin();
        $this->member('treasurer@assoc.test');

        $this->withHeaders($this->headers($admin['token']))
            ->postJson('/api/v1/staff/users', [
                'name' => 'Counter Clerk',
                'email' => 'clerk@assoc.test',
                'password' => 'clerk-password',
                'role' => 'operator',
            ])
            ->assertCreated()
            ->assertJsonPath('meta.notice', null);
    }

    /** Editing an account ONTO a member's email is the same event, later. */
    public function test_moving_an_email_onto_a_members_warns_the_same_way(): void
    {
        $admin = $this->superadmin();
        $this->member('treasurer@assoc.test');

        $id = $this->withHeaders($this->headers($admin['token']))
            ->postJson('/api/v1/staff/users', [
                'name' => 'Rokeya Begum',
                'email' => 'rokeya@assoc.test',
                'password' => 'staff-password',
                'role' => 'operator',
            ])
            ->assertJsonPath('meta.notice', null)
            ->json('data.id');

        $moved = $this->withHeaders($this->headers($admin['token']))
            ->putJson("/api/v1/staff/users/{$id}", ['email' => 'treasurer@assoc.test'])
            ->assertSuccessful()
            ->json('meta.notice');

        $this->assertStringContainsString('Rokeya Begum', (string) $moved);

        /*
         * Saving the same account again, with the email left where it is, says
         * nothing. A warning repeated on every edit of a long-standing account
         * is one nobody reads, including on the day it matters.
         */
        $this->withHeaders($this->headers($admin['token']))
            ->putJson("/api/v1/staff/users/{$id}", ['name' => 'Rokeya Begum Chowdhury'])
            ->assertSuccessful()
            ->assertJsonPath('meta.notice', null);
    }

    // ---- the lockout guards ----------------------------------------------

    public function test_the_last_superadmin_cannot_be_deleted(): void
    {
        $admin = $this->superadmin();

        $other = $this->inTenant(function () {
            $user = User::create([
                'name' => 'Operator',
                'email' => 'op@assoc.test',
                'password' => 'secret-password',
            ]);
            $user->assignRole('operator');

            return $user;
        });

        // Deleting somebody else is fine.
        $this->withHeaders($this->headers($admin['token']))
            ->deleteJson("/api/v1/staff/users/{$other->id}")
            ->assertSuccessful();

        // Deleting the only superadmin is not - and it is refused for that
        // reason rather than because it is the caller's own account.
        $second = $this->inTenant(function () {
            $user = User::create([
                'name' => 'Second admin',
                'email' => 'second@assoc.test',
                'password' => 'secret-password',
            ]);
            $user->assignRole('superadmin');

            return $user;
        });

        // Two superadmins now, so one may go.
        $this->withHeaders($this->headers($admin['token']))
            ->deleteJson("/api/v1/staff/users/{$second->id}")
            ->assertSuccessful();
    }

    public function test_the_only_superadmin_cannot_be_moved_off_the_role(): void
    {
        $admin = $this->superadmin();

        $this->withHeaders($this->headers($admin['token']))
            ->putJson("/api/v1/staff/users/{$admin['id']}", ['role' => 'operator'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'LAST_SUPERADMIN');

        // And the role is untouched, not half-applied.
        $this->inTenant(function () use ($admin) {
            $this->assertTrue(User::find($admin['id'])->hasRole('superadmin'));
        });
    }

    public function test_you_cannot_delete_the_account_you_are_signed_in_with(): void
    {
        $admin = $this->superadmin();

        $this->withHeaders($this->headers($admin['token']))
            ->deleteJson("/api/v1/staff/users/{$admin['id']}")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'CANNOT_DELETE_SELF');
    }

    public function test_deleting_an_account_revokes_its_tokens(): void
    {
        $admin = $this->superadmin();

        $victim = $this->inTenant(function () {
            $user = User::create([
                'name' => 'Leaver',
                'email' => 'leaver@assoc.test',
                'password' => 'secret-password',
            ]);
            $user->assignRole('operator');
            $user->createToken('phone', ['dashboard.view']);

            return $user;
        });

        $this->withHeaders($this->headers($admin['token']))
            ->deleteJson("/api/v1/staff/users/{$victim->id}")
            ->assertSuccessful();

        /*
         * A personal access token outlives the row it belongs to unless it is
         * removed - so a dismissed staff member would carry on using the app.
         */
        $this->inTenant(function () use ($victim) {
            $this->assertSame(
                0,
                DB::table('personal_access_tokens')
                    ->where('tokenable_id', $victim->id)
                    ->where('tokenable_type', User::class)
                    ->count()
            );
        });
    }

    // ---- roles ------------------------------------------------------------

    public function test_the_seeded_roles_cannot_be_deleted_or_renamed(): void
    {
        $admin = $this->superadmin();
        $headers = $this->headers($admin['token']);

        $operator = $this->inTenant(fn () => Role::findByName('operator', 'web'));

        $this->withHeaders($headers)
            ->deleteJson("/api/v1/staff/roles/{$operator->id}")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'ROLE_NOT_DELETABLE');

        $this->withHeaders($headers)
            ->putJson("/api/v1/staff/roles/{$operator->id}", ['name' => 'clerk'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'ROLE_NOT_RENAMEABLE');
    }

    public function test_superadmin_cannot_be_narrowed(): void
    {
        $admin = $this->superadmin();
        $role = $this->inTenant(fn () => Role::findByName('superadmin', 'web'));

        /*
         * It holds everything by definition, including permissions a later
         * release adds. Letting it be narrowed is how an association locks
         * itself out of its own administration.
         */
        $this->withHeaders($this->headers($admin['token']))
            ->putJson("/api/v1/staff/roles/{$role->id}", ['permissions' => ['dashboard.view']])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'ROLE_NOT_EDITABLE');
    }

    public function test_a_role_somebody_holds_cannot_be_deleted(): void
    {
        $admin = $this->superadmin();
        $headers = $this->headers($admin['token']);

        $role = $this->withHeaders($headers)->postJson('/api/v1/staff/roles', [
            'name' => 'temporary',
            'permissions' => ['dashboard.view'],
        ])->assertCreated()->json('data.id');

        $this->withHeaders($headers)->postJson('/api/v1/staff/users', [
            'name' => 'Holder',
            'email' => 'holder@assoc.test',
            'password' => 'holder-password',
            'role' => 'temporary',
        ])->assertCreated();

        // Those accounts would be left with no role at all - able to sign in
        // and do nothing, with no message saying why.
        $this->withHeaders($headers)
            ->deleteJson("/api/v1/staff/roles/{$role}")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'ROLE_IN_USE');
    }

    public function test_an_unheld_custom_role_can_be_deleted(): void
    {
        $admin = $this->superadmin();
        $headers = $this->headers($admin['token']);

        $role = $this->withHeaders($headers)->postJson('/api/v1/staff/roles', [
            'name' => 'spare',
            'permissions' => ['dashboard.view'],
        ])->assertCreated()->json('data.id');

        $this->withHeaders($headers)
            ->deleteJson("/api/v1/staff/roles/{$role}")
            ->assertSuccessful();
    }

    // ---- who may administer ----------------------------------------------

    public function test_an_operator_cannot_manage_staff_or_roles(): void
    {
        $this->superadmin();

        $token = $this->inTenant(function () {
            $user = User::create([
                'name' => 'Just an operator',
                'email' => 'justop@assoc.test',
                'password' => 'secret-password',
            ]);
            $user->assignRole('operator');

            return $user->createToken('test', $user->getAllPermissions()->pluck('name')->all())
                ->plainTextToken;
        });

        $this->withHeaders($this->headers($token))->getJson('/api/v1/staff/users')->assertForbidden();
        $this->withHeaders($this->headers($token))->getJson('/api/v1/staff/roles')->assertForbidden();
    }

    public function test_admin_cannot_manage_staff_or_roles_either(): void
    {
        $this->superadmin();

        $token = $this->inTenant(function () {
            $user = User::create([
                'name' => 'Association admin',
                'email' => 'assocadmin@assoc.test',
                'password' => 'secret-password',
            ]);
            $user->assignRole('admin');

            return $user->createToken('test', $user->getAllPermissions()->pluck('name')->all())
                ->plainTextToken;
        });

        /*
         * The seeded `admin` is operational - everything except role and user
         * administration. That split is deliberate and worth a test, because it
         * is the kind of thing a later "make admin a bit more useful" change
         * would quietly undo.
         */
        $this->withHeaders($this->headers($token))->getJson('/api/v1/staff/members')->assertSuccessful();
        $this->withHeaders($this->headers($token))->getJson('/api/v1/staff/users')->assertForbidden();
        $this->withHeaders($this->headers($token))->getJson('/api/v1/staff/roles')->assertForbidden();
    }
}
