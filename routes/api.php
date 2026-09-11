<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\DuesController;
use App\Http\Controllers\Api\V1\GatewayController;
use App\Http\Controllers\Api\V1\PayflexCallbackController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PaymentDocumentController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\Staff\AuditController;
use App\Http\Controllers\Api\V1\Staff\CollectionController;
use App\Http\Controllers\Api\V1\Staff\FeeController;
use App\Http\Controllers\Api\V1\Staff\LedgerController;
use App\Http\Controllers\Api\V1\Staff\MemberController;
use App\Http\Controllers\Api\V1\Staff\NomineeController;
use App\Http\Controllers\Api\V1\Staff\PaymentApprovalController;
use App\Http\Controllers\Api\V1\Staff\ProfileUpdateController;
use App\Http\Controllers\Api\V1\Staff\ReportController;
use App\Http\Controllers\Api\V1\Staff\ShareController;
use App\Http\Controllers\Api\V1\Staff\RoleController;
use App\Http\Controllers\Api\V1\Staff\UserController;
use App\Http\Controllers\Api\V1\Staff\VoucherController;
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

/*
|--------------------------------------------------------------------------
| PayFlex callbacks — deliberately UNVERSIONED
|--------------------------------------------------------------------------
|
| The only routes in this file outside `v1`, and not by choice. PayFlex builds
| the callback URL itself: it strips everything from `/api/` off the address we
| gave it and appends its own fixed `/api/pay-flex/notify`. A version segment
| cannot survive that, and neither can a {tenant} - so the association is
| resolved from the HOST against the `domains` table, which means an association
| using PayFlex must have a working domain row.
|
| This is a real exception to FR-API-1, written down rather than quietly made.
| The alternative is changing `buildCallbackUrl` in the PayFlex repo, which
| would move the problem onto every other client already integrated with it.
|
| Unsigned, because PayFlex sends no signature. Safe only because the body is a
| doorbell: the single field read from it is an invoice number, and everything
| that decides whether money moved comes from asking PayFlex back over an
| authenticated request. See PayflexCallbackController.
|
| Two paths for one meaning — `notify` is PayFlex's first attempt, `verify` its
| retry. Allowlisted in RouteAuthorisationSweepTest with that reasoning.
|
*/

