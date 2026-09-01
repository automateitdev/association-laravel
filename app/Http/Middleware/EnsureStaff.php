<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The staff/member boundary.
 *
 * Runs before spatie's permission middleware, which calls hasPermissionTo() -
 * a method members do not have. Without this, a member token reaching a staff
 * route produces a confusing framework error instead of a clean 403.
 *
 * Two independent barriers guard staff endpoints:
 *   1. token abilities  - a member token carries only member.* abilities
 *   2. this + permission - live role check against the tenant database
 *
 * Abilities alone are not enough: a token issued before a demotion still
 * carries the old abilities, so the live check is what actually holds
 * (ADR-0004 records this trade-off).
 */
class EnsureStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user() instanceof User) {
            throw ApiException::insufficientPermission('staff');
        }

        return $next($request);
    }
}
