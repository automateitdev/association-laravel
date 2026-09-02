<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DuesController;
use App\Http\Controllers\Api\V1\GatewayController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PaymentDocumentController;
use App\Http\Controllers\Api\V1\Staff\FeeController;
use App\Http\Controllers\Api\V1\Staff\LedgerController;
use App\Http\Controllers\Api\V1\Staff\MemberController;
use App\Http\Controllers\Api\V1\Staff\PaymentApprovalController;
use App\Http\Controllers\Api\V1\Staff\ReportController;
use App\Http\Controllers\Api\V1\Staff\SettingsController;
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
| Tenant resolution is GROUP middleware on `api`, applied in bootstrap/app.php,
| because Laravel's priority list would otherwise sort Authenticate ahead of a
| route-level ResolveTenant. See the comment there.
|
| Every route below is checked by RouteAuthorisationSweepTest (T-14): it must
| be authenticated AND authorised, or allowlisted with a written reason.
|
*/

Route::prefix('v1')->group(function () {

    // ---- central: no association required ----------------------------

    Route::get('/health', fn () => response()->json(['data' => ['status' => 'ok']]));
    Route::get('/tenants/lookup', TenantLookupController::class);

    /*
     * ---- gateway callbacks -------------------------------------------
     *
     * Not called by the app. The gateway has no session with us and sends no
     * X-Tenant header, so the association travels in the URL we handed it when
     * the session was created; authenticity comes from the signature, checked
     * before anything is acted on.
     *
     * Deliberately outside `auth:sanctum` - and allowlisted in
     * RouteAuthorisationSweepTest with that reasoning written down.
     */
    Route::post('/webhooks/{tenant}/gateway', [GatewayController::class, 'webhook'])
        ->name('api.gateway.webhook');

    Route::get('/webhooks/{tenant}/return/{payment}', [GatewayController::class, 'returnUrl'])
        ->name('api.gateway.return');

    // ---- identity ------------------------------------------------------

    Route::post('/auth/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {

        // Self-scoped: these act only on the token holder.
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);

        // ---- member surface --------------------------------------------
        //
        // Guarded by token abilities. A member token carries only member.*
        // abilities, so it can never reach the staff block below.

        Route::get('/fees/dues', [DuesController::class, 'index'])
            ->middleware('ability:member.dues.view');

        Route::get('/fees/summary', [DuesController::class, 'summary'])
            ->middleware('ability:member.dues.view');

        // Where to send the money, and whether online payment is offered at
        // all. Without this the manual flow asks for a transfer and never says
        // to which account.
        Route::get('/fees/payment-instructions', [DuesController::class, 'instructions'])
            ->middleware('ability:member.dues.view');

        // What a chosen set of instalments comes to. Exists so the app never
        // adds money up: the member must be told the amount before the payment
        // is created, and a client-side sum is the arithmetic FR-MON-6 forbids.
        Route::post('/fees/quote', [DuesController::class, 'quote'])
            ->middleware('ability:member.dues.view');

        Route::get('/payments', [PaymentController::class, 'index'])
            ->middleware('ability:member.payments.view');

        Route::get('/payments/{payment}', [PaymentController::class, 'show'])
            ->middleware('ability:member.payments.view');

        Route::post('/payments', [PaymentController::class, 'store'])
            ->middleware('ability:member.payments.create');

        /*
         * Proof-of-payment documents.
         *
         * With no gateway integrated, a member pays at a bank and uploads the
         * slip; staff approve against it. These files are evidence an approval
         * decision rests on, so they are never served by URL - the download
         * route streams them after an ownership or permission check
         * (NFR-SEC-4).
         *
         * Shared between members and staff: a member reaches their own
         * payment's documents, staff reach any if they may view payments. The
         * controller makes that decision in one place.
         */
        Route::get('/payments/{payment}/documents', [PaymentDocumentController::class, 'index'])
            ->middleware('abilities:member.payments.view,payments.view');
        Route::post('/payments/{payment}/documents', [PaymentDocumentController::class, 'store'])
            ->middleware('abilities:member.payments.create,payments.view');
        Route::get('/payments/{payment}/documents/{index}', [PaymentDocumentController::class, 'show'])
            ->middleware('abilities:member.payments.view,payments.view');

        // Two calls, not one: the payment row exists before the member is sent
        // anywhere, so a dropped round trip is reconcilable (FR-PAY-7).
        Route::post('/payments/{payment}/gateway-session', [GatewayController::class, 'startSession'])
            ->middleware('ability:member.payments.create');

        // ---- staff surface ---------------------------------------------
        //
        // Two barriers: `staff` rejects member tokens outright, then
        // `permission` checks the LIVE role in the tenant database. Token
        // abilities are a snapshot taken at login; a staff member demoted
        // this morning still holds a token minted this morning, so the live
        // check is the one that actually holds (ADR-0004).

        Route::middleware('staff')->prefix('staff')->group(function () {

            Route::get('/dashboard', [ReportController::class, 'dashboard'])
                ->middleware('permission:dashboard.view');

            // Members
            Route::get('/members', [MemberController::class, 'index'])
                ->middleware('permission:members.view');
            Route::post('/members', [MemberController::class, 'store'])
                ->middleware('permission:members.create');
            Route::get('/members/{member}', [MemberController::class, 'show'])
                ->middleware('permission:members.view');
            Route::put('/members/{member}', [MemberController::class, 'update'])
                ->middleware('permission:members.edit');

            // The society record: number, join date, share number, employer.
            // Gated on members.edit rather than members.approve - assigning a
            // number is office record-keeping, not the decision to admit.
            Route::put('/members/{member}/associator-info', [MemberController::class, 'assignAssociatorInfo'])
                ->middleware('permission:members.edit');

            Route::post('/members/{member}/approve', [MemberController::class, 'approve'])
                ->middleware('permission:members.approve');
            Route::post('/members/{member}/reject', [MemberController::class, 'reject'])
                ->middleware('permission:members.approve');
            Route::post('/members/{member}/suspend', [MemberController::class, 'suspend'])
                ->middleware('permission:members.suspend');
            Route::post('/members/{member}/reinstate', [MemberController::class, 'reinstate'])
                ->middleware('permission:members.suspend');

            // The chart of accounts, so a fee head can name where its
            // instalment and its fine income each post (FR-FEE-2).
            Route::get('/ledgers', [LedgerController::class, 'index'])
                ->middleware('permission:ledgers.view');

            // Fee heads and assignment
            Route::get('/fee-setups', [FeeController::class, 'indexSetups'])
                ->middleware('permission:fee-setups.view');
            Route::post('/fee-setups', [FeeController::class, 'storeSetup'])
                ->middleware('permission:fee-setups.create');
            Route::put('/fee-setups/{feeSetup}', [FeeController::class, 'updateSetup'])
                ->middleware('permission:fee-setups.edit');

            Route::get('/fee-assigns', [FeeController::class, 'indexAssigns'])
                ->middleware('permission:fee-assigns.view');
            Route::post('/fee-assigns', [FeeController::class, 'storeAssigns'])
                ->middleware('permission:fee-assigns.create');

            // Payment approval
            Route::get('/payments/pending', [PaymentApprovalController::class, 'pending'])
                ->middleware('permission:payments.view');
            Route::post('/payments/decide', [PaymentApprovalController::class, 'decide'])
                ->middleware('permission:payments.approve');

            // Association configuration. superadmin only - these change how
            // money is calculated and where members are told to send it.
            Route::get('/settings', [SettingsController::class, 'index'])
                ->middleware('permission:settings.view');
            Route::put('/settings', [SettingsController::class, 'update'])
                ->middleware('permission:settings.edit');

            // Gateway credentials are WRITE-ONLY: stored, never read back.
            Route::put('/settings/gateway', [SettingsController::class, 'updateGateway'])
                ->middleware('permission:settings.edit');

            // Reports
            Route::get('/reports/memberwise-paid', [ReportController::class, 'memberwisePaid'])
                ->middleware('permission:reports.paid');
            Route::get('/reports/due-info', [ReportController::class, 'dueInfo'])
                ->middleware('permission:reports.due');
        });
    });
});
