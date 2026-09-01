<?php

use App\Exceptions\ApiException;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum's ability middleware, used to keep a member token out of a
        // staff endpoint even if routing were mis-configured (FR-AUTH-4).
        $middleware->alias([
            'ability' => Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
            'abilities' => Laravel\Sanctum\Http\Middleware\CheckForAnyAbility::class,

            // The staff/member boundary. Runs before `permission`, which calls
            // hasPermissionTo() - a method members do not have.
            'staff' => App\Http\Middleware\EnsureStaff::class,

            // Live role check against the tenant database. Token abilities are
            // a snapshot taken at login; this is the authority.
            'permission' => Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role' => Spatie\Permission\Middleware\RoleMiddleware::class,
        ]);

        /*
         * Tenant resolution MUST run before authentication (FR-TEN-1), so it is
         * GROUP middleware, not route middleware.
         *
         * Declaring it around the auth middleware in the route file is not
         * enough. Authenticate sits in Laravel's middleware priority list and
         * custom middleware does not, so the framework sorts auth ahead of it
         * regardless of declaration order - `route:list -v` shows
         * `Authenticate:sanctum` listed first. The symptom is quiet and
         * confusing: Sanctum looks the bearer token up in the CENTRAL database,
         * which has no personal_access_tokens table at all.
         *
         * Group middleware always runs before route middleware, so this removes
         * the dependency on priority ordering entirely. ResolveTenant itself
         * exempts the two central endpoints.
         *
         * A token is meaningless until we know which database to check it
         * against; this ordering is load-bearing, not cosmetic.
         */
        $middleware->api(prepend: [ResolveTenant::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        /*
         * One error envelope, for every failure (bcs-docs/05-api-contract.md).
         *
         * The app branches on `code` and displays `message`. Anything that
         * escapes as a raw framework error is a shape the app has never seen,
         * so the translations below are not cosmetic - they are the difference
         * between an actionable message and a spinner.
         */

        $exceptions->render(fn (ApiException $e) => $e->render());

        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'error' => [
                    'code' => $e->status === 429 ? 'RATE_LIMITED' : 'VALIDATION_FAILED',
                    'message' => $e->getMessage(),
                    'details' => $e->errors(),
                ],
            ], $e->status);
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'error' => [
                    'code' => 'TOKEN_EXPIRED',
                    'message' => 'Your session has ended. Please sign in again.',
                ],
            ], 401);
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'error' => [
                    'code' => 'INSUFFICIENT_PERMISSION',
                    'message' => 'You do not have permission to do that.',
                ],
            ], 403);
        });

        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'That resource was not found.',
                ],
            ], 404);
        });
    })->create();
