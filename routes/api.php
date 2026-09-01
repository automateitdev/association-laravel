<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DuesController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\TenantLookupController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
|
| Versioned in the path from day one. Mobile clients update on the user's
| schedule, not ours, so assume an old build is in the field for months and
| keep v1 serving it (bcs-docs/05-api-contract.md section 8).
|
*/

Route::prefix('v1')->group(function () {

    // ---- central: no association required ----------------------------
    //
    // The only two endpoints that work without X-Tenant. Everything else is
    // scoped to one association's database.

    Route::get('/health', fn () => response()->json(['data' => ['status' => 'ok']]));
    Route::get('/tenants/lookup', TenantLookupController::class);

    // ---- tenant-scoped ------------------------------------------------
    //
    // ResolveTenant runs BEFORE auth: a token is meaningless until we know
    // which database to check it against.

    // ResolveTenant is GROUP middleware on `api`, applied in bootstrap/app.php,
    // because Laravel's priority list would otherwise sort Authenticate ahead
    // of it. See the comment there.

    Route::group([], function () {

        Route::post('/auth/login', [AuthController::class, 'login']);

        Route::middleware('auth:sanctum')->group(function () {

            Route::post('/auth/logout', [AuthController::class, 'logout']);
            Route::get('/me', [AuthController::class, 'me']);

            // ---- member surface ---------------------------------------

            Route::get('/fees/dues', [DuesController::class, 'index'])
                ->middleware('ability:member.dues.view');

            Route::get('/fees/summary', [DuesController::class, 'summary'])
                ->middleware('ability:member.dues.view');

            Route::get('/payments', [PaymentController::class, 'index'])
                ->middleware('ability:member.payments.view');

            Route::get('/payments/{payment}', [PaymentController::class, 'show'])
                ->middleware('ability:member.payments.view');

            Route::post('/payments', [PaymentController::class, 'store'])
                ->middleware('ability:member.payments.create');
        });
    });
});
