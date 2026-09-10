<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The front page belongs to routes/web.php.
 *
 * WHY THIS TEST EXISTS. The package's stock `routes/tenant.php` registers
 * `GET /` behind InitializeTenancyByDomain. Laravel keys routes by method and
 * URI, so that later registration REPLACED the welcome route rather than
 * losing to it, and every request to / on the console host answered
 * TenantCouldNotBeIdentifiedOnDomain - a 500 on the front page of the
 * deployment, from a file nobody had edited since the project was created.
 *
 * It survived because nothing asked for / . The API had health checks, the
 * console had its own routes, and the one URL a person types by hand was the
 * only one nobody tested.
 *
 * This application identifies tenants by the X-Tenant header, never by domain
 * - one deployment serves every association - so a domain-identified route
 * group is scaffolding, not architecture.
 */
class WelcomeRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_front_page_renders_without_a_tenant(): void
    {
        // No X-Tenant header, which is the point: this page is central.
        $this->get('/')->assertOk();
    }

    /**
     * Named rather than merely working, so a reintroduced tenant.php fails here
     * instead of in production. A 200 alone would not catch it - a domain route
     * could answer 200 for a request that happened to identify a tenant.
     */
    public function test_the_root_route_is_not_tenant_identified(): void
    {
        $root = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($r) => $r->uri() === '/' && in_array('GET', $r->methods(), true));

        $this->assertNotNull($root, 'No GET / route is registered at all.');

        $tenancyMiddleware = array_filter(
            $root->gatherMiddleware(),
            fn ($m) => is_string($m) && str_contains($m, 'Stancl\\Tenancy'),
        );

        $this->assertSame(
            [],
            array_values($tenancyMiddleware),
            'GET / carries tenancy middleware, so routes/tenant.php has come back.',
        );
    }
}
