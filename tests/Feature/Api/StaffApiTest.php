<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Tenant\FeeAssign;
use App\Models\Tenant\AssociatorInfo;
use App\Models\Tenant\Member;
use App\Models\Tenant\MemberShareBalance;
use App\Models\Tenant\PaymentInfo;
use App\Models\User;
use App\Services\FeeAssignService;
use App\Services\FineService;
use App\Services\PaymentService;
use App\Services\TenantSeedService;
use Carbon\CarbonImmutable;
use Tests\Support\TenantFixtures;
use Tests\TenantTestCase;

/**
 * The staff API surface.
 *
 * The batch-approval tests here are the ones that matter most: defect D-3 is a
 * batch of two payments crashing outright, and it survived for years because a
 * batch of ONE works fine.
 */
class StaffApiTest extends TenantTestCase
{
    use TenantFixtures;

    private function headers(?string $token = null): array
    {
        return array_filter([
            'X-Tenant' => $this->slug(),
            'Accept' => 'application/json',
            'Authorization' => $token ? "Bearer {$token}" : null,
        ]);
    }

    private function staffToken(string $role = 'superadmin'): string
    {
        return $this->inTenant(function () use ($role) {
            app(TenantSeedService::class)->seedAll();

            static $sequence = 0;
            $sequence++;

            $user = User::create([
                'name' => "Staff {$sequence}",
                'email' => "staff{$sequence}@assoc.test",
                'password' => 'secret-password',
            ]);
            $user->assignRole($role);

            return $user->createToken('test', $user->getAllPermissions()->pluck('name')->all())
                ->plainTextToken;
        });
    }

    // ---- the staff/member boundary --------------------------------------

