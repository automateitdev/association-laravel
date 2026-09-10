<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\Artisan;
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
 *
 * It does NOT make a run independent of the one before it - see
 * provisionFreshTenant, which is the other half of that problem.
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
     * Provision a tenant for a test, surviving whatever the last run left behind.
     *
     * WHY IT CLEANS FIRST. tearDown drops the database and the MySQL user, and
     * tearDown does not run when a process is killed - a cancelled suite, a
     * crashed machine, a MySQL that went away mid-run. What survives is a real
     * database and a real user, and the tenancy manager's CREATE USER has no
     * IF NOT EXISTS: the NEXT run's first provision then exits 1.
     *
     * The shape that produces is genuinely misleading. Only the FIRST test of
     * the class fails, because its own tearDown clears the leftover and every
     * sibling after it provisions cleanly. So a suite reports one failure in a
     * class of eight, it passes when you run it on its own, and it passes on
     * the retry - which reads exactly like flakiness in the code under test
     * rather than debris from a run that was killed hours earlier.
     *
     * WHY IT REPORTS THE OUTPUT. `->assertSuccessful()` says only "Expected
     * status code 0 but received 1", and attributes it to wherever the pending
     * command was finally destroyed rather than to this line. The reason
     * provisioning failed is in the command's output, and it was being thrown
     * away.
     */
    protected function provisionFreshTenant(string $base): string
    {
        $slug = $this->slugFor($base);

        $this->dropTenantArtefactsFor($base);

        $code = Artisan::call('tenant:provision', ['slug' => $slug]);

        $this->assertSame(0, $code, "tenant:provision [{$slug}] failed:\n".Artisan::output());

        return $slug;
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
