<?php

declare(strict_types=1);

namespace Tests;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ParallelSlugs;

/**
 * Base class for tests that need a working tenant.
 *
 * WHY THIS EXISTS
 * ---------------
 * Provisioning a tenant per test means creating a database and running ~11
 * migrations every time. The first tenancy suite took 207 seconds for 24 tests
 * on that basis, and the service tests would have been far worse. A slow suite
 * is a suite people stop running, and this project's whole defence against the
 * legacy money defects is tests that actually run.
 *
 * So: the tenant database is created and migrated ONCE per test run, and each
 * test gets a clean slate by truncation instead. Truncating thirty tables is
 * milliseconds; migrating them is seconds.
 *
 * The registry row is a separate matter. RefreshDatabase wraps each test in a
 * transaction on the CENTRAL connection, so the `tenants` row is rolled back
 * after every test - while the tenant database itself survives, because DDL
 * auto-commits in MySQL. Each test therefore re-creates the row with events
 * suppressed, which registers the tenant without trying to build its database
 * a second time.
 */
abstract class TenantTestCase extends TestCase
{
    use ParallelSlugs;
    use RefreshDatabase;

    /** Base name. The live slug is this plus the parallel process token. */
    protected const SLUG = 'testco';

    /** Fixed, because it must be reconstructable on every test. Test-only. */
    protected const DB_PASSWORD = 'testco-local-only';

    /** The live slug for THIS process. */
    protected function slug(): string
    {
        return $this->slugFor(static::SLUG);
    }

    /** Per process: each parallel worker builds its own tenant database once. */
    private static bool $databaseBuilt = false;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = self::$databaseBuilt
            ? $this->registerTenantWithoutBuildingIt()
            : $this->buildTenantDatabase();

        $this->truncateTenantTables();
    }

    /**
     * First test of the run: let the TenantCreated pipeline create and migrate
     * the database for real.
     */
    private function buildTenantDatabase(): Tenant
    {
        // A previous run may have left the database behind - the registry row is
        // transactional, the database is not.
        $this->dropTenantArtefacts();

        $tenant = Tenant::create($this->tenantAttributes());

        self::$databaseBuilt = true;

        return $tenant;
    }

    /**
     * Every subsequent test: the database already exists, so registering the
     * tenant must not fire CreateDatabase again.
     */
    private function registerTenantWithoutBuildingIt(): Tenant
    {
        return Tenant::withoutEvents(fn () => Tenant::create($this->tenantAttributes()));
    }

    private function tenantAttributes(): array
    {
        return [
            'id' => $this->slug(),
            'name' => 'Test Association',
            'status' => Tenant::STATUS_ACTIVE,
            'tenancy_db_username' => $this->databaseUserFor(static::SLUG),
            'tenancy_db_password' => self::DB_PASSWORD,
        ];
    }

    /**
     * Empty every tenant table, leaving the schema in place.
     */
    protected function truncateTenantTables(): void
    {
        $this->tenant->run(function () {
            $tables = array_map(
                fn ($row) => reset($row),
                array_map('get_object_vars', DB::select('SHOW TABLES'))
            );

            DB::statement('SET FOREIGN_KEY_CHECKS = 0');

            foreach ($tables as $table) {
                // `migrations` is schema state, not test data. Truncating it
                // would make the next run think the database is unmigrated.
                if ($table !== 'migrations') {
                    DB::table($table)->truncate();
                }
            }

            DB::statement('SET FOREIGN_KEY_CHECKS = 1');
        });

        $this->clearTenantUploads();
    }

    /**
     * Truncating tables does not remove the files those rows pointed at.
     *
     * Payment-document tests upload real images, and without this they pile up
     * under storage/tenant<slug>/ run after run - thousands of orphaned files
     * that nothing references and nobody notices until they are staged into a
     * commit.
     */
    protected function clearTenantUploads(): void
    {
        $this->tenant->run(function () {
            foreach (['payment-documents', 'member-documents'] as $directory) {
                \Illuminate\Support\Facades\Storage::disk('local')->deleteDirectory($directory);
            }
        });
    }

    protected function dropTenantArtefacts(): void
    {
        $this->dropTenantArtefactsFor(static::SLUG);
    }

    /**
     * Leave no connection open holding a metadata lock.
     *
     * The tenant connection outlives the tenancy context, and a lingering one
     * that touched `tenants` blocks the next process's `migrate:fresh` DROP
     * TABLE. That is not theoretical - it hung this suite for ten minutes with
     * no output.
     */
    protected function tearDown(): void
    {
        try {
            tenancy()->end();
        } catch (\Throwable) {
        }

        DB::purge('tenant');

        parent::tearDown();
    }

    /**
     * Re-resolve the authenticated user on every simulated request.
     *
     * Laravel's guards cache their user, and the test container survives across
     * `$this->getJson()` calls - so a second request in one test silently reuses
     * the first request's user. That masked two genuine authorisation
     * assertions here: a stranger appeared able to read another member's
     * payment, and a revoked token appeared still valid.
     *
     * Production never behaves this way (a fresh container per request; Octane
     * flushes guards itself), so this aligns the harness with reality rather
     * than working around a real defect.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app['auth']->forgetGuards();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    /** Run a closure inside the tenant's database context. */
    protected function inTenant(callable $callback): mixed
    {
        return $this->tenant->run($callback);
    }

    protected function assertTenantTableExists(string $table): void
    {
        $this->assertTrue(
            $this->inTenant(fn () => Schema::hasTable($table)),
            "Tenant table [{$table}] is missing."
        );
    }
}
