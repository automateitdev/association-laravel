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
        then: function (): void {
            // The operator console. Web middleware (sessions, CSRF), no tenant.
            Illuminate\Support\Facades\Route::middleware([])
                ->group(__DIR__.'/../routes/platform.php');
        },
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * TLS ENDS AT THE REVERSE PROXY, so the app has to be told or every URL
         * it builds is wrong.
         *
         * Behind a proxy that terminates HTTPS, PHP sees a plain HTTP request.
         * Without this, `request()->isSecure()` is false, `url()` and `route()`
         * emit http://, and - the part that actually breaks rather than merely
         * looks wrong - a SIGNED URL is signed over the http form while the
         * browser requests the https one, so the signature does not match and
         * every member's document download 403s (NFR-SEC-4).
         *
         * Trusted by SUBNET, not '*'. Trusting any proxy means believing
         * X-Forwarded-For from whoever connects, which is a client's own header
         * until something in front strips it - and this app is deployed beside
         * other things on shared hosts. Private ranges are what the proxy can
         * actually be: a container on a docker bridge, or 127.0.0.1.
         *
         * TRUSTED_PROXIES overrides it for a deployment whose proxy sits
         * somewhere else entirely.
         */
        $middleware->trustProxies(
            at: array_map(
                trim(...),
                explode(',', (string) env('TRUSTED_PROXIES', '127.0.0.1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16')),
            ),
        );

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
         * The console's kill switch is GROUP middleware, for the same reason
         * ResolveTenant is (see below): `Authenticate` sits in Laravel's
         * middleware priority list and custom route middleware does not, so
         * declaring this as an alias let auth sort ahead of it - and a
         * disabled console answered a redirect to a login page instead of the
         * 404 that hides whether it exists at all.
         *
         * Group middleware always runs before route middleware, which is the
         * ordering this needs.
         */
        $middleware->appendToGroup('platform', [
            App\Http\Middleware\PlatformConsole::class,
        ]);

        /*
         * ...and placed BEFORE Authenticate in the priority list, which is what
         * actually settles the order. Being a group is not enough on its own:
         * a group named in a route's middleware list is expanded and then
         * sorted by priority, so without this the console's kill switch still
         * ran after auth and a disabled console answered a redirect instead of
         * a 404.
         */
        $middleware->prependToPriorityList(
            /*
             * The CONTRACT, not Authenticate itself. Laravel's priority list
             * names `Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests`,
             * so prepending before the concrete class matched nothing and
             * quietly appended to the END - which is how a disabled console
             * still answered a login redirect instead of a 404.
             */
            Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            App\Http\Middleware\PlatformConsole::class,
        );

        /*
         * Where an unauthenticated operator is sent. The app has no `login`
         * route - it is an API - so without this the framework looks for one
         * and 500s.
         */
        $middleware->redirectGuestsTo(fn ($request) => $request->is('platform*')
            ? route('platform.login')
            : null);

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
