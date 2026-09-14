<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Migration\LegacyMigrator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Load the legacy association into a tenant (FR-MIG-1, FR-MIG-2).
 *
 *     php artisan legacy:migrate cocsol --source=local_cocsol
 *
 * REFUSES TO RUN OVER AN ASSOCIATION THAT IS BEING USED. The tenant it writes
 * to is named on the command line, and the whole of that tenant's data is
 * replaced - so the one mistake that matters is typing the wrong slug. A tenant
 * holding members this migration did not put there is not a migration target,
 * and `--force` is the only way past that, deliberately typed.
 */
class LegacyMigrate extends Command
{
    protected $signature = 'legacy:migrate
        {tenant : The tenant slug to load into, e.g. cocsol}
        {--source= : Legacy database name; defaults to LEGACY_DB_DATABASE}
        {--force : Replace the data of a tenant that already holds members}';

    protected $description = 'Migrate the legacy association database into a tenant';

    public function handle(): int
    {
        $slug = (string) $this->argument('tenant');
        $tenant = Tenant::find($slug);

        if (! $tenant) {
            $this->error("No tenant [{$slug}]. Provision it first: php artisan tenant:provision {$slug}");

            return self::FAILURE;
        }

        if ($source = $this->option('source')) {
            config(['database.connections.legacy.database' => $source]);
            DB::purge('legacy');
        }

        $legacyName = config('database.connections.legacy.database');

        if (! $legacyName) {
            $this->error('No legacy database named. Pass --source= or set LEGACY_DB_DATABASE.');

            return self::FAILURE;
        }

        try {
            $legacyMembers = DB::connection('legacy')->table('members')->count();
        } catch (\Throwable $e) {
            $this->error("Cannot read legacy database [{$legacyName}]: {$e->getMessage()}");

            return self::FAILURE;
        }

        $existing = $tenant->run(fn () => DB::table('members')->count());

        if ($existing > 0 && ! $this->option('force')) {
            $this->error("Tenant [{$slug}] already holds {$existing} members.");
            $this->line('This command REPLACES every table it touches. If that is what you want,');
            $this->line('re-run it with --force.');

            return self::FAILURE;
        }

        $this->info("Legacy [{$legacyName}] ({$legacyMembers} members)  ->  tenant [{$slug}]");

        if ($existing > 0) {
            $this->warn("Replacing the existing data of [{$slug}].");
        }

        $this->newLine();
        $this->line('Loading:');

        $started = microtime(true);

        try {
            $report = (new LegacyMigrator)->run($tenant, fn (string $line) => $this->line($line));
        } catch (\Throwable $e) {
            $this->newLine();
            $this->error('Migration failed, and nothing was committed: '.$e->getMessage());

            return self::FAILURE;
        }

        $elapsed = round(microtime(true) - $started, 1);

        $this->newLine();
        $this->line("Reconciliation  ({$elapsed}s)");

        $rows = [];

        foreach ($report->before as $measure => $before) {
            $after = $report->after[$measure] ?? '(missing)';
            $same = is_numeric($before) && is_numeric($after)
                ? abs((float) $before - (float) $after) < 0.005
                : $before === $after;

            $rows[] = [$measure, $before, $after, $same ? 'same' : 'MOVED'];
        }

        $this->table(['Measure', 'Legacy', 'Migrated', ''], $rows);

        foreach ($report->notes as $note) {
            $this->line('  - '.$note);
        }

        $moved = $report->discrepancies();

        $this->newLine();

        if ($moved === []) {
            $this->info('Every measured figure came across unchanged.');

            return self::SUCCESS;
        }

        /*
         * A moved number is not automatically a failure - M-5 moves the share
         * total on purpose, and the plan says so. But it is never something to
         * report quietly, so it is listed again here, alone, where it cannot be
         * read past.
         */
        $this->warn('These figures changed and each one needs a named reason (FR-MIG-3):');

        foreach ($moved as $row) {
            $this->line("  {$row['measure']}: {$row['before']} -> {$row['after']}");
        }

        return self::SUCCESS;
    }
}
