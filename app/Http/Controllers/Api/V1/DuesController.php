<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Tenant\FeeAssign;
use App\Models\Tenant\FineDate;
use App\Models\Tenant\Member;
use App\Models\Tenant\PaymentInfo;
use App\Models\Tenant\PaymentInfoItem;
use App\Models\Tenant\Setting;
use App\Services\Gateways\GatewayRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What a member owes, and what they have paid.
 *
 * Three response rules apply to everything here, and they are the whole point
 * of the exercise (bcs-docs/05-api-contract.md section 1.6):
 *
 *   1. instalment_amount and fine_amount are ALWAYS separate fields
 *   2. an instalment count is a count of DISTINCT assignments, not of rows
 *   3. the server computes; the app displays
 */
class DuesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $member = $this->member($request);

        $assigns = FeeAssign::query()
            ->with('feeSetup:id,fee_head')
            ->where('member_id', $member->id)
            ->outstanding()
            ->orderBy('period')
            ->get();

        /*
         * TODAY, ASKED ONCE. A member's dues are read while the list is being
         * built, and a date that moved between the first row and the last would
         * put a boundary instalment on both sides of it.
         */
        $today = now()->startOfDay();

        $data = $assigns->map(fn (FeeAssign $assign) => [
            'fee_assign_id' => $assign->id,
            'fee_head' => $assign->feeSetup->fee_head,
            'period' => $assign->period,

            /*
             * WHETHER THIS IS OWED YET, and the reason this field exists.
             *
             * Associations assign instalments well ahead - COCSOL's run to
             * 2027-12, fifteen months out - and `outstanding()` asks only about
             * STATUS. So a member who owed one late instalment was shown
             * "OUTSTANDING 18,100.00" and eighteen rows, of which seventeen
             * were months that have not happened. That is not a dues figure,
             * it is the rest of their membership added up.
             *
             * They are still listed and still payable: paying ahead is normal
             * and the association assigned them deliberately. They are simply
             * not DEBT, and the difference is the whole of what a member wants
             * to know when they open this screen.
             */
            'due' => $assign->assign_date === null
                || $assign->assign_date->startOfDay()->lessThanOrEqualTo($today),

            // Never merged. A single "amount" field would be the legacy bug in
            // a new coat.
            'instalment_amount' => (string) $assign->amount,
            'fine_amount' => (string) $assign->fine_amount,

            // Convenience only, computed here so the app never adds money.
            'total_due' => $assign->totalDue(),

            'status' => $assign->status,
            'overdue_periods' => $this->overduePeriods($assign),
        ]);

        $sum = fn ($rows, string $column) => $rows->reduce(
            fn (string $carry, FeeAssign $a) => bcadd($carry, (string) $a->{$column}, 2),
            '0.00'
        );

        $owed = $assigns->filter(
            fn (FeeAssign $a) => $a->assign_date === null
                || $a->assign_date->startOfDay()->lessThanOrEqualTo($today)
        );
        $ahead = $assigns->reject(fn (FeeAssign $a) => $owed->contains($a));

        $instalmentTotal = $sum($assigns, 'amount');
        $fineTotal = $sum($assigns, 'fine_amount');

        $dueInstalments = $sum($owed, 'amount');
        $dueFines = $sum($owed, 'fine_amount');

        return response()->json([
            'data' => $data,
            'meta' => [
                /*
                 * The totals of everything assigned, unchanged and still first,
                 * because callers already read them and a screen that silently
                 * started meaning something else is worse than one that gains a
                 * field.
                 */
                'instalment_total' => $instalmentTotal,
                'fine_total' => $fineTotal,
                'grand_total' => bcadd($instalmentTotal, $fineTotal, 2),

                // What is actually owed today. This is the figure to put in
                // front of a member; the one above is their whole schedule.
                'due_instalment_total' => $dueInstalments,
                'due_fine_total' => $dueFines,
                'due_grand_total' => bcadd($dueInstalments, $dueFines, 2),
                'due_count' => $owed->count(),

                // And what is assigned but not yet owed, so "pay ahead" can be
                // offered as the deliberate choice it is.
                'scheduled_total' => $sum($ahead, 'amount'),
                'scheduled_count' => $ahead->count(),
            ],
        ]);
    }

    /**
     * Four numbers, never one.
     *
     * The legacy reports collapse these into a single "savings" figure that
     * silently includes fines and counts duplicate rows as instalments.
     */
    public function summary(Request $request): JsonResponse
    {
        $member = $this->member($request);

        $completedItems = PaymentInfoItem::query()
            ->where('payment_status', PaymentInfo::STATUS_COMPLETED)
            ->whereHas('feeAssign', fn ($q) => $q->where('member_id', $member->id));

        // FR-MON-4: DISTINCT assignments, with amount > 0. Counting rows is how
        // the legacy instalment counts became an upper bound rather than a
        // count (defect D-7).
        $instalmentsPaidCount = (clone $completedItems)
            ->where('amount', '>', 0)
            ->distinct('fee_assign_id')
            ->count('fee_assign_id');

        $instalmentsPaidAmount = (clone $completedItems)->sum('amount');
        $finesPaidAmount = (clone $completedItems)->sum('fine_amount');

        $member->loadMissing('associatorInfo');

        return response()->json([
            'data' => [
                'instalments_paid_count' => $instalmentsPaidCount,
                'instalments_paid_amount' => number_format((float) $instalmentsPaidAmount, 2, '.', ''),
                'fines_paid_amount' => number_format((float) $finesPaidAmount, 2, '.', ''),
                'shares' => (int) ($member->associatorInfo?->num_or_shares ?? 0),
            ],
        ]);
    }

    /**
     * What a chosen set of instalments comes to.
     *
     * This endpoint exists so the APP never has to add money up.
     *
     * The member must be told how much to transfer BEFORE the payment is
     * created, and the only figure the client could otherwise produce is a
     * client-side sum - which is precisely the arithmetic this platform forbids
     * (FR-MON-6). Summing on the phone would also have meant parsing decimal
     * strings into floats, and floats do not reconcile.
     *
     * Ownership is checked here as everywhere else: a member may only quote
     * their own instalments (FR-RBAC-4).
     */
    public function quote(Request $request): JsonResponse
    {
        $member = $this->member($request);

        $validated = $request->validate([
            'fee_assign_ids' => ['required', 'array', 'min:1'],
            'fee_assign_ids.*' => ['required', 'integer'],
        ]);

        $assigns = FeeAssign::query()
            ->whereIn('id', $validated['fee_assign_ids'])
            ->where('member_id', $member->id)
            ->get();

        if ($assigns->count() !== count(array_unique($validated['fee_assign_ids']))) {
            throw ApiException::notOwner('instalment');
        }

        $instalmentTotal = $assigns->reduce(
            fn (string $carry, FeeAssign $a) => bcadd($carry, (string) $a->amount, 2),
            '0.00'
        );
        $fineTotal = $assigns->reduce(
            fn (string $carry, FeeAssign $a) => bcadd($carry, (string) $a->fine_amount, 2),
            '0.00'
        );

        return response()->json([
            'data' => [
                'instalment_count' => $assigns->count(),

                // Separate, as everywhere else. The app displays all three and
                // computes none of them.
                'instalment_total' => $instalmentTotal,
                'fine_total' => $fineTotal,
                'grand_total' => bcadd($instalmentTotal, $fineTotal, 2),
            ],
        ]);
    }

    /**
     * What a member should be told they are paying through.
     *
     * The bank's name, not ours and not the middleware's: a member deciding
     * whether to trust a payment page recognises "Sonali", and has never heard
     * of PayFlex. Which route we take to reach the bank is our business.
     *
     * @var array<string, string>
     */
    private const PROVIDER_LABELS = [
        'spg' => 'Sonali Payment Gateway',
        'payflex_spg' => 'Sonali Payment Gateway',
    ];

    /**
     * How the member is meant to pay.
     *
     * Without this the manual flow is incomplete: the app tells a member to
     * transfer money and upload a slip, and never says WHICH account. The
     * details are per association because each holds its own (A-1).
     *
     * `online_enabled` tells the app whether to offer a "Pay now" button at
     * all, so a member is never shown a route that is not configured.
     */
    public function instructions(GatewayRegistry $gateways): JsonResponse
    {
        $provider = $gateways->activeProvider();

        $offlineOpen = (bool) Setting::get(Setting::MEMBER_OFFLINE_PAYMENT_ENABLED);

        $bank = [
            'account_name' => (string) Setting::get(Setting::BANK_ACCOUNT_NAME),
            'account_number' => (string) Setting::get(Setting::BANK_ACCOUNT_NUMBER),
            'bank_name' => (string) Setting::get(Setting::BANK_NAME),
            'branch' => (string) Setting::get(Setting::BANK_BRANCH),
            'routing_number' => (string) Setting::get(Setting::BANK_ROUTING_NUMBER),
            'instructions' => (string) Setting::get(Setting::BANK_INSTRUCTIONS),
        ];

        return response()->json([
            'data' => [
                /*
                 * TWO SEPARATE QUESTIONS, and they used to be one.
                 *
                 * `bank.account_number` says the association CAN receive a
                 * transfer. `payment.member_offline_enabled` says it is willing
                 * to accept one a MEMBER files themselves - a record created by
                 * the person who benefits from it, which somebody then has to
                 * check against a photographed slip. An association whose
                 * members pay at the counter has the first and wants nothing to
                 * do with the second, and before this switch existed it had no
                 * way to say so.
                 *
                 * `reason` because the two closures need different words on
                 * screen: "the office has not published its account details"
                 * sends a member to ask for them, and "this association does
                 * not take offline payments" does not.
                 */
                'manual' => [
                    'available' => $offlineOpen && $bank['account_number'] !== '',
                    'reason' => match (true) {
                        ! $offlineOpen => 'disabled',
                        $bank['account_number'] === '' => 'no_bank_details',
                        default => null,
                    },
                    'bank' => $bank,
                ],
                /*
                 * THREE THINGS, ALL REQUIRED, and the switch is only one of
                 * them. It used to be the only one, which meant an association
                 * could turn online payment on with no gateway configured and
                 * every member would be offered a "Pay now" button whose one
                 * outcome is a refusal three screens later.
                 *
                 * `provider` is read rather than hardcoded: there are now two
                 * routes to Sonali, and telling the app the wrong one would put
                 * the wrong name in front of a member at the moment they are
                 * deciding whether to trust the payment.
                 */
                'online' => [
                    'available' => (bool) Setting::get(Setting::ONLINE_PAYMENT_ENABLED)
                        && $provider !== null
                        && config('services.gateway.driver') !== 'fake',
                    'provider' => $provider ?? 'none',
                    'label' => self::PROVIDER_LABELS[$provider] ?? 'Online payment',
                ],
            ],
        ]);
    }

    /**
     * How many fine periods have been APPLIED to this assignment.
     *
     * Deliberately counts what accrual actually did - `complete` fine dates -
     * rather than what the wall clock says should have happened by now.
     *
     * The two are not the same, and the difference is visible to members. This
     * count and `fine_amount` travel in the same object, so if they are read
     * from different clocks they contradict each other: an instalment renders as
     * "1 month late" while showing no fine at all. That gap opens every night
     * between a fine date passing and `fines:accrue` running, and stays open
     * indefinitely if the job fails - which is exactly when a member is most
     * likely to be looking.
     *
     * Counting applied dates makes the badge and the money consistent by
     * construction: both are then reports of the same accrued state, and the
     * fine-date series stays the single source of truth (FR-FINE-3).
     */
    private function overduePeriods(FeeAssign $assign): int
    {
        return $assign->fineDates()
            ->where('status', FineDate::STATUS_COMPLETE)
            ->count();
    }

    /**
     * The member is ALWAYS the authenticated one. Never a request field.
     *
     * This is defect D-5's fix at the entry point: the legacy controller takes
     * member_id from the request body and validates only that it exists.
     */
    /**
     * The member's own account, period by period (FR-REP-5, for one person).
     *
     * WHAT THE PORTAL HAD NO ANSWER TO. A member could see what they owe now
     * and a list of receipts, and nothing that put the two together. "Did I pay
     * March?" meant scrolling a stack of invoice cards and reading dates; "what
     * did I pay in fines last year" had no answer at all short of arithmetic on
     * a phone. The office has had a member-wise report since the legacy; the
     * member has never had their own.
     *
     * ONE ROW PER ASSIGNMENT, which is the unit the association charges in -
     * not per payment, because one payment settles several months and a row per
     * payment cannot show the month that is missing. The missing months are the
     * point of a statement.
     *
     * EVERY FIGURE COMPUTED HERE. The app renders this table and adds nothing
     * up, including the column totals: a statement is the one screen a member
     * might take to the office to argue with, so the numbers on it have to be
     * the association's own.
     */
    public function statement(Request $request): JsonResponse
    {
        $member = $this->member($request);

        $assigns = FeeAssign::query()
            ->with([
                'feeSetup:id,fee_head',

                /*
                 * The settling item, and only a completed one. An assignment
                 * with a pending payment against it is NOT paid, and showing
                 * that payment's date in a "Paid on" column would tell a member
                 * a thing the association has not yet agreed to.
                 */
                'items' => fn ($q) => $q
                    ->where('payment_status', PaymentInfo::STATUS_COMPLETED)
                    ->with('payment:id,invoice_no,payment_date'),
            ])
            ->where('member_id', $member->id)
            ->orderByDesc('period')
            ->get();

        $today = now()->startOfDay();

        $rows = $assigns->map(function (FeeAssign $assign) use ($today) {
            $item = $assign->items->first();

            return [
                'fee_assign_id' => $assign->id,
                'period' => $assign->period,
                'fee_head' => $assign->feeSetup->fee_head,

                // Charged, always apart.
                'instalment_amount' => (string) $assign->amount,
                'fine_amount' => (string) $assign->fine_amount,
                'total_amount' => $assign->totalDue(),

                'status' => $assign->status,

                // Only when the association has actually accepted the money.
                // `toDateString`, as every other endpoint sends it. A raw cast
                // here would hand the app an ISO timestamp for a column that is
                // a date everywhere else it appears.
                'paid_on' => $item?->payment?->payment_date?->toDateString(),
                'invoice_no' => $item?->payment?->invoice_no,
                'payment_id' => $item?->payment?->id,

                'due' => $assign->assign_date === null
                    || $assign->assign_date->startOfDay()->lessThanOrEqualTo($today),
            ];
        });

        $sum = fn ($rows, string $column) => $rows->reduce(
            fn (string $carry, FeeAssign $a) => bcadd($carry, (string) $a->{$column}, 2),
            '0.00'
        );

        $paid = $assigns->filter(fn (FeeAssign $a) => $a->status === FeeAssign::STATUS_PAID);

        return response()->json([
            'data' => $rows,
            'meta' => [
                'periods' => $assigns->count(),
                'paid_periods' => $paid->count(),

                // Column totals for the table foot, and the same separation
                // everywhere else keeps.
                'instalment_total' => $sum($assigns, 'amount'),
                'fine_total' => $sum($assigns, 'fine_amount'),
                'paid_instalment_total' => $sum($paid, 'amount'),
                'paid_fine_total' => $sum($paid, 'fine_amount'),
            ],
        ]);
    }

    private function member(Request $request): Member
    {
        $account = $request->user();

        abort_unless($account instanceof Member, 403);

        return $account;
    }
}
