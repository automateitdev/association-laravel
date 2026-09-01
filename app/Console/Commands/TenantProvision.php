<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\TenantProvisioningRun;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * Provision one association (FR-TEN-6).
 *
 * All-or-nothing. A half-created tenant - database present, migrations half-run,
 * registry row written - is worse than no tenant at all, because the next attempt
 * with the same slug collides with it. Every failure path rolls the whole thing
 * back and records why.
 *
 * Verified by T-13: provisioning fails midway, no orphan database and no orphan
 * registry row remain.
 */
class TenantProvision extends Command
{
    protected $signature = 'tenant:provision
        {slug : The immutable association identifier, e.g. cocsol}
        {--name= : Display name (defaults to the slug)}
        {--legal-name= : Registered legal name}
        {--domain= : Hostname, defaults to <slug>.<central domain>}
        {--locale=en}
        {--timezone=Asia/Dhaka}
        {--currency=BDT}';

    protected $description = 'Create an association: database, scoped DB user, migrations, domain';

    public function handle(): int
    {
        $slug = (string) $this->argument('slug');

        if (! $this->isValidSlug($slug)) {
            $this->error("Invalid slug [{$slug}].");
            $this->line('  Lowercase letters, digits and hyphens; 2-50 characters; must start with a letter.');

            return self::FAILURE;
        }

        if (Tenant::find($slug)) {
            $this->error("Tenant [{$slug}] already exists.");

            return self::FAILURE;
        }

        $run = TenantProvisioningRun::begin($slug, 'tenant:provision');
        $tenant = null;

        try {
            $this->info("Provisioning [{$slug}]...");

            // Creating the Tenant fires TenantCreated, which runs CreateDatabase
            // then MigrateDatabase synchronously (see TenancyServiceProvider).
            // The tenancy_db_* keys drive PermissionControlledMySQLDatabaseManager,
            // which creates a MySQL user granted rights on this database only.
            $tenant = Tenant::create([
                'id' => $slug,
                'name' => $this->option('name') ?: Str::headline($slug),
                'legal_name' => $this->option('legal-name'),
                'status' => Tenant::STATUS_PROVISIONING,
                'locale' => $this->option('locale'),
                'timezone' => $this->option('timezone'),
                'currency' => $this->option('currency'),
                'tenancy_db_username' => $this->databaseUsername($slug),
                'tenancy_db_password' => Str::password(32, symbols: false),
            ]);

            // Verify rather than assert. The pipeline above can no-op silently,
            // and a command that reports work it did not do is worse than one
            // that fails: it sends the operator looking in the wrong place.
            $this->assertDatabaseWasCreated($tenant);

            $this->line("  database + scoped user .. {$tenant->database()->getName()}");
            $this->line('  tenant migrations ....... run');

            $domain = $this->option('domain') ?: $slug.'.'.$this->centralDomain();
            $tenant->domains()->create(['domain' => $domain]);
            $this->line("  domain .................. {$domain}");

            // TODO(P1): seed the default chart of accounts, roles and permissions,
            // and create the first superadmin with an invitation token. Blocked on
            // the tenant domain migrations landing.

            $tenant->update([
                'status' => Tenant::STATUS_ACTIVE,
                'onboarded_at' => now(),
            ]);

            $run->succeed("Provisioned {$slug} at {$domain}");

            $this->newLine();
            $this->info("Tenant [{$slug}] is active.");
            $this->line("  database: {$tenant->database()->getName()}");
            $this->line("  db user:  {$tenant->database()->getUsername()}");
            $this->line("  domain:   {$domain}");

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->newLine();
            $this->error("Provisioning failed: {$e->getMessage()}");

            // Tenant::create() fires TenantCreated synchronously, so the whole
            // CreateDatabase -> MigrateDatabase pipeline runs INSIDE create().
            // A failure there leaves $tenant unassigned even though the registry
            // row was already inserted - re-fetch, or the rollback quietly does
            // nothing and leaves an orphan database behind.
            $tenant ??= Tenant::find($slug);

            $rollbackNotes = $this->rollback($tenant, $slug);
            $run->fail(
                $e->getMessage()."\n\nRollback: ".$rollbackNotes,
                TenantProvisioningRun::STATUS_ROLLED_BACK
            );

            $this->warn("Rolled back. {$rollbackNotes}");

            return self::FAILURE;
        }
    }

