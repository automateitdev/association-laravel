<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant;
use App\Models\TenantProvisioningRun;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Throwable;

/**
 * Provision one association (FR-TEN-6, FR-PLT-1).
 *
 * A SERVICE BECAUSE THERE ARE NOW TWO DOORS. This was the body of
 * `tenant:provision`, and stayed there while the command was the only caller.
 * The console needs the same thing, and provisioning is the single operation
 * here where a second implementation would be genuinely dangerous: it creates a
 * database and a scoped MySQL user, and a partial failure leaves an orphan that
 * blocks the next attempt on the same slug. One rollback path, used by both.
 *
 * ALL-OR-NOTHING. Every failure rolls the whole thing back and records why, in
 * `tenant_provisioning_runs`. Verified by T-13: provisioning fails midway, no
 * orphan database and no orphan registry row remain.
 */
class TenantProvisioner
{
    /**
     * @param  array<string, string|null>  $options
     *   slug, name, legal_name, domain, admin_name, admin_email,
     *   locale, timezone, currency
     */
    public function provision(array $options): ProvisionResult
    {
        $slug = (string) ($options['slug'] ?? '');

        if (! $this->isValidSlug($slug)) {
            return ProvisionResult::refused(
                'Invalid slug. Lowercase letters, digits and hyphens; 2-50 characters; must start with a letter.'
            );
        }

        if (Tenant::find($slug)) {
            return ProvisionResult::refused("An association with the id [{$slug}] already exists.");
        }

        $run = TenantProvisioningRun::begin($slug, 'tenant:provision');
        $tenant = null;
        $steps = [];

        try {
            /*
             * Creating the Tenant fires TenantCreated, which runs CreateDatabase
             * then MigrateDatabase synchronously (see TenancyServiceProvider).
             * The tenancy_db_* keys drive PermissionControlledMySQLDatabaseManager,
             * which creates a MySQL user granted rights on this database only.
             */
            $tenant = Tenant::create([
                'id' => $slug,
                'name' => $options['name'] ?: Str::headline($slug),
                'legal_name' => $options['legal_name'] ?? null,
                'status' => Tenant::STATUS_PROVISIONING,
                'locale' => $options['locale'] ?: 'en',
                'timezone' => $options['timezone'] ?: 'Asia/Dhaka',
                'currency' => $options['currency'] ?: 'BDT',
                'tenancy_db_username' => $this->databaseUsername($slug),
                'tenancy_db_password' => Str::password(32, symbols: false),
            ]);

            // Verify rather than assert. The pipeline above can no-op silently,
            // and reporting work that did not happen is worse than failing: it
            // sends the operator looking in the wrong place.
            $this->assertDatabaseWasCreated($tenant);

            $steps[] = "database + scoped user: {$tenant->database()->getName()}";
            $steps[] = 'tenant migrations: run';

            $domain = $options['domain'] ?: $slug.'.'.$this->centralDomain();
            $tenant->domains()->create(['domain' => $domain]);
            $steps[] = "domain: {$domain}";

            $setupToken = $tenant->run(function () use ($options) {
                app(TenantSeedService::class)->seedAll();

                return $this->createFirstSuperadmin($options);
            });

            $steps[] = 'settings, chart of accounts and roles: seeded';

            $tenant->update([
                'status' => Tenant::STATUS_ACTIVE,
                'onboarded_at' => now(),
            ]);

            $run->succeed("Provisioned {$slug} at {$domain}");

            return ProvisionResult::succeeded($tenant->fresh(), $run, $domain, $setupToken, $steps);
        } catch (Throwable $e) {
            /*
             * Tenant::create() fires TenantCreated synchronously, so the whole
             * CreateDatabase -> MigrateDatabase pipeline runs INSIDE create().
             * A failure there leaves $tenant unassigned even though the registry
             * row was already inserted - re-fetch, or the rollback quietly does
             * nothing and leaves an orphan database behind.
             */
            $tenant ??= Tenant::find($slug);

            $rollbackNotes = $this->rollback($tenant, $slug);

            $run->fail(
                $e->getMessage()."\n\nRollback: ".$rollbackNotes,
                TenantProvisioningRun::STATUS_ROLLED_BACK
            );

            return ProvisionResult::failed($run, $e->getMessage(), $rollbackNotes, $steps);
        }
    }

    /**
     * Create the association's first superadmin.
     *
     * The account is created with an unusable random password and a one-time
     * setup token. Nobody - not us, not the operator provisioning it - ever
     * knows a password the association's own administrator did not choose.
     *
     * @param  array<string, string|null>  $options
     * @return string|null the setup token, shown once
     */
    private function createFirstSuperadmin(array $options): ?string
    {
        $email = $options['admin_email'] ?? null;

        if (! $email) {
            return null;
        }

        $user = User::create([
            'name' => $options['admin_name'] ?: 'Administrator',
            'email' => $email,

            // Random and discarded. The token below is the only way in.
            'password' => Str::password(40),
        ]);

        $user->assignRole('superadmin');

        $token = Str::random(64);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $email],
            ['token' => Hash::make($token), 'created_at' => now()]
        );

        return $token;
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

        $exists = DB::connection('mysql')->select(
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
     * or the caller loses the original error.
     */
    public function rollback(?Tenant $tenant, string $slug): string
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
            // Fires TenantDeleted -> DeleteDatabase, which drops the database
            // and its scoped user. This throws when the pipeline failed before
            // the database existed, which is expected and not fatal here.
            $tenant->delete();
        } catch (Throwable $e) {
            $problems[] = 'tenant row: '.$e->getMessage();

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

        DB::connection('mysql')->statement("DROP DATABASE IF EXISTS `{$database}`");
        DB::connection('mysql')->statement("DROP USER IF EXISTS `{$username}`@`%`");
        DB::connection('mysql')->statement("DROP USER IF EXISTS `{$username}`@`localhost`");
    }

    public function isValidSlug(string $slug): bool
    {
        return (bool) preg_match('/^[a-z][a-z0-9-]{1,49}$/', $slug);
    }

    private function databaseUsername(string $slug): string
    {
        // MySQL usernames are capped at 32 characters.
        return substr('t_'.str_replace('-', '_', $slug), 0, 32);
    }

    private function centralDomain(): string
    {
        return (string) (config('tenancy.central_domains')[0] ?? 'localhost');
    }
}
