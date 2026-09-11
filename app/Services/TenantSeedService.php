<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\AccountCategory;
use App\Models\Tenant\AccountGroup;
use App\Models\Tenant\Ledger;
use App\Models\Tenant\Setting;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Everything a brand-new association needs before staff can log in.
 *
 * Runs inside the tenant context during provisioning (FR-TEN-6). Idempotent
 * throughout: re-running it must never duplicate a ledger or drop a permission.
 */
class TenantSeedService
{
    public const GUARD = 'web';

    /**
     * The permission catalogue.
     *
     * Ported from the legacy menu structure (SRS Appendix A) but renamed to
     * dot-notation. The legacy names are display strings with a typo baked in -
     * "Deashboard View" - and carrying a misspelling forever because it is
     * awkward to change is how it survived this long. The migration maps old
     * names to these; see the mapping table in the migration plan.
     *
     * @return array<string, array<string>>
     */
    public static function permissionCatalogue(): array
    {
        return [
            'Dashboard' => ['dashboard.view'],

            'Members' => [
                'members.view', 'members.create', 'members.edit',
                'members.approve', 'members.suspend',
                'associator.view', 'associator.edit',
                'nominees.manage',
                'profile-updates.view', 'profile-updates.decide',
                'fines.adjust',
            ],

            'Fees' => [
                'fee-setups.view', 'fee-setups.create', 'fee-setups.edit',
                'fee-assigns.view', 'fee-assigns.create',
                'collections.view', 'collections.create',
                'payments.view', 'payments.approve',
            ],

            'Accounting' => [
                'ledgers.view', 'ledgers.create', 'ledgers.edit',
                'vouchers.view', 'vouchers.create', 'vouchers.approve',
            ],

            'Shares' => ['shares.view', 'shares.transfer'],

            /*
             * One permission per report, matching what each one exposes. The
             * three statements are not the same audience as the member-by-member
             * reports: what the association is worth and whether its books
             * balance is a committee's business, not the counter's.
             */
            'Reports' => [
                'reports.paid', 'reports.due', 'reports.income-statement',
                'reports.balance-sheet', 'reports.trial-balance', 'reports.cash-summary',
                'reports.voucherwise',
                'reports.inconsistency', 'reports.export',
            ],

            'Messaging' => ['sms.send', 'sms.templates', 'sms.recharge'],

            'Administration' => [
                'settings.view', 'settings.edit',
                'roles.view', 'roles.create', 'roles.edit', 'roles.delete',
                'users.view', 'users.create', 'users.edit', 'users.delete',
            ],
        ];
    }

    public function seedAll(): void
    {
        DB::transaction(function () {
            $this->seedSettings();
            $this->seedChartOfAccounts();
            $this->seedRolesAndPermissions();
        });
    }

    /**
     * Seeded to preserve legacy behaviour exactly (M-9). An association that
     * migrates must not find its fines have changed on day one.
     */
    public function seedSettings(): void
    {
        foreach (Setting::defaults() as $key => $spec) {
            Setting::query()->firstOrCreate(
                ['key' => $key],
                ['value' => $spec['value'], 'group' => $spec['group']]
            );
        }
    }

    /**
     * A default chart derived from COCSOL's, editable afterwards (A-3).
     *
     * Note that instalment income and fine income are SEPARATE ledgers from the
     * outset. That separation is the accounting half of ADR-0005 - if a new
     * association starts with one combined income account, the platform has
     * already lost the distinction it exists to keep.
     */
    public function seedChartOfAccounts(): void
    {
        $structure = [
            'Assets' => ['type' => 'asset', 'groups' => [
                'Cash and Bank' => ['Cash in Hand', 'Bank Account'],
                'Receivables' => ['Subscriptions Receivable'],
            ]],
            'Liabilities' => ['type' => 'liability', 'groups' => [
                'Payables' => ['Sundry Payables'],
            ]],
            'Equity' => ['type' => 'equity', 'groups' => [
                'Member Funds' => ['Share Capital'],
            ]],
            'Income' => ['type' => 'income', 'groups' => [
                'Subscriptions' => ['Subscription Income', 'Admission Fee Income'],
                'Penalties' => ['Fine Income'],
            ]],
            'Expenses' => ['type' => 'expense', 'groups' => [
                'Administrative' => ['Office Expenses', 'Bank Charges'],
            ]],
        ];

        foreach ($structure as $categoryName => $spec) {
            $category = AccountCategory::query()->firstOrCreate(
                ['name' => $categoryName],
                ['type' => $spec['type']]
            );

            foreach ($spec['groups'] as $groupName => $ledgers) {
                $group = AccountGroup::query()->firstOrCreate([
                    'account_category_id' => $category->id,
                    'name' => $groupName,
                ]);

                foreach ($ledgers as $ledgerName) {
                    Ledger::query()->firstOrCreate([
                        'account_group_id' => $group->id,
                        'name' => $ledgerName,
                    ]);
                }
            }
        }
    }

    /**
     * Additive and idempotent (FR-RBAC-3).
     *
     * The legacy seeder deletes the entire permissions table before rebuilding
     * it, so any permission granted by hand is lost and it is unsafe to run
     * against live data (defect D-11). Nothing here deletes anything.
     */
    public function seedRolesAndPermissions(): void
    {
        $all = [];

        foreach (self::permissionCatalogue() as $group => $permissions) {
            foreach ($permissions as $name) {
                Permission::findOrCreate($name, self::GUARD);
                $all[] = $name;
            }
        }

        $superadmin = Role::findOrCreate('superadmin', self::GUARD);
        $admin = Role::findOrCreate('admin', self::GUARD);
        $operator = Role::findOrCreate('operator', self::GUARD);

        // Everything, always - including permissions added by a later release.
        $superadmin->syncPermissions($all);

        // Operational, minus role and user administration.
        $admin->syncPermissions(array_values(array_filter(
            $all,
            fn (string $p) => ! str_starts_with($p, 'roles.') && ! str_starts_with($p, 'users.')
        )));

        // Matches the legacy operator: dashboard and share transfer only.
        // Deliberately narrow; associations widen it themselves if they want to.
        $operator->syncPermissions(['dashboard.view', 'shares.view', 'shares.transfer']);
    }
}
