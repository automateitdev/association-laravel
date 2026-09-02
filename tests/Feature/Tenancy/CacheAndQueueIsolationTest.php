<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Models\Tenant;
use App\Providers\TenancyGuardServiceProvider;
use Illuminate\Cache\TaggableStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\Jobs\RecordTenantMarkerJob;
use Tests\Support\ParallelSlugs;
use Tests\TestCase;

/**
 * T-22 and T-23: the isolation mechanisms that are NOT the database connection.
 *
 * The database is the barrier everybody remembers. Cache and queues are the two
 * that get forgotten, and they fail differently:
 *
 *   CACHE  - the worst kind. Data served for the wrong association has the right
 *            shape, plausible numbers, and raises no error. It looks exactly
 *            like working software.
 *
 *   QUEUE  - a job dispatched in one association's context, executed by a worker
 *            that has forgotten whose job it is, writes to whatever connection
 *            happens to be default. That is how ledger rows reach the wrong
 *            books.
 *
 * HOW TENANT CACHE ISOLATION ACTUALLY WORKS HERE
 * ----------------------------------------------
 * stancl/tenancy scopes cache with TAGS, not key prefixes: inside tenancy every
 * call is routed through ->tags(['tenant<id>']). That has a consequence worth
 * stating plainly, because it is not obvious and it is dangerous:
 *
 *   the cache DRIVER is part of the isolation boundary.
 *
 * Tags work on array/redis/memcached/dynamodb. They do NOT work on database or
 * file - and Laravel 11+ ships with CACHE_STORE=database. TenancyGuardServiceProvider
 * refuses to boot on a non-taggable driver for exactly this reason, and the first
 * test below is that guard.
 */
class CacheAndQueueIsolationTest extends TestCase
{
    use ParallelSlugs;
    use RefreshDatabase;

    private const ALPHA = 'cache-alpha';

    private const BETA = 'cache-beta';

    private Tenant $alpha;