Route::post('/pay-flex/notify', PayflexCallbackController::class)->name('api.payflex.notify');
Route::post('/pay-flex/verify', PayflexCallbackController::class)->name('api.payflex.verify');

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

        /*
         * A member asking the office to change their details (FR-MEM-8).
         * Not an edit - nothing here changes the record. See ProfileController.
         */
        Route::get('/me/profile-updates', [ProfileController::class, 'index']);
        Route::post('/me/profile-updates', [ProfileController::class, 'store']);

        /*
         * A member's own identity documents (parity P-10).
         *
         * READ ONLY, and deliberately. Changing what the association identifies
         * you by goes through the profile-update queue an officer decides on
         * (FR-MEM-8) - and that queue does not carry files yet, so today staff
         * file these at the counter. Listing them still matters: a member
         * should be able to see what the office holds without asking.
         */
        Route::get('/me/documents', [DocumentController::class, 'meIndex']);
        Route::get('/me/documents/{slot}', [DocumentController::class, 'meShow']);

        /*
         * Submitting one for review (FR-MEM-8).
         *
         * Gated on the same ability as any other change a member asks for,
         * because that is what this is: nothing the association holds changes
         * until an officer approves it. The reads above need no ability - they
         * return the member's own record and nothing else - but a write does,
         * and the route sweep is right to insist.
         */
        Route::post('/me/documents', [DocumentController::class, 'meSubmit'])
            ->middleware('ability:member.profile.request-change');

        Route::get('/me/documents/{slot}/pending', [DocumentController::class, 'mePendingShow']);

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

            /*
             * Identity documents (parity P-10): photograph, NID front and back,
             * signature and the two proofs.
             *
             * Reading is `members.view`, writing is `members.edit`. The split
             * matters more here than elsewhere: the photograph and the NID are
             * how the association proves who somebody is, so an account that may
             * read the register has no business replacing them.
             *
             * A member reaches their own through /me/documents above.
             */
            Route::get('/members/{member}/documents', [DocumentController::class, 'index'])
                ->middleware('permission:members.view');
            Route::post('/members/{member}/documents', [DocumentController::class, 'store'])
                ->middleware('permission:members.edit');
            Route::get('/members/{member}/documents/{slot}', [DocumentController::class, 'show'])
                ->middleware('permission:members.view');
            Route::delete('/members/{member}/documents/{slot}', [DocumentController::class, 'destroy'])
                ->middleware('permission:members.edit');

            /*
             * A nominee's documents, on `nominees.manage` like everything else
             * about nominees. The legacy carries applicant AND nominee NID
             * images through its approval queue; this is the half of that which
             * does not need the queue.
             */
            Route::get('/nominees/{nominee}/documents', [DocumentController::class, 'nomineeIndex'])
                ->middleware('permission:nominees.manage');
            Route::post('/nominees/{nominee}/documents', [DocumentController::class, 'nomineeStore'])
                ->middleware('permission:nominees.manage');
            Route::get('/nominees/{nominee}/documents/{slot}', [DocumentController::class, 'nomineeShow'])
                ->middleware('permission:nominees.manage');
            Route::delete('/nominees/{nominee}/documents/{slot}', [DocumentController::class, 'nomineeDestroy'])
                ->middleware('permission:nominees.manage');

            /*
             * The document review queue (FR-MEM-8).
             *
             * On `profile-updates.decide`, the permission that already governs
             * deciding what a member may change about themselves. A document is
             * the same decision with a file attached, and inventing a second
             * permission for it would mean an association could grant one and
             * not the other without ever meaning to.
             */
            Route::get('/document-reviews', [DocumentController::class, 'reviewIndex'])
                ->middleware('permission:profile-updates.decide');
            Route::get('/document-reviews/{document}', [DocumentController::class, 'reviewShow'])
                ->middleware('permission:profile-updates.decide');
            Route::post('/document-reviews/{document}/decide', [DocumentController::class, 'reviewDecide'])
                ->middleware('permission:profile-updates.decide');

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

            /*
             * How much of a proposed assignment already exists.
             *
             * Its own route rather than a filter on the listing above, because
             * the question is not "show me assignments" - it is "of these
             * members and these periods, which are already done". The listing
             * pages at 25 and would need one request per member to answer it.
             */
            Route::get('/fee-assigns/coverage', [FeeController::class, 'assignCoverage'])
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

            /*
             * There is deliberately no route to SET the gateway.
             *
             * It moved to the platform operator - the console form at
             * /platform/tenants/{id}, or `php artisan tenant:gateway` at the
             * server, both through `GatewayConfigurator`. `ar_account` decides
             * where the money lands, and an endpoint that lets anybody with
             * `settings.edit` change it hands a money-diversion vector to a
             * role granted for editing fine rates. GET /settings still reports
             * whether a gateway is configured and which account it ends in.
             */

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

            /*
             * The queue of changes members have asked for (FR-MEM-8). Viewing
             * and deciding are separate permissions: an association may well
             * want somebody to read the queue without being able to approve
             * a change of mobile number.
             */
            Route::get('/profile-updates', [ProfileUpdateController::class, 'index'])
                ->middleware('permission:profile-updates.view');
            Route::post('/profile-updates/{update}/decide', [ProfileUpdateController::class, 'decide'])
                ->middleware('permission:profile-updates.decide');

            /*
             * Vouchers (FR-ACC-4). Drafting and approving are separate
             * permissions because approval is the moment a person's typing
             * reaches the ledger - the one control that separation buys.
             *
             * There is no route that edits or deletes an approved voucher.
             * Its entries have been reported on; undoing one means posting a
             * reversal (FR-ACC-6), which is itself dated and attributable.
             */
            Route::get('/vouchers', [VoucherController::class, 'index'])
                ->middleware('permission:vouchers.view');
            Route::get('/vouchers/{voucher}', [VoucherController::class, 'show'])
                ->middleware('permission:vouchers.view');

            Route::post('/vouchers', [VoucherController::class, 'store'])
                ->middleware('permission:vouchers.create');
            Route::put('/vouchers/{voucher}', [VoucherController::class, 'update'])
                ->middleware('permission:vouchers.create');
            Route::delete('/vouchers/{voucher}', [VoucherController::class, 'destroy'])
                ->middleware('permission:vouchers.create');

            Route::post('/vouchers/{voucher}/decide', [VoucherController::class, 'decide'])
                ->middleware('permission:vouchers.approve');
            Route::post('/vouchers/{voucher}/reverse', [VoucherController::class, 'reverse'])
                ->middleware('permission:vouchers.approve');

            /*
             * Shares (FR-SHR-3). A transfer moves shares between two members
             * and posts nothing to the ledger: whatever the buyer paid the
             * seller is between them, and the association took no money.
             */
            Route::get('/shares/transfers', [ShareController::class, 'index'])
                ->middleware('permission:shares.view');
            Route::get('/shares/members/{member}', [ShareController::class, 'show'])
                ->middleware('permission:shares.view');
            Route::post('/shares/transfers', [ShareController::class, 'store'])
                ->middleware('permission:shares.transfer');

            // Reports
            Route::get('/reports/memberwise-paid', [ReportController::class, 'memberwisePaid'])
                ->middleware('permission:reports.paid');
            Route::get('/reports/due-info', [ReportController::class, 'dueInfo'])
                ->middleware('permission:reports.due');

            /*
             * The income statement (P-9). Its own permission, already seeded
             * and until now unrouted: what the association earned and spent is
             * a committee's business, and is not the same audience as the
             * member-by-member reports the office reads daily.
             */
            Route::get('/reports/income-statement', [ReportController::class, 'incomeStatement'])
                ->middleware('permission:reports.income-statement');

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
            Route::get('/reports/income-statement/export', [ReportController::class, 'exportIncomeStatement'])
                ->middleware(['permission:reports.income-statement', 'permission:reports.export']);
            Route::get('/reports/inconsistencies/export', [AuditController::class, 'export'])
                ->middleware(['permission:reports.inconsistency', 'permission:reports.export']);
        });
    });
});
