<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Http\Middleware\ResolveTenant;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

/**
 * T-14: every registered API route has an authorisation check.
 *
 * WHY THIS IS MECHANICAL RATHER THAN A REVIEW HABIT
 * -------------------------------------------------
 * Defect D-5 in the legacy system is a payment endpoint whose authorize()
 * returns true. Nobody decided that; it was a default nobody revisited, and it
 * survived years of code review because a missing check looks exactly like code
 * that isn't there. Reviewers notice wrong lines, not absent ones.
 *
 * So this is enforced by a failing build. Adding a route without deciding its
 * authorisation is not possible without also editing this file, and editing
 * this file is a deliberate act a reviewer will see.
 *
 * If you are here because the build broke: do not add your route to the
 * allowlist to make it pass. Give it an ability or a permission. The allowlist
 * is for endpoints that genuinely have no caller identity.
 */
class RouteAuthorisationSweepTest extends TestCase
{
    /**
     * Endpoints that are deliberately public, each with the reason it is safe.
     *
     * Anything added here needs a justification that survives being read aloud
     * to someone who does not trust you.
     */
    private const PUBLIC_ROUTES = [
        // Liveness. Returns a constant; touches no database.
        'GET api/v1/health',

        // First-launch association picker (ADR-0002). Returns display fields
        // only, rate limited per IP, and answers identically for an unknown and
        // an inactive slug so it cannot be used to enumerate associations.
        'GET api/v1/tenants/lookup',

        // The endpoint that establishes identity. Throttled per identifier and
        // per IP; refuses inactive and suspended members with distinct codes.
        'POST api/v1/auth/login',
    ];

    /**
     * Authenticated endpoints that act ONLY on the token holder, so an ability
     * would be checking that a member is themselves.
     *
     * These still require auth:sanctum - they are exempt from the ability
     * requirement, not from authentication.
     */
    private const SELF_SCOPED_ROUTES = [
        'POST api/v1/auth/logout',  // revokes the caller's own current token
        'GET api/v1/me',            // returns the caller's own profile
    ];

    /**
     * Middleware that constitutes an authorisation decision, as opposed to
     * merely establishing who is calling.
     */
    private const AUTHORISATION_MIDDLEWARE = ['ability', 'abilities', 'can', 'permission', 'role'];

    public function test_every_api_route_is_authenticated_and_authorised(): void
    {
        $failures = [];

        foreach ($this->apiRoutes() as $route) {
            $signature = $this->signature($route);
            $middleware = $this->resolvedMiddleware($route);

            if (in_array($signature, self::PUBLIC_ROUTES, true)) {
                continue;
            }

            if (! $this->routeIsAuthenticated($middleware)) {
                $failures[] = "{$signature} has no auth:sanctum middleware.";

                continue;
            }

            if (in_array($signature, self::SELF_SCOPED_ROUTES, true)) {
                continue;
            }

            if (! $this->routeIsAuthorised($middleware)) {
                $failures[] = "{$signature} is authenticated but never authorised - "
                    .'add an ability/permission, or justify it in SELF_SCOPED_ROUTES.';
            }
        }

        $this->assertSame([], $failures, "\n".implode("\n", $failures)."\n");
    }

    /**
     * The allowlists must not rot into a graveyard of routes that no longer
     * exist - a stale entry silently exempts a future route that reuses the URI.
     */
    public function test_the_allowlists_contain_no_stale_entries(): void
    {
        $existing = collect($this->apiRoutes())->map(fn (Route $r) => $this->signature($r))->all();

        $stale = array_diff(
            [...self::PUBLIC_ROUTES, ...self::SELF_SCOPED_ROUTES],
            $existing
        );

        $this->assertSame(
            [],
            array_values($stale),
            'These allowlisted routes no longer exist and must be removed: '
                .implode(', ', $stale)
        );
    }

    /**
     * Tenant resolution is group middleware precisely so that it cannot be
     * forgotten per route. If someone moves it back, this catches it.
     */
    public function test_every_tenant_scoped_route_resolves_a_tenant_first(): void
    {
        $central = ['GET api/v1/health', 'GET api/v1/tenants/lookup'];
        $failures = [];

        foreach ($this->apiRoutes() as $route) {
            $signature = $this->signature($route);

            if (in_array($signature, $central, true)) {
                continue;
            }

            if (! in_array(ResolveTenant::class, $this->resolvedMiddleware($route), true)) {
                $failures[] = "{$signature} does not resolve a tenant.";
            }
        }

        $this->assertSame([], $failures, "\n".implode("\n", $failures)."\n");
    }

    // ---- helpers -------------------------------------------------------

    /**
     * A route's middleware with groups expanded.
     *
     * `gatherMiddleware()` returns route and controller middleware but leaves
     * group names ('api') unexpanded, so a naive check reports that no route
     * resolves a tenant - which is precisely backwards, since moving tenant
     * resolution INTO the group is what made it reliable.
     *
     * @return array<string>
     */
    private function resolvedMiddleware(Route $route): array
    {
        $groups = app(\Illuminate\Routing\Router::class)->getMiddlewareGroups();
        $resolved = [];

        foreach ($route->gatherMiddleware() as $entry) {
            if (isset($groups[$entry])) {
                foreach ($groups[$entry] as $inner) {
                    $resolved[] = is_string($inner) ? $inner : $inner::class;
                }

                continue;
            }

            $resolved[] = $entry;
        }

        return $resolved;
    }

    /** @return array<Route> */
    private function apiRoutes(): array
    {
        return array_values(array_filter(
            RouteFacade::getRoutes()->getRoutes(),
            fn (Route $route) => str_starts_with($route->uri(), 'api/')
        ));
    }

    private function signature(Route $route): string
    {
        $method = collect($route->methods())
            ->reject(fn ($m) => $m === 'HEAD')
            ->first();

        return "{$method} {$route->uri()}";
    }

    /** @param array<string> $middleware */
    private function routeIsAuthenticated(array $middleware): bool
    {
        foreach ($middleware as $entry) {
            if (str_starts_with($entry, 'auth:')) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string> $middleware */
    private function routeIsAuthorised(array $middleware): bool
    {
        foreach ($middleware as $entry) {
            $name = explode(':', $entry)[0];

            if (in_array($name, self::AUTHORISATION_MIDDLEWARE, true)) {
                return true;
            }
        }

        return false;
    }
}
