<?php

declare(strict_types=1);

use App\Http\Controllers\Platform\PlatformController;
use App\Http\Controllers\Platform\SessionController;
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
    });

    Route::middleware('auth:operator')->group(function () {
        Route::post('/logout', [SessionController::class, 'destroy'])->name('logout');

        Route::get('/', [PlatformController::class, 'index'])->name('index');
        Route::get('/audit', [PlatformController::class, 'audit'])->name('audit');

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
    });
});