    /**
     * Confirm the tenant database actually exists and carries its migrations.
     *
     * @throws \RuntimeException
     */
    private function assertDatabaseWasCreated(Tenant $tenant): void
    {
        $name = $tenant->database()->getName();

        if (! $name) {
            throw new \RuntimeException(
                'Tenant database name resolved to null - check the tenant key type.'
            );
        }

        $exists = \DB::connection('mysql')->select(
            'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?',
            [$name]
        );

        if (! $exists) {
            throw new \RuntimeException("Tenant database [{$name}] was not created.");
        }

        $migrated = $tenant->run(fn () => \Schema::hasTable('migrations'));

        if (! $migrated) {
            throw new \RuntimeException("Tenant database [{$name}] has no migrations table.");
        }
    }

    /**
     * Undo everything, in the reverse order it was created.
     *
     * Deleting the Tenant fires TenantDeleted, which drops the database and its
     * user. Rollback must never throw - a failure here is reported, not raised,
     * or the operator loses the original error.
     */
    private function rollback(?Tenant $tenant, string $slug): string
    {
        if (! $tenant) {
            return 'nothing to undo - failed before the tenant row was written.';
        }

        $problems = [];

        // Each step is independent. An early failure must not skip the later
        // cleanup - that is how an orphan database survives and blocks the next
        // attempt with the same slug.

        try {
            $tenant->domains()->delete();
        } catch (Throwable $e) {
            $problems[] = 'domains: '.$e->getMessage();
        }

        try {
            // Fires TenantDeleted -> DeleteDatabase, which drops the database and
            // its scoped user. This throws when the pipeline failed before the
            // database existed, which is expected and not fatal to the rollback.
            $tenant->delete();
        } catch (Throwable $e) {
            $problems[] = 'tenant row: '.$e->getMessage();

            // Make sure the registry row is gone even if the delete pipeline
            // blew up partway through it.
            try {
                Tenant::withoutEvents(fn () => Tenant::where('id', $slug)->delete());
            } catch (Throwable $inner) {
                $problems[] = 'registry row: '.$inner->getMessage();
            }
        }

        try {
            $this->forceDropDatabaseArtefacts($slug);
        } catch (Throwable $e) {
            $problems[] = 'database/user: '.$e->getMessage();
        }

        $orphaned = Tenant::find($slug) !== null;

        if ($orphaned || $problems !== []) {
            return 'PARTIAL - verify by hand for ['.$slug.']: '.implode('; ', $problems);
        }

        return 'database, user, domain and registry row removed.';
    }

    /**
     * Drop the database and MySQL user this slug would own, if they survived.
     *
     * Identifiers are derived from the slug, which has already passed
     * isValidSlug() - lowercase letters, digits and hyphens only - so there is
     * nothing here an attacker could steer.
     */
    private function forceDropDatabaseArtefacts(string $slug): void
    {
        $database = config('tenancy.database.prefix').$slug.config('tenancy.database.suffix');
        $username = $this->databaseUsername($slug);

        \DB::connection('mysql')->statement("DROP DATABASE IF EXISTS `{$database}`");
        \DB::connection('mysql')->statement("DROP USER IF EXISTS `{$username}`@`%`");
    }

    private function isValidSlug(string $slug): bool
    {
        return (bool) preg_match('/^[a-z][a-z0-9-]{1,49}$/', $slug);
    }

    /**
     * MySQL usernames are capped at 32 characters.
     */
    private function databaseUsername(string $slug): string
    {
        return Str::substr('t_'.str_replace('-', '_', $slug), 0, 32);
    }

    private function centralDomain(): string
    {
        return config('tenancy.central_domains')[0] ?? 'localhost';
    }
}
