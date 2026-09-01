<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Validate an association slug at first launch (ADR-0002).
 *
 * One of only two endpoints that work without X-Tenant, so it deserves care:
 * it is the platform's only unauthenticated window onto the central database.
 *
 * It returns display information only - name, locale, currency. No member
 * counts, no contact details, nothing that would make the endpoint worth
 * scraping. It is rate limited per IP because an unauthenticated lookup is
 * otherwise a free slug-enumeration oracle (open question AQ-4).
 */
class TenantLookupController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'slug' => ['required', 'string', 'max:50'],
        ]);

        $key = 'tenant-lookup:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 20)) {
            return response()->json([
                'error' => [
                    'code' => 'RATE_LIMITED',
                    'message' => 'Too many lookups. Please wait a moment.',
                    'details' => ['retry_after' => RateLimiter::availableIn($key)],
                ],
            ], 429);
        }

        RateLimiter::hit($key, 60);

        $tenant = Tenant::query()
            ->where('id', $request->query('slug'))
            ->where('status', Tenant::STATUS_ACTIVE)
            ->first();

        if (! $tenant) {
            // Same shape and status whether the slug is unknown or merely
            // inactive, so the response does not become a directory of which
            // associations exist.
            return response()->json([
                'error' => [
                    'code' => 'TENANT_NOT_FOUND',
                    'message' => 'No active association was found with that code.',
                ],
            ], 404);
        }

        return response()->json([
            'data' => [
                'slug' => $tenant->getKey(),
                'name' => $tenant->name,
                'locale' => $tenant->locale,
                'currency' => $tenant->currency,
                'timezone' => $tenant->timezone,
            ],
        ]);
    }
}
