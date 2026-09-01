<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Tenant\FeeAssign;
use App\Models\Tenant\Member;
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
            'X-Tenant' => self::SLUG,
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
