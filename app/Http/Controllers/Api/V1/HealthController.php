<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Is this API actually able to serve a request?
 *
 * WHY THIS TOUCHES THE DATABASE
 * -----------------------------
 * It used to be `fn () => response()->json(['status' => 'ok'])` - a route that
 * proved PHP was running and nothing else. That is worse than having no health
 * check, because it answers the question it appears to answer WRONGLY.
 *
 * Twice during development MySQL stopped while this endpoint carried on
 * returning 200. Every real request was failing with a connection error and the
 * one endpoint whose job is to say so reported the API healthy. A monitor
 * pointed at it would have stayed green through a total outage.
 *
 * Nothing this API does is useful without the central database - it is where
 * the tenant registry lives, so a request cannot even be ROUTED to an
 * association without it. Checking it is therefore not "checking a dependency";
 * it is checking the one thing that makes an answer possible.
 *
 * WHAT IT DELIBERATELY DOES NOT DO
 * --------------------------------
 * It does not touch any tenant database. There may be hundreds, connecting to
 * each on every health check would make the check itself a load problem, and
 * one association's database being unreachable is not the same event as the API
 * being down. That is a per-tenant concern and belongs in a different signal.
 *
 * It also reports no version, no uptime and no dependency list: a health
 * endpoint is unauthenticated, and everything it returns is public.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        try {
            /*
             * The cheapest query that proves a working connection AND that the
             * central schema is actually there.
             *
             * `SELECT 1` would pass against a server with no database on it,
             * which is a state a half-finished deploy can genuinely reach.
             * Counting the tenant registry proves the thing every request
             * depends on.
             */
            DB::connection()->table('tenants')->count();
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'error' => [
                    'code' => 'DATABASE_UNAVAILABLE',
                    /*
                     * No exception message. This endpoint is unauthenticated,
                     * and a driver error names the host, the port and the
                     * database - which is a map for anybody who asks.
                     */
                    'message' => 'The service is not able to serve requests.',
                ],
            ], 503);
        }

        return response()->json(['data' => ['status' => 'ok']]);
    }
}
