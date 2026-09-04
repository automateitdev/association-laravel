<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DuesController;
use App\Http\Controllers\Api\V1\GatewayController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PaymentDocumentController;
use App\Http\Controllers\Api\V1\Staff\AuditController;
use App\Http\Controllers\Api\V1\Staff\CollectionController;
use App\Http\Controllers\Api\V1\Staff\FeeController;
use App\Http\Controllers\Api\V1\Staff\LedgerController;
use App\Http\Controllers\Api\V1\Staff\MemberController;
use App\Http\Controllers\Api\V1\Staff\NomineeController;
use App\Http\Controllers\Api\V1\Staff\PaymentApprovalController;
use App\Http\Controllers\Api\V1\Staff\ReportController;
use App\Http\Controllers\Api\V1\Staff\RoleController;
use App\Http\Controllers\Api\V1\Staff\UserController;
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

    /*
     * A health check that can actually fail. See HealthController - the version
     * this replaces returned 200 through two real outages.
     */
    Route::get('/health', HealthController::class);
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

        /*
         * The member's own receipt. Above the {payment} route, or "invoice"
         * is matched as a payment id.
         */
        Route::get('/payments/{payment}/invoice', [PaymentController::class, 'invoice'])
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
            /*
              * Exports sit BEFORE the /{member} route, or Laravel matches
              * "export" as a member id and returns a 404 for a route that
              * exists.
              */
            Route::get('/members/export', [MemberController::class, 'export'])
                ->middleware(['permission:members.view', 'permission:reports.export']);

            Route::get('/members', [MemberController::class, 'index'])
                ->middleware('permission:members.view');
            Route::post('/members', [MemberController::class, 'store'])
                ->middleware('permission:members.create');
            Route::get('/members/{member}', [MemberController::class, 'show'])
                ->middleware('permission:members.view');
            Route::put('/members/{member}', [MemberController::class, 'update'])
                ->middleware('permission:members.edit');

            // The society record: number, join date, share number, employer.
            /*
             * Its own permission rather than members.edit.
             *
             * The catalogue has always declared associator.view/edit; this
             * route was gated on members.edit only because nothing else used
             * them yet. Assigning a membership number is office record-keeping,
             * and an association may reasonably let somebody edit a member's
             * contact details without letting them renumber the register.
             *
             * Safe to narrow: both seeded roles that hold members.edit
             * (superadmin, admin) also hold associator.edit.
             */
            Route::get('/associator-infos/export', [MemberController::class, 'exportAssociatorInfos'])
                ->middleware(['permission:associator.view', 'permission:reports.export']);

            Route::get('/associator-infos', [MemberController::class, 'associatorInfos'])
                ->middleware('permission:associator.view');

            Route::put('/members/{member}/associator-info', [MemberController::class, 'assignAssociatorInfo'])
                ->middleware('permission:associator.edit');

            /*
             * Nominees (FR-MEM-9). One permission covers reading and writing:
             * an association that lets somebody see who a member nominated has
             * no reason to stop them correcting it, and splitting the two would
             * be a distinction nobody asked for.
             */
            Route::get('/members/{member}/nominees', [NomineeController::class, 'index'])
                ->middleware('permission:nominees.manage');
            Route::post('/members/{member}/nominees', [NomineeController::class, 'store'])
                ->middleware('permission:nominees.manage');
            Route::put('/nominees/{nominee}', [NomineeController::class, 'update'])
                ->middleware('permission:nominees.manage');
            Route::delete('/nominees/{nominee}', [NomineeController::class, 'destroy'])
                ->middleware('permission:nominees.manage');

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

            /*
             * Groups are read with ledgers.view rather than ledgers.create:
             * the create FORM needs them, but so does anyone reading the chart
             * and wondering what a ledger is filed under.
             */
            Route::get('/account-groups', [LedgerController::class, 'groups'])
                ->middleware('permission:ledgers.view');

            Route::post('/ledgers', [LedgerController::class, 'store'])
                ->middleware('permission:ledgers.create');
            Route::put('/ledgers/{ledger}', [LedgerController::class, 'update'])
                ->middleware('permission:ledgers.edit');

            // Fee heads and assignment
            Route::get('/fee-setups/export', [FeeController::class, 'exportSetups'])
                ->middleware(['permission:fee-setups.view', 'permission:reports.export']);

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

            /*
             * Its own permission, not fee-assigns.edit: waiving a fine changes
             * what a member owes, and an association may well want the person
             * who assigns fees not to be the person who can forgive them.
             */
            Route::post('/fee-assigns/{feeAssign}/fine-adjustment', [FeeController::class, 'adjustFine'])
                ->middleware('permission:fines.adjust');

            // Payment approval
            Route::get('/payments/pending/export', [PaymentApprovalController::class, 'exportPending'])
                ->middleware(['permission:payments.view', 'permission:reports.export']);

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

            /*
             * Taking money at the counter (FR-FEE-9).
             *
             * Two permissions, because looking up what a member owes and
             * actually recording a payment against it are different acts - a
             * clerk may be trusted with one and not the other.
             */
            Route::get('/members/{member}/dues', [CollectionController::class, 'dues'])
                ->middleware('permission:collections.view');
            Route::post('/collections', [CollectionController::class, 'store'])
                ->middleware('permission:collections.create');

            // The counter's receipt. Gated on seeing payments, not on taking
            // them: reprinting a receipt is a lookup, not a collection.
            Route::get('/payments/{payment}/invoice', [CollectionController::class, 'invoice'])
                ->middleware('permission:payments.view');

            /*
             * Staff administration (FR-RBAC-1).
             *
             * Nothing here existed, which meant an association could not
             * onboard its own office - the seeded account was the only way in.
             */
            Route::get('/users', [UserController::class, 'index'])
                ->middleware('permission:users.view');
            Route::post('/users', [UserController::class, 'store'])
                ->middleware('permission:users.create');
            Route::put('/users/{user}', [UserController::class, 'update'])
                ->middleware('permission:users.edit');
            Route::delete('/users/{user}', [UserController::class, 'destroy'])
                ->middleware('permission:users.delete');

            // The catalogue a role editor is built from. Gated on roles.view
            // rather than being public: it enumerates everything this build can
            // do, which is not something to hand out unasked.
            Route::get('/permissions', [RoleController::class, 'permissions'])
                ->middleware('permission:roles.view');

            Route::get('/roles', [RoleController::class, 'index'])
                ->middleware('permission:roles.view');
            Route::post('/roles', [RoleController::class, 'store'])
                ->middleware('permission:roles.create');
            Route::put('/roles/{role}', [RoleController::class, 'update'])
                ->middleware('permission:roles.edit');
            Route::delete('/roles/{role}', [RoleController::class, 'destroy'])
                ->middleware('permission:roles.delete');

            // Reports
            Route::get('/reports/memberwise-paid', [ReportController::class, 'memberwisePaid'])
                ->middleware('permission:reports.paid');
            Route::get('/reports/due-info', [ReportController::class, 'dueInfo'])
                ->middleware('permission:reports.due');

            /*
             * FR-REP-6. Deliberately its own permission: this report names
             * members whose figures are wrong, which is not the same audience
             * as the reports staff read every day.
             */
            Route::get('/reports/inconsistencies', [AuditController::class, 'index'])
                ->middleware('permission:reports.inconsistency');

            /*
             * Downloads (FR-REP-7). TWO permissions each, and the pair is the
             * point: `reports.export` alone must not open a report the account
             * cannot already read on screen, and being allowed to read one on
             * screen must not imply permission to walk out with the file.
             *
             * Listed as separate middleware entries because that is AND -
             * `permission:a|b` is OR, which here would mean either one grants
             * the download.
             */
            Route::get('/reports/memberwise-paid/export', [ReportController::class, 'exportMemberwisePaid'])
                ->middleware(['permission:reports.paid', 'permission:reports.export']);
            Route::get('/reports/due-info/export', [ReportController::class, 'exportDueInfo'])
                ->middleware(['permission:reports.due', 'permission:reports.export']);
            Route::get('/reports/inconsistencies/export', [AuditController::class, 'export'])
                ->middleware(['permission:reports.inconsistency', 'permission:reports.export']);
        });
    });
});
