<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\ParallelTesting;

/**
 * Process-scoped tenant slugs.
 *
 * Laravel gives each parallel test process its own CENTRAL database
 * automatically, but it knows nothing about the tenant databases these tests
 * create - those are real databases with names derived from a slug, plus a real
 * MySQL user each.
 *
 * Two workers running the same test file would therefore fight over
 * `tenanttestco` and `t_testco`: one drops the database the other is midway
 * through using. The failures would be intermittent, and they would look like
 * tenancy bugs rather than test-harness bugs - which is the worst possible
 * disguise for them to wear in THIS codebase.
 *
 * Appending the process token makes every worker's tenants its own.
 */
trait ParallelSlugs
{
    protected function slugFor(string $base): string
    {
        $token = ParallelTesting::token();

        // Slugs must match /^[a-z][a-z0-9-]{1,49}$/, and the derived MySQL
        // username (t_ + slug with hyphens as underscores) must stay under
        // MySQL's 32-character limit.
        return $token ? "{$base}-p{$token}" : $base;
    }

    protected function databaseUserFor(string $base): string
    {
        return 't_'.str_replace('-', '_', $this->slugFor($base));
    }

    protected function databaseNameFor(string $base): string
    {
        return config('tenancy.database.prefix').$this->slugFor($base);
    }

    /**
     * Remove a tenant's database and MySQL user, regardless of whether the
     * registry row survived.
     */
    protected function dropTenantArtefactsFor(string $base): void
    {
        \DB::connection('mysql')->statement(
            'DROP DATABASE IF EXISTS `'.$this->databaseNameFor($base).'`'
        );
        \DB::connection('mysql')->statement(
            'DROP USER IF EXISTS `'.$this->databaseUserFor($base).'`@`%`'
        );
    }
}
