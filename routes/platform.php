<?php

declare(strict_types=1);

use App\Http\Controllers\Platform\BreakGlassController;
use App\Http\Controllers\Platform\GatewayController;
use App\Http\Controllers\Platform\PlatformController;
use App\Http\Controllers\Platform\SessionController;
use App\Http\Controllers\Platform\TenantDataController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Platform console (FR-PLT-1 … FR-PLT-5)
|--------------------------------------------------------------------------
|
| Separate from the API on purpose. Every route in routes/api.php resolves a
| tenant from `X-Tenant` before it authenticates anything; these resolve none,
| because an operator belongs to no association. Keeping them in different
| files means that difference is visible rather than a flag on a route group.
|
| The whole file sits behind `platform`, which 404s unless the console is
| enabled for this deployment.
|
*/

Route::middleware(['web', 'platform'])->prefix('platform')->name('platform.')->group(function () {
    Route::middleware('guest:operator')->group(function () {
        Route::get('/login', [SessionController::class, 'create'])->name('login');
        Route::post('/login', [SessionController::class, 'store'])->name('login.store');

        /*
         * The second factor. Still `guest`, because the password step
         * deliberately does NOT sign anybody in - the operator is pending, not
         * authenticated, until a code is verified.
         */
        Route::get('/challenge', [SessionController::class, 'challenge'])->name('challenge');
        Route::post('/challenge', [SessionController::class, 'verify'])->name('challenge.verify');
    });

    Route::middleware('auth:operator')->group(function () {
        Route::post('/logout', [SessionController::class, 'destroy'])->name('logout');

        Route::get('/', [PlatformController::class, 'index'])->name('index');
        Route::get('/audit', [PlatformController::class, 'audit'])->name('audit');

        /*
         * Read-only, and deliberately so. Accounts are created and second
         * factors enrolled at the server (`operator:create`, `operator:mfa`):
         * a console that could mint its own users would make one stolen
         * session permanent.
         */
        Route::get('/operators', [PlatformController::class, 'operators'])->name('operators');

        // Before the {tenant} route, or "new" is read as an association id.
        Route::get('/tenants/new', [PlatformController::class, 'create'])->name('tenant.create');
        Route::post('/tenants', [PlatformController::class, 'store'])->name('tenant.store');

        Route::get('/tenants/{tenant}', [PlatformController::class, 'show'])->name('tenant');

        // Suspend, reinstate, archive - one endpoint, because they share the
        // required reason and the typed confirmation.
        Route::post('/tenants/{tenant}/transition', [PlatformController::class, 'transition'])
            ->name('tenant.transition');

        Route::post('/tenants/{tenant}/migrate', [PlatformController::class, 'migrate'])
            ->name('tenant.migrate');

        /*
         * Break-glass (FR-SEC-6).
         *
         * The grant routes and the routes that READ an association's data are
         * kept apart here as well as in the controllers. Everything under
         * `/break-glass` is about permission and touches no tenant database;
         * the two under `/tenants/{tenant}/data` are the only addresses in this
         * application where an operator sees an association's rows, and each
         * one asks for a live grant before it queries anything.
         */
        Route::get('/break-glass', [BreakGlassController::class, 'index'])->name('break-glass');
        Route::post('/tenants/{tenant}/break-glass', [BreakGlassController::class, 'store'])
            ->name('break-glass.request');
        Route::post('/break-glass/{grant}/decide', [BreakGlassController::class, 'decide'])
            ->name('break-glass.decide');
        Route::post('/break-glass/{grant}/renotify', [BreakGlassController::class, 'renotify'])
            ->name('break-glass.renotify');
        Route::post('/break-glass/{grant}/revoke', [BreakGlassController::class, 'revoke'])
            ->name('break-glass.revoke');

        /*
         * The payment gateway (FR-PAY-11).
         *
         * An operator surface, not the association's: `ar_account` is where
         * their money lands, and it used to be editable by anybody holding
         * `settings.edit`. There is no GET here on purpose - the credentials
         * are write-only, so there is no form to pre-fill and no screen that
         * could render one back.
         */
        Route::post('/tenants/{tenant}/gateway', [GatewayController::class, 'store'])
            ->name('tenant.gateway');
        Route::post('/tenants/{tenant}/gateway/toggle', [GatewayController::class, 'toggle'])
            ->name('tenant.gateway.toggle');

        Route::get('/tenants/{tenant}/data/members', [TenantDataController::class, 'members'])
            ->name('tenant.data.members');
        Route::get('/tenants/{tenant}/data/payments', [TenantDataController::class, 'payments'])
            ->name('tenant.data.payments');
    });
});
