<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\TenantProvisioningRun;
use App\Services\TenantSeedService;
use Illuminate\Console\Command;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * Bring existing associations up to date with the current release.
 *
 * THE GAP THIS CLOSES
 * -------------------
 * `TenantSeedService` runs at PROVISIONING and nowhere else. So a release that
 * adds a permission adds it to the catalogue, ships, and reaches nobody: every
 * association created before that day is missing the row, and the route behind
 * it answers 403 - to `superadmin` included, which is how it gets reported as
 * "the feature is broken" rather than as a deploy step nobody ran.
 *
 * Found on 2026-09-11, by adding `reports.voucherwise` and watching a demo
 * tenant's superadmin be refused. It applied to every report permission added
 * since each association was created. `tenants:migrate` has existed all along
 * for schema; this is its counterpart for the catalogue.
 *
 * NOT `tenants:seed`, WHICH IS TAKEN. stancl/tenancy ships a command by that
 * name - it runs Laravel's `DatabaseSeeder` inside each tenant database, and
 * this project's `config/tenancy.php` still points it at that class. Declaring
 * a second `tenants:seed` here did register and did win, because Artisan keeps
 * whichever was registered last; which one that is depends on registration
 * order, and a package upgrade could silently swap them. A command that runs
 * something different from what the dependency's documentation says is worse
 * than a slightly longer name.
 *
 * WHY IT IS SAFE TO RUN AGAINST LIVE DATA
 * ---------------------------------------
 * Everything the service writes is `findOrCreate`, and a permission is granted
 * to a role only in the run that CREATES it. An association's own configuration
 * is therefore never overruled: if their admins do not hold `members.suspend`,
 * somebody took it away on purpose, and this leaves it that way. See the note
 * on `seedRolesAndPermissions` for what that replaced, which was not safe.
 *
 * WHY --dry-run EXISTS
 * --------------------
 * This runs against every association on a deploy day. "Tell me what you are
 * about to do, to whom" is the cheapest thing a command like that can offer,
 * and the reason it can be trusted is that the dry run reads through a method
 * that writes nothing rather than through a rolled-back transaction.
 */
class ReseedTenants extends Command
{
    protected $signature = 'tenants:reseed
        {--tenant= : Restrict to one association slug}
        {--permissions-only : Only the permission catalogue - leave settings and the chart of accounts alone}
        {--dry-run : Report what would change and write nothing}';

    protected $description = 'Bring existing associations up to date with the seeded catalogue';

