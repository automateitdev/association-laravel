<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Models\Tenant;
use App\Models\TenantProvisioningRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Provisioning: FR-TEN-6, and test T-13 from bcs-docs/08-testing-strategy.md.
 *
 * These tests create and drop REAL MySQL databases. They cannot run on SQLite -
 * a tenant database is a database, not a table prefix.
 */
class TenantProvisioningTest extends TestCase
{
    use RefreshDatabase;

    /** Every slug this file provisions. Teardown drops these and nothing else. */
    private const TEST_SLUGS = [
        'acme-society',
        'migrated-co',
        'doomed-co',
        'only-once',
        'squatter',
        'pipeline-fail',
    ];

    protected function tearDown(): void
    {
        // Tenant databases outlive RefreshDatabase: it rolls back the central
        // connection, not the databases the tests created on the side.
        foreach (Tenant::all() as $tenant) {
            try {
                $tenant->delete();
            } catch (\Throwable) {
                // Best effort - a test that deliberately breaks provisioning may
                // leave a tenant in a state that cannot be deleted cleanly.
            }
        }

        $this->dropStrayTestDatabases();

        parent::tearDown();
    }

    public function test_it_provisions_a_tenant_with_database_user_and_domain(): void
    {
        $this->artisan('tenant:provision', [
            'slug' => 'acme-society',
            '--name' => 'Acme Cooperative Society',
        ])->assertSuccessful();

        $tenant = Tenant::find('acme-society');

        $this->assertNotNull($tenant, 'The tenant registry row should exist.');
        $this->assertSame(Tenant::STATUS_ACTIVE, $tenant->status);
        $this->assertNotNull($tenant->onboarded_at);

        // The slug is the key (FR-TEN-5), not an auto-incrementing integer.
        $this->assertSame('acme-society', $tenant->getKey());
        $this->assertFalse($tenant->getIncrementing());
        $this->assertSame('string', $tenant->getKeyType());

        $this->assertTrue($this->databaseExists('tenantacme-society'));
        $this->assertTrue($this->mysqlUserExists('t_acme_society'));

        $this->assertSame(
            'acme-society',
            $tenant->domains()->first()->tenant_id,
            'The domain must point at the slug, not at 0.'
        );
    }

    public function test_the_tenant_database_carries_its_migrations(): void
    {
        $this->artisan('tenant:provision', ['slug' => 'migrated-co'])->assertSuccessful();

        $hasUsers = Tenant::find('migrated-co')->run(
            fn () => \Schema::hasTable('users')
        );

        $this->assertTrue($hasUsers, 'Tenant migrations should have run.');
    }

    /**
     * T-13: provisioning fails midway, and nothing is left behind.
     *
     * A half-created tenant is worse than none - the next attempt with the same
     * slug collides with it.
     */
    public function test_a_failed_provision_leaves_no_orphan_database_or_registry_row(): void
    {
        // Force the failure after the database is created, by taking the domain
        // the command is about to claim.
        $squatter = Tenant::create(['id' => 'squatter', 'name' => 'Squatter']);
        $squatter->domains()->create(['domain' => 'doomed-co.'.$this->centralDomain()]);

        $this->artisan('tenant:provision', ['slug' => 'doomed-co'])->assertFailed();

        $this->assertNull(
            Tenant::find('doomed-co'),
            'The registry row must be rolled back.'
        );
        $this->assertFalse(
            $this->databaseExists('tenantdoomed-co'),
            'The tenant database must be dropped.'
        );

        $run = TenantProvisioningRun::where('tenant_id', 'doomed-co')->latest('id')->first();
        $this->assertNotNull($run, 'The failed run must still be recorded.');
        $this->assertSame(TenantProvisioningRun::STATUS_ROLLED_BACK, $run->status);
    }

    /**
     * T-13, the harder half: the failure happens INSIDE Tenant::create().
     *
     * TenantCreated fires synchronously, so CreateDatabase and MigrateDatabase
     * run before create() returns. A throw there leaves the command's $tenant
     * variable unassigned even though the registry row is already inserted - and
     * a rollback that trusts that variable silently does nothing.
     *
     * This is not hypothetical: it happened, and it left an orphan database plus
     * a live MySQL user behind. The first version of this test missed it because
     * it forced the failure after create() had returned.
     */
    public function test_a_failure_inside_the_create_pipeline_still_rolls_back(): void
    {
        // Squat the database the pipeline is about to create, so CreateDatabase
        // throws from inside Tenant::create().
        DB::connection('mysql')->statement('CREATE DATABASE `tenantpipeline-fail`');

        $this->artisan('tenant:provision', ['slug' => 'pipeline-fail'])->assertFailed();

        $this->assertNull(
            Tenant::find('pipeline-fail'),
            'The registry row must not survive a failure inside create().'
        );
        $this->assertFalse(
            $this->mysqlUserExists('t_pipeline_fail'),
            'The scoped MySQL user must not survive either.'
        );

        $run = TenantProvisioningRun::where('tenant_id', 'pipeline-fail')->latest('id')->first();
        $this->assertSame(TenantProvisioningRun::STATUS_ROLLED_BACK, $run?->status);
    }

    public function test_it_refuses_a_duplicate_slug(): void
    {
        $this->artisan('tenant:provision', ['slug' => 'only-once'])->assertSuccessful();
        $this->artisan('tenant:provision', ['slug' => 'only-once'])->assertFailed();

        $this->assertSame(1, Tenant::where('id', 'only-once')->count());
    }

    #[DataProvider('invalidSlugs')]
    public function test_it_refuses_an_invalid_slug(string $slug): void
    {
        $this->artisan('tenant:provision', ['slug' => $slug])->assertFailed();
        $this->assertSame(0, Tenant::count());
    }

    public static function invalidSlugs(): array
    {
        return [
            'uppercase' => ['Cocsol'],
            'leading digit' => ['1society'],
            'underscore' => ['co_op'],
            'too short' => ['c'],
            'spaces' => ['co op'],
            'sql-ish' => ["co'; DROP DATABASE bcs_central; --"],
        ];
    }

    private function databaseExists(string $name): bool
    {
        return (bool) DB::connection('mysql')->select(
            'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?',
            [$name]
        );
    }

    private function mysqlUserExists(string $user): bool
    {
        return (bool) DB::connection('mysql')->select(
            'SELECT user FROM mysql.user WHERE user = ?',
            [$user]
        );
    }

    private function centralDomain(): string
    {
        return config('tenancy.central_domains')[0] ?? 'localhost';
    }

    /**
     * Drop only the databases THIS test file creates.
     *
     * An earlier version dropped every `tenant%` database, which also destroyed
     * the developer's local demo tenants. A test suite may not reach outside its
     * own fixtures - the slugs below are the complete list this file provisions.
     */
    private function dropStrayTestDatabases(): void
    {
        foreach (self::TEST_SLUGS as $slug) {
            DB::connection('mysql')->statement(
                'DROP DATABASE IF EXISTS `'.config('tenancy.database.prefix').$slug.'`'
            );
            DB::connection('mysql')->statement(
                'DROP USER IF EXISTS `t_'.str_replace('-', '_', $slug).'`@`%`'
            );
        }
    }
}