    public function test_a_member_token_cannot_reach_a_staff_endpoint(): void
    {
        $memberToken = $this->inTenant(function () {
            app(TenantSeedService::class)->seedAll();
            $member = $this->makeMember(['password' => 'x']);

            // Even if the member's token somehow carried a staff ability, the
            // `staff` middleware rejects a non-staff account outright.
            return $member->createToken('test', ['members.view'])->plainTextToken;
        });

        $this->withHeaders($this->headers($memberToken))
            ->getJson('/api/v1/staff/members')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'INSUFFICIENT_PERMISSION');
    }

    /**
     * The live permission check, not the token snapshot. An operator holds
     * dashboard and shares only.
     */
    public function test_an_operator_cannot_approve_payments(): void
    {
        $token = $this->staffToken('operator');

        $this->withHeaders($this->headers($token))
            ->postJson('/api/v1/staff/payments/decide', [
                'payment_ids' => [1],
                'decision' => 'completed',
            ])
            ->assertStatus(403);
    }

    public function test_an_operator_can_reach_the_dashboard(): void
    {
        $token = $this->staffToken('operator');

        $this->withHeaders($this->headers($token))
            ->getJson('/api/v1/staff/dashboard')
            ->assertOk()
            ->assertJsonStructure(['data' => ['members', 'collections', 'outstanding']]);
    }

    // ---- members ---------------------------------------------------------

    public function test_staff_can_register_a_member_who_starts_inactive(): void
    {
        $token = $this->staffToken();

        $this->withHeaders($this->headers($token))
            ->postJson('/api/v1/staff/members', [
                'name' => 'New Member',
                'mobile' => '01799999999',
            ])
            ->assertStatus(201)
            // Creating a member and approving them are separate decisions;
            // collapsing them would remove the approval record.
            ->assertJsonPath('data.status', Member::STATUS_INACTIVE);
    }

    public function test_approving_a_member_records_who_did_it(): void
    {
        $token = $this->staffToken();

        $memberId = $this->withHeaders($this->headers($token))
            ->postJson('/api/v1/staff/members', ['name' => 'Pending', 'mobile' => '01788888888'])
            ->json('data.id');

        $this->withHeaders($this->headers($token))
            ->postJson("/api/v1/staff/members/{$memberId}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', Member::STATUS_ACTIVE);

        $this->inTenant(function () use ($memberId) {
            $this->assertDatabaseHas('audit_logs', [
                'subject_id' => $memberId,
                'action' => 'member.approved',
            ]);
        });
    }

    public function test_suspending_a_member_requires_a_reason(): void
    {
        $token = $this->staffToken();
        $memberId = $this->inTenant(fn () => $this->makeMember()->id);

        $this->withHeaders($this->headers($token))
            ->postJson("/api/v1/staff/members/{$memberId}/suspend", [])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    /**
     * The association's own practice, carried over deliberately.
     *
     * A member exists before anyone knows their membership number. The legacy
     * registration form has no field for it; the number is typed later on a
     * screen labelled "Office use only". So creation must produce a society
     * record with no number, and that must not be an error state.
     */
    public function test_a_new_member_gets_a_society_record_with_no_number_yet(): void
    {
        $token = $this->staffToken();

        $memberId = $this->withHeaders($this->headers($token))
            ->postJson('/api/v1/staff/members', ['name' => 'Unnumbered', 'mobile' => '01766666666'])
            ->assertStatus(201)
            ->json('data.id');

        $this->inTenant(function () use ($memberId) {
            $info = AssociatorInfo::where('member_id', $memberId)->first();

            self::assertNotNull($info, 'A member must have a society record from the moment they exist.');
            self::assertNull($info->membership_no, 'The number is assigned by the office, later.');
        });
    }

    public function test_the_office_assigns_the_membership_number_afterwards(): void
    {
        $token = $this->staffToken();

        $memberId = $this->withHeaders($this->headers($token))
            ->postJson('/api/v1/staff/members', ['name' => 'To Number', 'mobile' => '01755555511'])
            ->json('data.id');

        // Zero-padded numeric, matching the association's register - 315 live
        // numbers running 01..317, no prefix.
        $this->withHeaders($this->headers($token))
            ->putJson("/api/v1/staff/members/{$memberId}/associator-info", [
                'membership_no' => '318',
                'join_date' => '2026-03-15',
            ])
            ->assertOk()
            ->assertJsonPath('data.membership_no', '318')
            ->assertJsonPath('data.join_date', '2026-03-15');

        $this->inTenant(function () use ($memberId) {
            $this->assertDatabaseHas('audit_logs', [
                'subject_id' => $memberId,
                'action' => 'member.associator_info_assigned',
            ]);
        });
    }

    /**
     * FR-MEM-3: unique within the association.
     *
     * More than the legacy schema enforced - there `membershp_number` was a
     * plain nullable string with no index, and uniqueness held only because
     * staff were careful. They were, across 315 rows, but nothing made them.
     */
    public function test_a_membership_number_cannot_be_reused(): void
    {
        $token = $this->staffToken();

        $first = $this->withHeaders($this->headers($token))
            ->postJson('/api/v1/staff/members', ['name' => 'First', 'mobile' => '01755555522'])
            ->json('data.id');
        $second = $this->withHeaders($this->headers($token))
            ->postJson('/api/v1/staff/members', ['name' => 'Second', 'mobile' => '01755555533'])
            ->json('data.id');

        $this->withHeaders($this->headers($token))
            ->putJson("/api/v1/staff/members/{$first}/associator-info", ['membership_no' => '400'])
            ->assertOk();

        $this->withHeaders($this->headers($token))
            ->putJson("/api/v1/staff/members/{$second}/associator-info", ['membership_no' => '400'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    /** Correcting a member's own number must not collide with itself. */
    public function test_reassigning_the_same_number_to_the_same_member_is_allowed(): void
    {
        $token = $this->staffToken();

        $memberId = $this->withHeaders($this->headers($token))
            ->postJson('/api/v1/staff/members', ['name' => 'Same', 'mobile' => '01755555544'])
            ->json('data.id');

        $this->withHeaders($this->headers($token))
            ->putJson("/api/v1/staff/members/{$memberId}/associator-info", ['membership_no' => '401'])
            ->assertOk();

        $this->withHeaders($this->headers($token))
            ->putJson("/api/v1/staff/members/{$memberId}/associator-info", [
                'membership_no' => '401',
                'designation' => 'Deputy Secretary',
            ])
            ->assertOk()
            ->assertJsonPath('data.membership_no', '401');
    }

    /**
     * Share count is derived, not typed.
     *
     * `num_or_shares` is a denormalised total maintained by ShareService from
     * share payments. Accepting it here would let the figure staff read drift
     * permanently from the ledger it comes from - which is precisely why the
     * legacy system's two sources disagree.
     */
    public function test_the_share_count_cannot_be_set_by_hand(): void
    {
        $token = $this->staffToken();

        $memberId = $this->withHeaders($this->headers($token))
            ->postJson('/api/v1/staff/members', ['name' => 'Shares', 'mobile' => '01755555555'])
            ->json('data.id');

        $this->withHeaders($this->headers($token))
            ->putJson("/api/v1/staff/members/{$memberId}/associator-info", [
                'membership_no' => '402',
                'num_or_shares' => 9999,
            ])
            ->assertOk();

        $this->inTenant(function () use ($memberId) {
            self::assertSame(
                0,
                (int) AssociatorInfo::where('member_id', $memberId)->value('num_or_shares'),
                'Shares must come from share payments, never from this endpoint.',
            );
        });
    }

    // ---- chart of accounts -----------------------------------------------

    /**
     * A fee head names two ledgers, so the ledgers have to be listable.
     *
     * Without this endpoint the fee setup screen cannot exist - it would have to
     * ask staff to type account ids from memory.
     */
    public function test_the_chart_of_accounts_can_be_listed_for_choosing(): void
    {
        $token = $this->staffToken();

        $response = $this->withHeaders($this->headers($token))
            ->getJson('/api/v1/staff/ledgers')
            ->assertOk();

        // Group and category travel with each ledger: several account names read
        // alike, and "Subscription Income - Income" is what disambiguates them.
        $response->assertJsonStructure([
            'data' => [['id', 'name', 'group', 'category', 'type']],
        ]);

        $names = collect($response->json('data'))->pluck('name');
        self::assertTrue($names->contains('Subscription Income'));
        self::assertTrue($names->contains('Fine Income'));
    }

    public function test_the_chart_can_be_narrowed_to_income_accounts(): void
    {
        $token = $this->staffToken();

        $types = collect(
            $this->withHeaders($this->headers($token))
                ->getJson('/api/v1/staff/ledgers?type=income')
                ->assertOk()
                ->json('data')
        )->pluck('type')->unique();

        self::assertSame(['income'], $types->values()->all());
    }

    // ---- fee heads -------------------------------------------------------

    /**
     * FR-FEE-2. The legacy system stamps the fine ledger silently from config;
     * here it is mandatory, staff-chosen, and must differ from the instalment
     * ledger - otherwise fines and instalments land in one account and the
     * separation is lost at the accounting layer.
     */
    public function test_a_fee_head_requires_a_distinct_fine_ledger(): void
    {
        $token = $this->staffToken();
        $ledgers = $this->inTenant(fn () => $this->makeLedgers());

        $this->withHeaders($this->headers($token))
            ->postJson('/api/v1/staff/fee-setups', [
                'fee_head' => 'Monthly Subscription',
                'monthly' => true,
                'amount' => '1000.00',
                'ledger_id' => $ledgers['income']->id,
                'fine_ledger_id' => $ledgers['income']->id,   // same - refused
            ])
            ->assertStatus(422);

        $this->withHeaders($this->headers($token))
            ->postJson('/api/v1/staff/fee-setups', [
                'fee_head' => 'Monthly Subscription',
                'monthly' => true,
                'amount' => '1000.00',
                'ledger_id' => $ledgers['income']->id,
                'fine_ledger_id' => $ledgers['fine']->id,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.fine_ledger.name', 'Fine Income');
    }

    /**
     * FR-FEE-8: staff must be told what was skipped. Silence about 2 of 4
     * skipped reads as "all 4 created".
     */
    public function test_bulk_assignment_reports_created_and_skipped(): void
    {
        $token = $this->staffToken();

        [$memberIds, $feeSetupId] = $this->inTenant(function () {
            $setup = $this->makeFeeSetup();

            return [
                [$this->makeMember()->id, $this->makeMember()->id],
                $setup->id,
            ];
        });

        $payload = [
            'fee_setup_id' => $feeSetupId,
            'member_ids' => $memberIds,
            'periods' => ['2026-01', '2026-02'],
        ];

        $this->withHeaders($this->headers($token))
            ->postJson('/api/v1/staff/fee-assigns', $payload)
            ->assertStatus(201)
            ->assertJsonPath('data.created', 4)
            ->assertJsonPath('data.skipped_duplicate', 0);

        // Re-running the identical assignment must skip, not duplicate.
        $this->withHeaders($this->headers($token))
            ->postJson('/api/v1/staff/fee-assigns', $payload)
            ->assertStatus(201)
            ->assertJsonPath('data.created', 0)
            ->assertJsonPath('data.skipped_duplicate', 4);

        $this->inTenant(fn () => $this->assertSame(4, FeeAssign::count()));
    }

    // ---- payment approval ------------------------------------------------

    /**
     * T-5 / defect D-3, over HTTP.
     *
     * The legacy controller crashes from the SECOND payment of a batch onwards
     * when SMS is enabled, and the TypeError escapes its catch(\Exception), so
     * the request 500s and the whole transaction rolls back. A batch of one
     * works, which is why nobody noticed.
     */
    public function test_a_batch_of_two_payments_is_approved(): void
    {
        $token = $this->staffToken();

        $paymentIds = $this->inTenant(function () {
            $this->seedSettings();
            $ledgers = $this->makeLedgers();
            $setup = $this->makeFeeSetup([
                'ledger_id' => $ledgers['income']->id,
                'fine_ledger_id' => $ledgers['fine']->id,
            ]);

            $ids = [];

            foreach ([1, 2] as $i) {
                $member = $this->makeMember();
                $assign = app(FeeAssignService::class)->assign($member->id, $setup, '2026-0'.$i);
                $ids[] = app(PaymentService::class)
                    ->create($member->id, [$assign->id], ledgerId: $ledgers['cash']->id)->id;
            }

            return $ids;
        });

        $this->withHeaders($this->headers($token))
            ->postJson('/api/v1/staff/payments/decide', [
                'payment_ids' => $paymentIds,
                'decision' => 'completed',
            ])
            ->assertOk()
            ->assertJsonPath('data.decided', 2)
            ->assertJsonPath('data.failed', 0);

        $this->inTenant(function () use ($paymentIds) {
            foreach ($paymentIds as $id) {
                $this->assertSame(PaymentInfo::STATUS_COMPLETED, PaymentInfo::find($id)->status);
            }
        });
    }

    /**
     * One bad payment in a batch must not discard the good ones - the legacy
     * failure mode is the whole transaction rolling back.
     */
    public function test_a_partial_batch_reports_per_payment_outcomes(): void
    {
        $token = $this->staffToken();

        $goodId = $this->inTenant(function () {
            $this->seedSettings();
            $ledgers = $this->makeLedgers();
            $setup = $this->makeFeeSetup([
                'ledger_id' => $ledgers['income']->id,
                'fine_ledger_id' => $ledgers['fine']->id,
            ]);
            $member = $this->makeMember();
            $assign = app(FeeAssignService::class)->assign($member->id, $setup, '2026-01');

            return app(PaymentService::class)
                ->create($member->id, [$assign->id], ledgerId: $ledgers['cash']->id)->id;
        });

        $response = $this->withHeaders($this->headers($token))
            ->postJson('/api/v1/staff/payments/decide', [
                'payment_ids' => [$goodId, 999999],
                'decision' => 'completed',
            ])
            ->assertStatus(207);

        $this->assertSame(1, $response->json('data.decided'));
        $this->assertSame(1, $response->json('data.failed'));

        // The good one still completed.
        $this->inTenant(fn () => $this->assertSame(
            PaymentInfo::STATUS_COMPLETED,
            PaymentInfo::find($goodId)->status
        ));
    }

    // ---- payments that are with a bank -----------------------------------

    /**
     * A pending payment and a pending payment AT A GATEWAY, side by side.
     *
     * @return array{manual: int, online: int}
     */
    private function twoPendingPayments(): array
    {
        return $this->inTenant(function () {
            $this->seedSettings();
            $ledgers = $this->makeLedgers();
            $setup = $this->makeFeeSetup([
                'ledger_id' => $ledgers['income']->id,
                'fine_ledger_id' => $ledgers['fine']->id,
            ]);

            $ids = [];

            foreach (['manual', 'online'] as $i => $kind) {
                $member = $this->makeMember();
                $assign = app(FeeAssignService::class)->assign($member->id, $setup, '2026-0'.($i + 1));
                $ids[$kind] = app(PaymentService::class)
                    ->create($member->id, [$assign->id], ledgerId: $ledgers['cash']->id)->id;
            }

            // What startSession() stamps before the member leaves for the bank.
            PaymentInfo::whereKey($ids['online'])->update([
                'gateway_reference' => 'SPG-REF-0001',
                'payment_type' => PaymentInfo::TYPE_ONLINE,
            ]);

            return $ids;
        });
    }

    /**
     * THE QUEUE IS FOR MONEY A HUMAN CONFIRMED ARRIVING.
     *
     * A payment with a gateway_reference has been handed to a bank and its
     * outcome is the bank's to report (ADR-0007): the callback completes it,
     * `payments:reconcile` asks every ten minutes in case that callback is
     * lost, and `payments:expire-intents` releases it if it never resolves.
     *
     * It used to appear here anyway - the queue selected on status alone - so a
     * clerk working quickly could approve a payment while the member was still
     * on the bank's page. That marks the dues paid and posts the ledger for
     * money that may never have been taken.
     */
    public function test_a_payment_at_a_gateway_is_not_offered_for_approval(): void
    {
        $token = $this->staffToken();
        $ids = $this->twoPendingPayments();

        $listed = $this->withHeaders($this->headers($token))
            ->getJson('/api/v1/staff/payments/pending')
            ->assertOk()
            ->json('data.*.id');

        $this->assertContains($ids['manual'], $listed);
        $this->assertNotContains($ids['online'], $listed);
    }

    /**
     * The list hides it; this refuses it. An id can be posted without the
     * screen - a stale tab, an export worked from, a script.
     *
     * The batch containment still holds: the manual payment beside it completes.
     */
    public function test_a_payment_at_a_gateway_cannot_be_approved_by_hand(): void
    {
        $token = $this->staffToken();
        $ids = $this->twoPendingPayments();

        $response = $this->withHeaders($this->headers($token))
            ->postJson('/api/v1/staff/payments/decide', [
                'payment_ids' => [$ids['manual'], $ids['online']],
                'decision' => 'completed',
            ])
            ->assertStatus(207);

        $this->assertSame(1, $response->json('data.decided'));
        $this->assertSame(1, $response->json('data.failed'));

        // Said in terms of what happens next, not just refused.
        $this->assertStringContainsString('with the bank', (string) $response->json('data.results.1.error'));

        $this->inTenant(function () use ($ids) {
            $this->assertSame(PaymentInfo::STATUS_COMPLETED, PaymentInfo::find($ids['manual'])->status);
            $this->assertSame(PaymentInfo::STATUS_PENDING, PaymentInfo::find($ids['online'])->status);
        });
    }

    /**
     * Suspending is refused too, and for the same reason in reverse: released
     * here while the bank is still processing, the callback would complete it
     * afterwards out of a status nobody expected it to leave.
     */
    public function test_a_payment_at_a_gateway_cannot_be_suspended_by_hand(): void
    {
        $token = $this->staffToken();
        $ids = $this->twoPendingPayments();

        $this->withHeaders($this->headers($token))
            ->postJson('/api/v1/staff/payments/decide', [
                'payment_ids' => [$ids['online']],
                'decision' => 'suspended',
                'reason' => 'Looks stuck.',
            ])
            ->assertStatus(207)
            ->assertJsonPath('data.decided', 0);

        $this->inTenant(fn () => $this->assertSame(
            PaymentInfo::STATUS_PENDING,
            PaymentInfo::find($ids['online'])->status
        ));
    }

    /** The dashboard figure sends somebody to that queue, so it counts the same rows. */
    public function test_the_dashboard_counts_only_what_the_queue_holds(): void
    {
        $token = $this->staffToken();
        $this->twoPendingPayments();

        $this->withHeaders($this->headers($token))
            ->getJson('/api/v1/staff/dashboard')
            ->assertOk()
            ->assertJsonPath('data.payments_pending_approval', 1);
    }

    // ---- reports ---------------------------------------------------------

    /**
     * FR-REP-3 and FR-REP-4 together. This report is the legacy one that shows
     * inflated savings, because it sums payable_amount - which on online
     * payments includes the fine (D-1) - and counts rows rather than distinct
     * assignments (D-7).
     */
    public function test_the_memberwise_paid_report_separates_instalments_from_fines(): void
    {
        $token = $this->staffToken();

        $this->inTenant(function () {
            $this->seedSettings();
            $ledgers = $this->makeLedgers();
            $setup = $this->makeFeeSetup([
                'ledger_id' => $ledgers['income']->id,
                'fine_ledger_id' => $ledgers['fine']->id,
            ]);

            $member = $this->makeMember();
            app(FeeAssignService::class)->assign($member->id, $setup, '2026-01');
            app(FineService::class)->accrueAll(CarbonImmutable::create(2026, 2, 10));

            $assign = FeeAssign::where('member_id', $member->id)->firstOrFail();
            $payment = app(PaymentService::class)
                ->create($member->id, [$assign->id], ledgerId: $ledgers['cash']->id);
            app(PaymentService::class)->complete($payment);
        });

        $this->withHeaders($this->headers($token))
            ->getJson('/api/v1/staff/reports/memberwise-paid')
            ->assertOk()
            ->assertJsonPath('data.0.instalments_paid_count', 1)
            ->assertJsonPath('data.0.instalments_paid_amount', '1000.00')
            ->assertJsonPath('data.0.fines_paid_amount', '200.00')
            ->assertJsonPath('data.0.total_paid', '1200.00')
            // FR-REP-8: column totals for every summable column.
            ->assertJsonPath('meta.instalments_paid_amount', '1000.00')
            ->assertJsonPath('meta.fines_paid_amount', '200.00');
    }

    /**
     * Instalments that ARRIVED BY TRANSFER are reported, and reported apart.
     *
     * The legacy report folds them into the member's paid total, which states
     * that somebody paid money the association never received. Both halves of
     * this matter and they pull in opposite directions: leaving transfers out
     * entirely loses the fact that a member is holding instalments at all
     * (which is what the rewrite did until now), and adding them in overstates
     * collections. So: reported, in their own column, never inside total_paid.
     *
     * The buyer here has no payments of their own, which is the case a report
     * built from payment rows cannot see without being asked to.
     */
    public function test_the_paid_report_reports_transferred_instalments_outside_the_paid_total(): void
    {
        $token = $this->staffToken();

        [$seller, $buyer, $setup] = $this->inTenant(function () {
            $this->seedSettings();
            $setup = $this->makeFeeSetup(['is_share' => true, 'amount' => '1000.00']);

            $seller = $this->makeMember(['name' => 'Aaa Seller']);
            $seller->associatorInfo()->create(['membership_no' => '801', 'num_or_shares' => 5]);

            $buyer = $this->makeMember(['name' => 'Bbb Buyer']);
            $buyer->associatorInfo()->create(['membership_no' => '802', 'num_or_shares' => 0]);

            MemberShareBalance::create([
                'member_id' => $seller->id,
                'fee_setup_id' => $setup->id,
                'shares' => 5,
            ]);

            return [$seller, $buyer, $setup];
        });

        $this->postJson('/api/v1/staff/shares/transfers', [
            'seller_id' => $seller->id,
            'transfers' => [[
                'buyer_id' => $buyer->id,
                'fee_setup_id' => $setup->id,
                'shares' => 3,
            ]],
            'transferred_on' => '2026-03-15',
        ], $this->headers($token))->assertCreated();

        $this->withHeaders($this->headers($token))
            ->getJson('/api/v1/staff/reports/memberwise-paid')
            ->assertOk()
            // The buyer is the only member on the report: the seller neither
            // paid nor received anything.
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.member_name', 'Bbb Buyer')
            ->assertJsonPath('data.0.transfers_in_count', 3)
            ->assertJsonPath('data.0.transfers_in_amount', '3000.00')

            // Not one paisa of it reaches the paid figures.
            ->assertJsonPath('data.0.instalments_paid_amount', '0.00')
            ->assertJsonPath('data.0.total_paid', '0.00')
            ->assertJsonPath('meta.transfers_in_amount', '3000.00')
            ->assertJsonPath('meta.total_paid', '0.00');
    }

    /**
     * The date range applies to transfers as well.
     *
     * The legacy column ignores it, so a report for March shows a transfer made
     * in some other year beside figures that do not include it. Two questions
     * answered on one row, with nothing saying which is which.
     */
    public function test_a_transfer_outside_the_range_is_left_out_of_the_paid_report(): void
    {
        $token = $this->staffToken();

        [$seller, $buyer, $setup] = $this->inTenant(function () {
            $this->seedSettings();
            $setup = $this->makeFeeSetup(['is_share' => true, 'amount' => '1000.00']);

            $seller = $this->makeMember(['name' => 'Ccc Seller']);
            $seller->associatorInfo()->create(['membership_no' => '803', 'num_or_shares' => 5]);

            $buyer = $this->makeMember(['name' => 'Ddd Buyer']);
            $buyer->associatorInfo()->create(['membership_no' => '804', 'num_or_shares' => 0]);

            MemberShareBalance::create([
                'member_id' => $seller->id,
                'fee_setup_id' => $setup->id,
                'shares' => 5,
            ]);

            return [$seller, $buyer, $setup];
        });

        $this->postJson('/api/v1/staff/shares/transfers', [
            'seller_id' => $seller->id,
            'transfers' => [[
                'buyer_id' => $buyer->id,
                'fee_setup_id' => $setup->id,
                'shares' => 2,
            ]],
            'transferred_on' => '2026-03-15',
        ], $this->headers($token))->assertCreated();

        $this->withHeaders($this->headers($token))
            ->getJson('/api/v1/staff/reports/memberwise-paid?from=2026-05-01&to=2026-05-31')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.transfers_in_amount', '0.00');
    }

    public function test_the_due_report_separates_instalments_from_fines(): void
    {
        $token = $this->staffToken();

        $this->inTenant(function () {
            $this->seedSettings();
            $setup = $this->makeFeeSetup();
            $member = $this->makeMember();
            app(FeeAssignService::class)->assign($member->id, $setup, '2026-01');
            app(FineService::class)->accrueAll(CarbonImmutable::create(2026, 2, 10));
        });

        $this->withHeaders($this->headers($token))
            ->getJson('/api/v1/staff/reports/due-info?as_of=2026-02-10')
            ->assertOk()
            ->assertJsonPath('data.0.instalments_due', '1000.00')
            ->assertJsonPath('data.0.fines_due', '200.00')
            ->assertJsonPath('data.0.total_due', '1200.00')
            ->assertJsonPath('meta.total_due', '1200.00');
    }

    public function test_the_dashboard_reports_collections_apart_from_fines(): void
    {
        $token = $this->staffToken();

        $this->withHeaders($this->headers($token))
            ->getJson('/api/v1/staff/dashboard')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'members' => ['active', 'inactive', 'suspended'],
                    'collections' => ['instalments', 'fines'],
                    'outstanding' => ['instalments', 'fines'],
                    'payments_pending_approval',
                ],
            ]);
    }
}