    public function handle(TenantSeedService $seeder): int
    {
        $dry = (bool) $this->option('dry-run');
        $permissionsOnly = (bool) $this->option('permissions-only');

        /*
         * ACTIVE AND SUSPENDED, not every row.
         *
         * A `provisioning` or `failed` tenant may have no database yet, so
         * reaching into it throws and the deploy exits non-zero over a tenant
         * that was never ready - which trains people to ignore the exit code.
         * An `archived` one is deliberately left as it was.
         *
         * SUSPENDED IS INCLUDED, and that is the deliberate half: a suspension
         * is about billing, not about the schema. An association that comes
         * back a month later must not return to a database two releases behind
         * the code serving it.
         */
        $tenants = Tenant::query()
            ->whereIn('status', [Tenant::STATUS_ACTIVE, Tenant::STATUS_SUSPENDED])
            ->when($this->option('tenant'), fn ($q, $slug) => $q->where('id', $slug))
            ->orderBy('id')
            ->get();

        if ($tenants->isEmpty()) {
            /*
             * A warning and SUCCESS, not an error. `--tenant=` naming nothing
             * is a typo worth seeing; no associations at all is a platform
             * nobody has onboarded yet, and failing the deploy over it would be
             * absurd.
             */
            $this->warn($this->option('tenant')
                ? "No active or suspended association with the slug [{$this->option('tenant')}]."
                : 'No associations to seed.');

            return self::SUCCESS;
        }

        if ($dry) {
            $this->comment('Dry run: nothing will be written.');
            $this->newLine();
        }

        $changed = 0;
        $failed = 0;

        foreach ($tenants as $tenant) {
            /*
             * Spatie caches the permission table, and this process visits many
             * databases in a row. The cache is tenant-prefixed by the cache
             * bootstrapper, but the registrar ALSO holds an in-memory
             * collection that is not - so without this, tenant two is asked
             * about tenant one's permissions and reports nothing missing.
             */
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            try {
                $changed += $this->seed($tenant, $seeder, $dry, $permissionsOnly) ? 1 : 0;
            } catch (Throwable $e) {
                $failed++;

                // Contained, like the accrual command: one association's
                // failure must not stop the rest of a deploy.
                $this->error("{$tenant->getKey()}: {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->line(sprintf(
            '%d association(s) examined, %d %s.',
            $tenants->count(),
            $changed,
            $dry ? 'would change' : 'changed',
        ));

        if ($failed > 0) {
            $this->error("{$failed} failed.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** @return bool whether anything changed, or would have */
    private function seed(
        Tenant $tenant,
        TenantSeedService $seeder,
        bool $dry,
        bool $permissionsOnly,
    ): bool {
        $slug = $tenant->getKey();

        if ($dry) {
            return $tenant->run(fn () => $this->report($slug, $seeder->pending(), $permissionsOnly));
        }

        $run = TenantProvisioningRun::begin($slug, 'tenants:reseed');

        try {
            $summary = $tenant->run(function () use ($seeder, $permissionsOnly) {
                /*
                 * Read FIRST, write second. Every write in the seeder is
                 * `findOrCreate`, so what was pending beforehand is exactly
                 * what this run went on to add - which is the only way the
                 * settings and chart passes can be reported at all. They
                 * return nothing themselves, and a run that quietly created a
                 * ledger while printing "already up to date" would be worse
                 * than one that printed nothing.
                 */
                $pending = $seeder->pending();

                if (! $permissionsOnly) {
                    $seeder->seedSettings();
                    $seeder->seedChartOfAccounts();
                }

                return $seeder->seedRolesAndPermissions() + [
                    'settings' => $permissionsOnly ? [] : $pending['settings'],
                    'ledgers' => $permissionsOnly ? [] : $pending['ledgers'],
                ];
            });
        } catch (Throwable $e) {
            $run->fail($e->getMessage());

            throw $e;
        }

        $added = [
            'permission' => $summary['created'],
            'setting' => $summary['settings'],
            'ledger' => $summary['ledgers'],
        ];

        if ($added === array_fill_keys(array_keys($added), [])) {
            $this->line("  <fg=gray>{$slug}: already up to date</>");
            $run->succeed('nothing to add');

            return false;
        }

        // Only the kinds that changed. "added 1 permission, 0 settings,
        // 1 ledger" makes a reader check a figure that was never in question.
        $counts = array_filter($added, fn (array $items) => $items !== []);

        $line = $slug.': added '.implode(', ', array_map(
            fn (string $kind, array $items) => count($items).' '.$kind.(count($items) === 1 ? '' : 's'),
            array_keys($counts),
            array_values($counts),
        ));

        $this->info("  {$line}");

        foreach ($added as $kind => $items) {
            foreach ($items as $item) {
                // Named, not counted. Somebody reading a deploy log needs to be
                // able to tell a release's new report permission from a typo in
                // the catalogue, and a count cannot do that.
                $this->line("      + {$kind} {$item}");
            }
        }

        foreach ($summary['granted'] as $role => $permissions) {
            if ($permissions !== []) {
                $this->line("      {$role}: ".implode(', ', $permissions));
            }
        }

        $run->succeed($line);

        return true;
    }

    /**
     * @param  array{permissions: list<string>, settings: list<string>, ledgers: list<string>}  $pending
     * @return bool whether anything would change
     */
    private function report(string $slug, array $pending, bool $permissionsOnly): bool
    {
        $kinds = $permissionsOnly
            ? ['permissions' => $pending['permissions']]
            : $pending;

        $kinds = array_filter($kinds, fn (array $items) => $items !== []);

        if ($kinds === []) {
            $this->line("  <fg=gray>{$slug}: already up to date</>");

            return false;
        }

        $this->info("  {$slug}:");

        foreach ($kinds as $kind => $items) {
            $this->line("      {$kind}: ".implode(', ', $items));

            if ($kind === 'permissions') {
                foreach ($items as $permission) {
                    $roles = implode(', ', TenantSeedService::rolesHolding($permission));
                    $this->line("        {$permission} -> {$roles}");
                }
            }
        }

        return true;
    }
}