    private Tenant $beta;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::ALPHA, self::BETA] as $base) {
            $this->artisan('tenant:provision', ['slug' => $this->slugFor($base)])->assertSuccessful();
        }

        $this->alpha = Tenant::find($this->slugFor(self::ALPHA));
        $this->beta = Tenant::find($this->slugFor(self::BETA));
    }

    protected function tearDown(): void
    {
        foreach ([self::ALPHA, self::BETA] as $base) {
            try {
                Tenant::find($this->slugFor($base))?->delete();
            } catch (\Throwable) {
            }

            $this->dropTenantArtefactsFor($base);
        }

        parent::tearDown();
    }

    // ---- T-22: cache ----------------------------------------------------

    /**
     * The configured cache store MUST support tags, or tenant cache isolation
     * is not happening at all.
     *
     * This is the assertion that actually protects production. A round-trip
     * test only runs in CI; this one stops the application booting.
     */
    public function test_the_configured_cache_store_supports_tags(): void
    {
        $store = Cache::store(config('cache.default'))->getStore();

        $this->assertInstanceOf(
            TaggableStore::class,
            $store,
            'Tenant cache isolation is implemented with tags. A non-taggable store '
            .'(database, file) would serve one association cached data belonging to '
            .'another, silently. Use redis in production.'
        );
    }

    /**
     * The guard refuses to boot rather than leaking quietly.
     */
    public function test_the_application_refuses_to_boot_on_a_non_taggable_cache_store(): void
    {
        config()->set('cache.default', 'file');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not support tags');

        (new TenancyGuardServiceProvider($this->app))->boot();
    }

    /**
     * Inside tenancy, values are tag-scoped rather than written bare.
     *
     * Reading the SAME key straight off the underlying store - bypassing the
     * tenant tag - must not return the tenant's value. If it did, the tag were
     * not being applied and every association would share one keyspace.
     */
    public function test_values_written_inside_tenancy_are_tag_scoped(): void
    {
        $this->alpha->run(function () {
            Cache::put('dues.total', '1000.00', 60);

            // Same manager, same store instance, no tenant tag.
            $untagged = app('cache')->store()->get('dues.total');

            $this->assertNotSame(
                '1000.00',
                $untagged,
                'The value was written without a tenant tag - cache is not scoped.'
            );

            // And it is readable through the tagged path.
            $this->assertSame('1000.00', Cache::get('dues.total'));
        });
    }

    /**
     * A full cross-association round trip needs a store that SURVIVES a tenancy
     * switch, because CacheTenancyBootstrapper rebuilds the cache manager on
     * every initialize(). With the array driver the store is rebuilt empty, so
     * the assertion cannot be made honestly here.
     *
     * Skipped rather than faked: a test that passes because everything is null
     * cannot tell isolation from a cache that does not work at all, which is
     * exactly the false comfort this suite exists to avoid. Enable Redis in CI
     * to turn this on.
     */
    public function test_a_cached_value_never_crosses_associations(): void
    {
        if (config('cache.default') === 'array') {
            $this->markTestSkipped(
                'Needs a cache store shared across tenancy switches (Redis). '
                .'The array store is rebuilt empty on every initialize(), so a '
                .'passing assertion here would prove nothing.'
            );
        }

        $this->alpha->run(fn () => Cache::put('dues.total', '1000.00', 60));
        $this->beta->run(fn () => Cache::put('dues.total', '9999.99', 60));

        $this->assertSame('1000.00', $this->alpha->run(fn () => Cache::get('dues.total')));
        $this->assertSame('9999.99', $this->beta->run(fn () => Cache::get('dues.total')));
    }

    // ---- T-23: queues ---------------------------------------------------

    /**
     * A job dispatched inside one association's context must execute against
     * that association's database, even though the worker picks it up with no
     * tenancy of its own.
     *
     * Tenancy is ended before the worker runs, so the worker genuinely starts
     * from the central context - which is what a production worker does.
     */
    public function test_a_queued_job_runs_against_the_association_that_dispatched_it(): void
    {
        config()->set('queue.default', 'database');

        // NOTE: a block body, not an arrow function, and deliberately so.
        //
        // Job::dispatch() returns a PendingDispatch which only queues the job
        // when it is DESTRUCTED. An arrow function returns that object out of
        // run(), so it is destructed after tenancy has already ended - and the
        // payload gets stamped with no tenant_id at all. The job then runs
        // against the central database.
        //
        // This is a live trap for application code too, not just tests:
        // `return SomeJob::dispatch(...)` as the last line of a tenant closure
        // silently loses its tenancy.
        $this->alpha->run(function () {
            RecordTenantMarkerJob::dispatch('from-alpha');
        });

        $this->assertSame(1, DB::connection('mysql')->table('jobs')->count(), 'Job was not queued.');


        tenancy()->end();

        Artisan::call('queue:work', ['--stop-when-empty' => true, '--tries' => 1]);

        $this->assertSame(
            [],
            DB::connection('mysql')->table('failed_jobs')->pluck('exception')->all(),
            'The job failed instead of running.'
        );

        $inAlpha = $this->alpha->run(
            fn () => DB::table('settings')->where('key', 'marker.from-alpha')->value('value')
        );
        $inBeta = $this->beta->run(
            fn () => DB::table('settings')->where('key', 'marker.from-alpha')->exists()
        );

        $this->assertSame('"'.$this->slugFor(self::ALPHA).'"', $inAlpha, 'The job must run in its own association.');
        $this->assertFalse($inBeta, 'The job must not touch another association.');
    }

    /**
     * Two jobs from two associations, processed by one worker, must land in two
     * different databases.
     */
    public function test_jobs_from_two_associations_do_not_mix(): void
    {
        config()->set('queue.default', 'database');

        // Block bodies: see the note in the previous test about PendingDispatch
        // being queued at destruction time.
        $this->alpha->run(function () {
            RecordTenantMarkerJob::dispatch('marker');
        });
        $this->beta->run(function () {
            RecordTenantMarkerJob::dispatch('marker');
        });

        tenancy()->end();

        Artisan::call('queue:work', ['--stop-when-empty' => true, '--tries' => 1]);

        $this->assertSame(
            '"'.$this->slugFor(self::ALPHA).'"',
            $this->alpha->run(fn () => DB::table('settings')->where('key', 'marker.marker')->value('value'))
        );
        $this->assertSame(
            '"'.$this->slugFor(self::BETA).'"',
            $this->beta->run(fn () => DB::table('settings')->where('key', 'marker.marker')->value('value'))
        );

        // One row each, not two in one database.
        foreach ([$this->alpha, $this->beta] as $tenant) {
            $this->assertSame(
                1,
                $tenant->run(fn () => DB::table('settings')->where('key', 'marker.marker')->count())
            );
        }
    }
}
