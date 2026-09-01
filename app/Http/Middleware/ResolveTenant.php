<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Stancl\Tenancy\Database\Models\Domain;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolve the association before anything else happens (FR-TEN-1).
 *
 * This runs BEFORE authentication, and that order is not negotiable: a token is
 * meaningless until we know which database to check it against.
 *
 * Precedence, per ADR-0002:
 *   1. X-Tenant header  - the mobile app's path
 *   2. Request host     - cocsol.bcsapp.com, or a custom domain
 */
class ResolveTenant
{
    /**
     * The only endpoints that work without an association.
     *
     * Kept here rather than in the route file because this middleware runs at
     * group level - it has to know what to skip.
     */
    private const CENTRAL_PATHS = [
        'api/v1/health',
        'api/v1/tenants/*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is(...self::CENTRAL_PATHS)) {
            return $next($request);
        }

        $tenant = $this->fromHeader($request) ?? $this->fromHost($request);

        if (! $tenant) {
            throw ApiException::tenantNotResolved();
        }

        // A suspended association is refused at the edge, before a single query
        // touches its data (FR-TEN-3). This is one of the reasons resolution is
        // by header rather than at login.
        if (! $tenant->isActive()) {
            throw ApiException::tenantSuspended($tenant->status);
        }

        tenancy()->initialize($tenant);

        // Every log line for this request carries the association, so an
        // investigation never has to guess whose data it is looking at.
        \Log::withContext(['tenant' => $tenant->getKey()]);

        return $next($request);
    }

    private function fromHeader(Request $request): ?Tenant
    {
        $slug = trim((string) $request->header('X-Tenant'));

        if ($slug === '') {
            return null;
        }

        return Tenant::find($slug);
    }

    private function fromHost(Request $request): ?Tenant
    {
        $domain = Domain::query()->where('domain', $request->getHost())->first();

        return $domain?->tenant;
    }
}
