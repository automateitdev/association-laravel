<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Models\Tenant\AccountCategory;
use App\Models\Tenant\Ledger;
use App\Models\Tenant\Setting;
use App\Models\User;
use App\Services\TenantSeedService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * What a brand-new association gets before anyone logs in (FR-TEN-6).
 */
class TenantSeedTest extends TenantTestCase
{
    private function seedTenant(): void
    {
        $this->inTenant(fn () => app(TenantSeedService::class)->seedAll());
    }

    public function test_settings_are_seeded_to_preserve_legacy_behaviour(): void
    {
        $this->seedTenant();

        $this->inTenant(function () {
            // M-9: a migrating association must not find its fines changed on
            // day one. 100/- per period, suspension at 3, exactly as the legacy
            // hard-coded constants.
            $this->assertSame('100.00', Setting::get(Setting::FINE_RATE));
            $this->assertSame(3, Setting::get(Setting::SUSPENSION_THRESHOLD));
        });
    }

    /**
     * The accounting half of ADR-0005. If a new association started with one
     * combined income account, the platform would have lost the instalment/fine
     * distinction before it collected a single taka.
     */
    public function test_instalment_and_fine_income_are_separate_ledgers(): void
    {
        $this->seedTenant();

        $this->inTenant(function () {
            $subscription = Ledger::where('name', 'Subscription Income')->first();
            $fine = Ledger::where('name', 'Fine Income')->first();

            $this->assertNotNull($subscription);
            $this->assertNotNull($fine);
            $this->assertNotSame($subscription->id, $fine->id);
        });
    }

    public function test_the_chart_of_accounts_covers_all_five_account_types(): void
    {
        $this->seedTenant();

        $this->inTenant(function () {
            $types = AccountCategory::pluck('type')->sort()->values()->all();

            $this->assertSame(
                ['asset', 'equity', 'expense', 'income', 'liability'],
                $types
            );
        });
    }

    public function test_roles_are_seeded_with_the_expected_reach(): void
    {
        $this->seedTenant();

        $this->inTenant(function () {
            $total = Permission::count();

            $this->assertGreaterThan(40, $total);

            // superadmin holds everything, always - including permissions added
            // by a later release.
            $this->assertSame($total, Role::findByName('superadmin', 'web')->permissions()->count());

            // admin holds everything except role and user administration.
            $adminPermissions = Role::findByName('admin', 'web')->permissions()->pluck('name');
            $this->assertTrue($adminPermissions->contains('payments.approve'));
            $this->assertFalse($adminPermissions->contains('roles.delete'));
            $this->assertFalse($adminPermissions->contains('users.create'));

            /*
             * The operator: the landing page, two of its cards, and shares.
             *
             * Named rather than counted. A count says "5" and stops being read
             * the moment somebody changes it; what matters about this role is
             * WHICH five - and in particular that the two dashboard cards it
             * holds are the operational ones, with neither of the money cards
             * among them. That is the distinction the whole per-card permission
             * split exists to make.
             */
            $operator = Role::findByName('operator', 'web')->permissions()->pluck('name');

            $this->assertEqualsCanonicalizing([
                'dashboard.view',
                'dashboard.approvals',
                'dashboard.members',
                'shares.view',
                'shares.transfer',
            ], $operator->all());

            $this->assertFalse($operator->contains('dashboard.collections'));
            $this->assertFalse($operator->contains('dashboard.outstanding'));
        });
    }

    /**
     * FR-RBAC-3 / defect D-11. The legacy seeder deletes the whole permissions
     * table before rebuilding it, so a permission granted by hand is lost and it
     * is unsafe against live data. Re-seeding here must be additive.
     */
    public function test_reseeding_is_additive_and_loses_nothing(): void
    {
        $this->seedTenant();

        $this->inTenant(function () {
            // A permission the association added by hand.
            $custom = Permission::findOrCreate('custom.local-report', 'web');
            Role::findByName('operator', 'web')->givePermissionTo($custom);

            $before = Permission::count();

            // And one the association took AWAY from a seeded role.
            Role::findByName('admin', 'web')->revokePermissionTo('members.suspend');

            app(TenantSeedService::class)->seedAll();

            $this->assertSame($before, Permission::count(), 'Re-seeding must not drop permissions.');
            $this->assertTrue(
                Permission::where('name', 'custom.local-report')->exists(),
                'A hand-added permission must survive a re-seed.'
            );

            /*
             * THE GRANT, NOT ONLY THE ROW - and this is the assertion that was
             * missing.
             *
             * This test has been named "additive and loses nothing" since it
             * was written, and checked only that the permission ROW survived.
             * The service was calling `syncPermissions`, which replaces a
             * role's permissions with exactly the list given, so every re-seed
             * stripped whatever an association had added to `operator` - whose
             * own comment in the seeder invites them to widen it - and put back
             * whatever they had removed from `admin`. The row survived either
             * way, so nothing ever failed.
             */
            $this->assertTrue(
                Role::findByName('operator', 'web')->hasPermissionTo('custom.local-report'),
                'A permission the association granted to a role must survive a re-seed.'
            );

            $this->assertFalse(
                Role::findByName('admin', 'web')->hasPermissionTo('members.suspend'),
                'A permission the association removed must not be handed back.'
            );
        });
    }

    public function test_seeding_twice_does_not_duplicate_the_chart_of_accounts(): void
    {
        $this->seedTenant();

        $this->inTenant(function () {
            $before = Ledger::count();

            app(TenantSeedService::class)->seedAll();

            $this->assertSame($before, Ledger::count());
        });
    }

    /**
     * Nobody - not us, not the operator - ever knows a password the
     * association's own administrator did not choose.
     */
    public function test_the_first_superadmin_is_created_without_a_known_password(): void
    {
        $this->inTenant(function () {
            app(TenantSeedService::class)->seedAll();

            $user = User::create([
                'name' => 'First Admin',
                'email' => 'first@assoc.test',
                'password' => \Illuminate\Support\Str::password(40),
            ]);
            $user->assignRole('superadmin');

            $this->assertTrue($user->hasRole('superadmin'));
            $this->assertTrue($user->can('payments.approve'));
            $this->assertTrue($user->can('users.create'));
        });
    }
}
