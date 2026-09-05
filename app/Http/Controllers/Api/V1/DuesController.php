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

        $data = $assigns->map(fn (FeeAssign $assign) => [
            'fee_assign_id' => $assign->id,
            'fee_head' => $assign->feeSetup->fee_head,
            'period' => $assign->period,

            // Never merged. A single "amount" field would be the legacy bug in
            // a new coat.
            'instalment_amount' => (string) $assign->amount,
            'fine_amount' => (string) $assign->fine_amount,

            // Convenience only, computed here so the app never adds money.
            'total_due' => $assign->totalDue(),

            'status' => $assign->status,
            'overdue_periods' => $this->overduePeriods($assign),
        ]);

        $instalmentTotal = $assigns->reduce(
            fn (string $carry, FeeAssign $a) => bcadd($carry, (string) $a->amount, 2),
            '0.00'
        );
        $fineTotal = $assigns->reduce(
            fn (string $carry, FeeAssign $a) => bcadd($carry, (string) $a->fine_amount, 2),
            '0.00'
        );

        return response()->json([
            'data' => $data,
            'meta' => [
                'instalment_total' => $instalmentTotal,
                'fine_total' => $fineTotal,
                'grand_total' => bcadd($instalmentTotal, $fineTotal, 2),
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
                'manual' => [
                    // False when the association has not filled the details in.
                    // The app must then say "contact the office" rather than
                    // render an empty card that looks like a loading failure.
                    'available' => $bank['account_number'] !== '',
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
    private function member(Request $request): Member
    {
        $account = $request->user();

        abort_unless($account instanceof Member, 403);

        return $account;
    }
}
