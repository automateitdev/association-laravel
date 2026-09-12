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
            /*
             * ONE PERMISSION PER CARD, not one for the page.
             *
             * `dashboard.view` opens the screen. What is ON it is then chosen
             * card by card, because an association's answer differs per figure
             * and per role: a cashier may well need to see how many payments
             * are waiting and how many members are unadmitted, and have no
             * business seeing what the association is owed.
             *
             * These are DELIBERATELY NOT the report permissions. Gating the
             * cards on `members.view` and `reports.due` - which is what this
             * did first - ties two different questions together: whether
             * somebody may see a COUNT, and whether they may open the register
             * behind it. An operator who should see "2 to admit" would have had
             * to be given the whole member list to get it.
             *
             * So a card can be shown to somebody who cannot open what it counts.
             * The screen handles that: the figure appears, and it is not a door.
             */
            'Dashboard' => [
                'dashboard.view',
                'dashboard.approvals',
                'dashboard.members',
                'dashboard.collections',
                'dashboard.outstanding',
            ],

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
     * The default chart, as data.
     *
     * Its own method because `tenants:seed --dry-run` has to say what it would
     * ADD without adding it, and a structure declared inside the method that
     * writes it cannot be read by anything else.
     *
     * @return array<string, array{type: string, groups: array<string, list<string>>}>
     */
    public static function chartStructure(): array
    {
        return [
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
        foreach (self::chartStructure() as $categoryName => $spec) {
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
     * What a seed run would ADD to this association, without adding it.
     *
     * Every write in this service is `findOrCreate`, so "what is missing" is
     * the whole of what a run would do. Reported per kind because the three
     * mean different things on a deploy: a missing permission is a release
     * nobody applied, a missing ledger is a chart somebody edited, and a
     * missing setting is neither - it is a default that did not exist when the
     * association was provisioned.
     *
     * @return array{permissions: list<string>, settings: list<string>, ledgers: list<string>}
     */
    public function pending(): array
    {
        $settings = [];

        foreach (array_keys(Setting::defaults()) as $key) {
            if (! Setting::query()->where('key', $key)->exists()) {
                $settings[] = $key;
            }
        }

        $ledgers = [];
        $held = Ledger::query()->pluck('name')->all();

        foreach (self::chartStructure() as $spec) {
            foreach ($spec['groups'] as $names) {
                foreach ($names as $name) {
                    /*
                     * By NAME, not by group. The real seeder keys on the group
                     * too, so an association that moved `Bank Account` to a
                     * group of their own would have it created again - which is
                     * a pre-existing wrinkle in `seedChartOfAccounts`, not
                     * something this report should invent a second answer to.
                     * Naming it here so the difference is known rather than
                     * discovered.
                     */
                    if (! in_array($name, $held, true)) {
                        $ledgers[] = $name;
                    }
                }
            }
        }

        return [
            'permissions' => $this->missingPermissions(),
            'settings' => $settings,
            'ledgers' => $ledgers,
        ];
    }

    /** The roles every association starts with. */
    public const ROLES = ['superadmin', 'admin', 'operator'];

    /**
     * The operator role: the legacy operator, and deliberately narrow.
     *
     * Associations widen it themselves if they want to - which is precisely
     * why nothing here may write it back over their choice.
     *
     * @var list<string>
     */
    private const OPERATOR = [
        'dashboard.view',

        /*
         * The two cards a counter clerk works from: what is waiting to be
         * approved, and who is not admitted yet. NOT the money - what the
         * association holds and is owed is a committee's business, and an
         * operator who needs it can be given it.
         */
        'dashboard.approvals',
        'dashboard.members',

        'shares.view',
        'shares.transfer',
    ];

    /**
     * Which seeded roles a permission belongs to, ON THE DAY IT IS CREATED.
     *
     * ONE STATEMENT OF THE RULE, because it has two callers: provisioning, and
     * `tenants:seed`, which brings an association created before a release up
     * to date with it. Two copies would drift, and the way anybody would find
     * out is an association whose admins silently cannot reach a feature every
     * other association has.
     *
     * @return list<string>
     */
    public static function rolesHolding(string $permission): array
    {
        $roles = ['superadmin'];

        // Operational, minus role and user administration.
        if (! str_starts_with($permission, 'roles.') && ! str_starts_with($permission, 'users.')) {
            $roles[] = 'admin';
        }

        if (in_array($permission, self::OPERATOR, true)) {
            $roles[] = 'operator';
        }

        return $roles;
    }

    /**
     * Permissions in the catalogue this association does not have yet.
     *
     * Reads nothing else and writes nothing at all, so `tenants:seed --dry-run`
     * cannot change what it is describing.
     *
     * @return list<string>
     */
    public function missingPermissions(): array
    {
        $held = Permission::query()
            ->where('guard_name', self::GUARD)
            ->pluck('name')
            ->all();

        $missing = [];

        foreach (self::permissionCatalogue() as $permissions) {
            foreach ($permissions as $name) {
                if (! in_array($name, $held, true)) {
                    $missing[] = $name;
                }
            }
        }

        return $missing;
    }

    /**
     * Bring the permission catalogue up to date. ADDITIVE, AND ONLY ADDITIVE.
     *
     * A PERMISSION IS GRANTED TO A ROLE ONLY IN THE RUN THAT CREATES IT, which
     * is the whole of the policy and the reason this is safe against live data.
     * A permission that already exists in the association's database is one the
     * association has had the chance to think about: if their admins do not
     * hold `members.suspend`, somebody took it away on purpose, and a deploy
     * that quietly hands it back has overruled them.
     *
     * So "what a release added" is read as "what has no row here yet", and
     * nothing else is touched.
     *
     * THIS USED TO CALL syncPermissions, which is not additive at all - it
     * replaces a role's permissions with exactly the list given. Re-running it
     * put back every permission an association had removed from `admin`, and
     * stripped every one they had ADDED to `operator`, whose own comment
     * invites them to widen it. The docblock above this method claimed
     * additivity throughout, and the test named for it only checked that the
     * permission ROW survived - not the grant - so nothing ever failed.
     *
     * The cost of the change: a permission dropped from the catalogue in a
     * later release stays granted rather than being swept up. Harmless, because
     * no route consults it, and far cheaper than the alternative.
     *
     * @return array{created: list<string>, granted: array<string, list<string>>}
     */
    public function seedRolesAndPermissions(): array
    {
        $roles = [];

        foreach (self::ROLES as $name) {
            $roles[$name] = Role::findOrCreate($name, self::GUARD);
        }

        $created = [];
        $granted = array_fill_keys(self::ROLES, []);

        foreach ($this->missingPermissions() as $name) {
            $permission = Permission::findOrCreate($name, self::GUARD);
            $created[] = $name;

            foreach (self::rolesHolding($name) as $role) {
                $roles[$role]->givePermissionTo($permission);
                $granted[$role][] = $name;
            }
        }

        return ['created' => $created, 'granted' => $granted];
    }
}
