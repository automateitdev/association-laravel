<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\TaggableStore;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Refuse to boot on a configuration that silently breaks tenant isolation.
 *
 * THE CACHE DRIVER IS PART OF THE ISOLATION BOUNDARY
 * --------------------------------------------------
 * stancl/tenancy scopes tenant cache with TAGS, not key prefixes: every call
 * made inside tenancy is routed through `->tags(['tenant<id>'])`. Tags are only
 * supported by taggable stores - array, redis, memcached, dynamodb. They are
 * NOT supported by `database` or `file`.
 *
 * Laravel 11+ ships with CACHE_STORE=database as the default, so the out-of-
 * the-box configuration walks straight into this. That is threat T-2 in
 * bcs-docs/06-security-and-tenancy.md - rated the leak "easiest to miss and
 * hardest to notice", because a cache serving one association's numbers to
 * another looks exactly like working software: right shape, plausible values,
 * no error.
 *
 * A misconfiguration here must therefore stop the application starting, not
 * surface as a support ticket about strange figures. Redis is a REQUIREMENT of
 * this platform, not a performance preference.
 */
class TenancyGuardServiceProvider extends ServiceProvider
{
    /** Drivers whose stores support tagging, and therefore tenant scoping. */
    private const TAGGABLE_DRIVERS = ['array', 'redis', 'memcached', 'dynamodb'];

    public function boot(): void
    {
        $this->assertCacheStoreIsTaggable();
    }

    private function assertCacheStoreIsTaggable(): void
    {
        $name = config('cache.default');
        $driver = config("cache.stores.{$name}.driver");

        if (in_array($driver, self::TAGGABLE_DRIVERS, true)) {
            return;
        }

        // Belt and braces: a custom store may still be taggable even if its
        // driver name is unfamiliar, so check the class before refusing.
        try {
            if ($this->app['cache']->store($name)->getStore() instanceof TaggableStore) {
                return;
            }
        } catch (\Throwable) {
            // Fall through to the exception below - if the store cannot even be
            // resolved, refusing to boot is still the right answer.
        }

        throw new RuntimeException(
            "Cache store [{$name}] uses the [{$driver}] driver, which does not support tags.\n"
            ."Tenant cache isolation is implemented with tags, so this configuration would let\n"
            ."one association's cached data be served to another - silently, with no error.\n\n"
            .'Use redis (memcached and dynamodb also work; array is fine for tests). '
            ."See bcs-docs/06-security-and-tenancy.md threat T-2."
        );
    }
}
