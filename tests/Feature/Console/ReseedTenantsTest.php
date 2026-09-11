<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Tenant\Ledger;
use App\Services\TenantSeedService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * `tenants:reseed` - bringing an existing association up to date with a release.
 *
 * THE GAP IT CLOSES. `TenantSeedService` runs at provisioning and nowhere else,
 * so a release that adds a permission reaches nobody: every association created
 * before that day lacks the row, and the route behind it answers 403 to
 * `superadmin` included. Found on 2026-09-11 by adding `reports.voucherwise`.
 *
 * THE PROPERTY THAT MAKES IT SAFE, and the one worth pinning hardest: a
 * permission is granted to a role only in the run that CREATES it. An
 * association's own configuration is never overruled - not the permission they
 * took away from `admin`, and not the one they added to `operator`.
 *
 * That second case is a REGRESSION TEST as much as a feature test. The service
 * used to call `syncPermissions`, which replaces a role's permissions with
 * exactly the list given: every re-seed silently stripped whatever an
 * association had added to `operator`, whose own comment invites them to widen
 * it. The existing test named "reseeding is additive and loses nothing" checked
 * only that the permission ROW survived, never the grant, so it passed
 * throughout.
 */
class ReseedTenantsTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->inTenant(fn () => app(TenantSeedService::class)->seedAll());
    }

    /** An association provisioned before a release that added a permission. */
    private function forgetPermission(string $name): void
    {
        $this->inTenant(fn () => Permission::where('name', $name)->delete());
    }

    /**
     * Asked INSIDE the tenant, every time.
     *
     * The first version returned the Role out of `inTenant` and called
     * `hasPermissionTo` on it afterwards - which reads the `permissions` table
     * on whatever connection is current, and by then that is the central
     * database. The error said so plainly; it just did not look like a test
     * bug, because the model came back perfectly well.
     */
    private function roleHas(string $role, string $permission): bool
    {
        return $this->inTenant(
            fn () => Role::findByName($role, TenantSeedService::GUARD)->hasPermissionTo($permission)
        );
    }

    // ------------------------------------------------------------ the gap

    public function test_it_adds_a_permission_the_association_was_provisioned_before(): void
    {
        $this->forgetPermission('reports.voucherwise');

        $this->artisan('tenants:reseed', ['--permissions-only' => true])
            ->expectsOutputToContain('reports.voucherwise')
            ->assertSuccessful();

        $this->inTenant(function () {
            self::assertTrue(Permission::where('name', 'reports.voucherwise')->exists());
        });

        self::assertTrue($this->roleHas('superadmin', 'reports.voucherwise'));
        self::assertTrue($this->roleHas('admin', 'reports.voucherwise'));

        /*
         * And NOT to operator, which is the narrow role. A command that handed
         * every new permission to every role would be a much simpler command
         * and would quietly widen the one role an association relies on being
         * small.
         */
        self::assertFalse($this->roleHas('operator', 'reports.voucherwise'));
    }

    /** Role administration stays off `admin`, as it is at provisioning. */
    public function test_a_new_role_or_user_permission_does_not_reach_admin(): void
    {
        $this->forgetPermission('roles.create');

        $this->artisan('tenants:reseed', ['--permissions-only' => true])->assertSuccessful();

        self::assertTrue($this->roleHas('superadmin', 'roles.create'));
        self::assertFalse($this->roleHas('admin', 'roles.create'));
    }

    public function test_running_it_twice_changes_nothing_the_second_time(): void
    {
        $this->forgetPermission('reports.voucherwise');

        $this->artisan('tenants:reseed', ['--permissions-only' => true])->assertSuccessful();

        $this->artisan('tenants:reseed', ['--permissions-only' => true])
            ->expectsOutputToContain('already up to date')
            ->assertSuccessful();
    }

    // ------------------------------------- what it must never overrule

    /**
     * A permission the association took AWAY stays away.
     *
     * If their admins do not hold `members.suspend`, somebody decided that. A
     * deploy that hands it back has overruled them, and nobody would see it
     * happen.
     */
    public function test_it_does_not_restore_a_permission_the_association_removed(): void
    {
        $this->inTenant(
            fn () => Role::findByName('admin', TenantSeedService::GUARD)
                ->revokePermissionTo('members.suspend')
        );

        $this->artisan('tenants:reseed')->assertSuccessful();

        self::assertFalse($this->roleHas('admin', 'members.suspend'));
    }

    /**
     * A permission the association ADDED survives - the regression.
     *
     * `operator` is seeded deliberately narrow and its own comment says
     * associations widen it themselves. Under `syncPermissions` every re-seed
     * threw that away.
     */
    public function test_it_keeps_a_permission_the_association_added_to_a_role(): void
    {
        $this->inTenant(function () {
            $custom = Permission::findOrCreate('custom.local-report', TenantSeedService::GUARD);
            Role::findByName('operator', TenantSeedService::GUARD)->givePermissionTo($custom);
        });

        $this->artisan('tenants:reseed')->assertSuccessful();

        self::assertTrue($this->roleHas('operator', 'custom.local-report'));
        self::assertTrue($this->roleHas('operator', 'shares.transfer'));
    }

    // ----------------------------------------------------------- the flags

    /**
     * `--permissions-only` leaves the chart of accounts alone.
     *
     * A deploy adding a permission has no business creating ledgers in books
     * somebody has been editing for two years, even by `firstOrCreate`.
     */
    public function test_permissions_only_leaves_the_chart_of_accounts_alone(): void
    {
        $this->inTenant(fn () => Ledger::where('name', 'Bank Charges')->delete());

        $this->artisan('tenants:reseed', ['--permissions-only' => true])->assertSuccessful();

        $this->inTenant(function () {
            self::assertFalse(Ledger::where('name', 'Bank Charges')->exists());
        });

        /*
         * Without the flag it is put back - and SAID. A run that quietly
         * created a ledger while printing "already up to date" would be worse
         * than one that printed nothing at all, which is why the command reads
         * what is pending before it writes.
         */
        $this->artisan('tenants:reseed')
            ->expectsOutputToContain('+ ledger Bank Charges')
            ->assertSuccessful();

        $this->inTenant(function () {
            self::assertTrue(Ledger::where('name', 'Bank Charges')->exists());
        });
    }

    /** The dry run reports, and writes nothing at all. */
    public function test_the_dry_run_writes_nothing(): void
    {
        $this->forgetPermission('reports.voucherwise');

        $this->artisan('tenants:reseed', ['--dry-run' => true])
            ->expectsOutputToContain('nothing will be written')
            /*
             * ONE EXPECTATION PER LINE OF OUTPUT, and they must not overlap.
             *
             * `expectsOutputToContain` is matched per WRITE, through Mockery,
             * and a write is consumed by the first expectation whose substring
             * it contains. Asking for `reports.voucherwise` and then for
             * `superadmin, admin` fails even though both are printed: the one
             * line carrying the second is swallowed by the first, which has
             * already been satisfied by the line above it.
             *
             * The roles are the part somebody reviewing a deploy actually
             * needs, which is why this asks for the whole line.
             */
            ->expectsOutputToContain('reports.voucherwise -> superadmin, admin')
            ->assertSuccessful();

        $this->inTenant(function () {
            self::assertFalse(Permission::where('name', 'reports.voucherwise')->exists());
        });
    }

    /** A slug that matches nothing is a typo worth seeing, not a failed deploy. */
    public function test_an_unknown_slug_warns_and_succeeds(): void
    {
        $this->artisan('tenants:reseed', ['--tenant' => 'no-such-association'])
            ->expectsOutputToContain('No active or suspended association with the slug')
            ->assertSuccessful();
    }

    /**
     * A tenant with no database yet is skipped rather than failing the deploy.
     *
     * `provisioning` and `failed` rows may have nothing to reach into, and an
     * exit code that goes red over a tenant that was never ready is one people
     * learn to ignore. `suspended` is deliberately NOT skipped: a suspension is
     * about billing, and an association that comes back must not return to a
     * database two releases behind the code serving it.
     */
    public function test_it_skips_an_association_that_is_not_ready(): void
    {
        $this->tenant->update(['status' => \App\Models\Tenant::STATUS_PROVISIONING]);

        $this->artisan('tenants:reseed', ['--permissions-only' => true])
            ->expectsOutputToContain('No associations to seed')
            ->assertSuccessful();

        $this->tenant->update(['status' => \App\Models\Tenant::STATUS_SUSPENDED]);
        $this->forgetPermission('reports.voucherwise');

        $this->artisan('tenants:reseed', ['--permissions-only' => true])
            ->expectsOutputToContain('reports.voucherwise')
            ->assertSuccessful();

        $this->tenant->update(['status' => \App\Models\Tenant::STATUS_ACTIVE]);
    }

    public function test_it_can_be_restricted_to_one_association(): void
    {
        $this->forgetPermission('reports.voucherwise');

        $this->artisan('tenants:reseed', [
            '--tenant' => $this->slug(),
            '--permissions-only' => true,
        ])->assertSuccessful();

        self::assertTrue($this->roleHas('superadmin', 'reports.voucherwise'));
    }

    // ------------------------------------------------------- the rule itself

    /**
     * The role rule is stated once and both callers read it.
     *
     * Provisioning and this command must agree about who gets what, and the way
     * anybody would find out they had drifted is an association whose admins
     * silently cannot reach a feature every other association has.
     */
    public function test_the_role_rule_matches_what_provisioning_produces(): void
    {
        $this->inTenant(function () {
            foreach (TenantSeedService::permissionCatalogue() as $permissions) {
                foreach ($permissions as $name) {
                    $expected = TenantSeedService::rolesHolding($name);

                    foreach (TenantSeedService::ROLES as $role) {
                        self::assertSame(
                            in_array($role, $expected, true),
                            Role::findByName($role, TenantSeedService::GUARD)->hasPermissionTo($name),
                            "Role [{$role}] disagrees with rolesHolding() about [{$name}].",
                        );
                    }
                }
            }
        });
    }
}
