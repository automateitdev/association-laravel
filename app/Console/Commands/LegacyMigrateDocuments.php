<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Migration\LegacyDocumentMigrator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The file half of the migration (see LegacyDocumentMigrator).
 *
 *     php artisan legacy:migrate-documents cocsol \
 *         --source=local_cocsol --files=/path/to/old/storage/app/public
 *
 * SEPARATE FROM `legacy:migrate` BECAUSE THE INPUTS ARE SEPARATE. The database
 * can be restored anywhere; the scans exist on one server, and on a development
 * machine restored from a database dump they are simply not there. Folding this
 * into the main migration would make every rehearsal fail for want of files
 * nobody has, or - worse - pass while quietly writing nothing.
 */
class LegacyMigrateDocuments extends Command
{
    protected $signature = 'legacy:migrate-documents
        {tenant : The tenant slug, e.g. cocsol}
        {--source= : Legacy database name; defaults to LEGACY_DB_DATABASE}
        {--files= : The old storage/app/public directory holding the scans}
        {--dry-run : Report what would be copied, and write nothing}';

    protected $description = "Copy the legacy's member and nominee scans into a tenant as documents";

    public function handle(): int
    {
        $slug = (string) $this->argument('tenant');
        $tenant = Tenant::find($slug);

        if (! $tenant) {
            $this->error("No tenant [{$slug}].");

            return self::FAILURE;
        }

        if ($source = $this->option('source')) {
            config(['database.connections.legacy.database' => $source]);
            DB::purge('legacy');
        }

        $root = (string) $this->option('files');

        if ($root === '') {
            $this->error('Pass --files=<the old storage/app/public directory>.');

            return self::FAILURE;
        }

        if (! is_dir($root)) {
            $this->error("Not a directory: {$root}");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        $this->info(($dryRun ? '[dry run] ' : '')."Documents from [{$root}] -> tenant [{$slug}]");
        $this->newLine();

        $report = (new LegacyDocumentMigrator($root, 'legacy', $dryRun))
            ->run($tenant, fn (string $line) => $this->line($line));

        $this->newLine();
        $this->table(
            ['Slot', 'Copied', 'Missing'],
            collect(array_keys($report->copiedBySlot + $report->missingBySlot))
                ->sort()
                ->map(fn ($slot) => [
                    $slot,
                    $report->copiedBySlot[$slot] ?? 0,
                    $report->missingBySlot[$slot] ?? 0,
                ])
                ->all(),
        );

        $this->line("  referenced by the database: {$report->referenced}");
        $this->line("  copied:                     {$report->copied}");
        $this->line("  missing from the disk:      {$report->missing}");

        if ($report->unreadable > 0) {
            $this->line("  present but unreadable:     {$report->unreadable}");
        }

        $this->newLine();

        if ($report->complete()) {
            $this->info('Every file the database names is now a document.');

            return self::SUCCESS;
        }

        /*
         * A WARNING, NOT A FAILURE, and the distinction is deliberate. Missing
         * files are a fact about the source, not a fault in this run: pointed
         * at a machine that holds the database but not the uploads it will
         * report all of them missing, which is the correct and useful answer.
         * What it must never do is finish quietly as though it had worked.
         */
        $this->warn(
            $report->copied === 0
                ? 'NO FILES WERE COPIED. The database names them; this directory does not hold them. '
                    .'Point --files at the server that has the uploads.'
                : "{$report->missing} of {$report->referenced} files are named by the database "
                    .'but absent from disk. Those members have no document on file.'
        );

        return self::SUCCESS;
    }
}
