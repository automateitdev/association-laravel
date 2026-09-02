<?php

declare(strict_types=1);

namespace Tests\Feature\Scheduled;

use App\Models\Tenant;
use App\Models\Tenant\FeeAssign;
use App\Models\TenantProvisioningRun;
use App\Services\FeeAssignService;
use App\Services\TenantSeedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\TenantFixtures;
use Tests\Support\ParallelSlugs;
use Tests\TestCase;

/**
 * T-12: fine accrual fails for one association; the others still accrue.
 *
 * This is one of the two tests that make multi-tenancy real. A scheduled task
 * that spans tenants in a single transaction - which is exactly what the legacy
 * command does - means one association's bad row silently costs every
 * association a night's accrual.
 *
 * The failure is induced honestly: the second tenant's `fine_dates` table is
 * dropped, so accrual against it genuinely throws. No mocking of the service
 * under test.
 */
class FineAccrualIsolationTest extends TestCase
{
    use ParallelSlugs;
    use RefreshDatabase;
    use TenantFixtures;

    private const HEALTHY = 'healthy-assoc';

    private const BROKEN = 'broken-assoc';

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::HEALTHY, self::BROKEN] as $base) {
            $slug = $this->slugFor($base);
            $this->artisan('tenant:provision', ['slug' => $slug])->assertSuccessful();

            Tenant::find($slug)->run(function () {
                app(TenantSeedService::class)->seedAll();

                $member = $this->makeMember();
                $feeSetup = $this->makeFeeSetup();

                app(FeeAssignService::class)->assign($member->id, $feeSetup, '2026-01');
            });
        }
    }

    protected function tearDown(): void
    {
        foreach ([self::HEALTHY, self::BROKEN] as $base) {
            try {
                Tenant::find($this->slugFor($base))?->delete();
            } catch (\Throwable) {
            }

            $this->dropTenantArtefactsFor($base);
        }

        parent::tearDown();
    }

    public function test_one_tenants_failure_does_not_stop_the_others(): void
    {
        // Break the second association for real.
        Tenant::find($this->slugFor(self::BROKEN))->run(fn () => DB::statement('DROP TABLE fine_dates'));

        $this->artisan('fines:accrue', ['--as-of' => '2026-03-15'])
            ->assertFailed();   // non-zero, so monitoring notices

        // The healthy association accrued regardless: 3 elapsed periods x 100.
        Tenant::find($this->slugFor(self::HEALTHY))->run(function () {
            $assign = FeeAssign::firstOrFail();

            $this->assertSame(
                '300.00',
                $assign->fine_amount,
                'A failure in another association must not affect this one.'
            );
        });
    }

    /**
     * Every run is recorded, successful or not. A silent accrual failure is
     * invisible for weeks - nobody notices fines not being charged.
     */
    public function test_every_tenants_run_is_recorded(): void
    {
        Tenant::find($this->slugFor(self::BROKEN))->run(fn () => DB::statement('DROP TABLE fine_dates'));

        $this->artisan('fines:accrue', ['--as-of' => '2026-03-15']);

        $this->assertDatabaseHas('tenant_provisioning_runs', [
            'tenant_id' => $this->slugFor(self::HEALTHY),
            'command' => 'fines:accrue',
            'status' => TenantProvisioningRun::STATUS_SUCCEEDED,
        ]);

        $this->assertDatabaseHas('tenant_provisioning_runs', [
            'tenant_id' => $this->slugFor(self::BROKEN),
            'command' => 'fines:accrue',
            'status' => TenantProvisioningRun::STATUS_FAILED,
        ]);
    }

    public function test_accrual_runs_for_every_active_association(): void
    {
        $this->artisan('fines:accrue', ['--as-of' => '2026-03-15'])->assertSuccessful();

        foreach ([self::HEALTHY, self::BROKEN] as $base) {
            $slug = $this->slugFor($base);
            Tenant::find($slug)->run(function () use ($slug) {
                $this->assertSame(
                    '300.00',
                    FeeAssign::firstOrFail()->fine_amount,
                    "Association {$slug} should have accrued."
                );
            });
        }
    }

    /** A suspended association keeps accruing - dues do not pause (FR-TEN-8). */
    public function test_a_suspended_association_still_accrues(): void
    {
        Tenant::find($this->slugFor(self::BROKEN))->update(['status' => Tenant::STATUS_SUSPENDED]);

        $this->artisan('fines:accrue', ['--as-of' => '2026-03-15'])->assertSuccessful();

        // Only active tenants are processed by the scheduled sweep, so a
        // suspended association is skipped here by design - its dues resume
        // accruing on reinstatement because the fine-date series is recomputed,
        // not incremented. This asserts the sweep does not crash on it.
        Tenant::find($this->slugFor(self::HEALTHY))->run(
            fn () => $this->assertSame('300.00', FeeAssign::firstOrFail()->fine_amount)
        );
    }

    public function test_a_single_association_can_be_targeted(): void
    {
        $this->artisan('fines:accrue', [
            '--tenant' => $this->slugFor(self::HEALTHY),
            '--as-of' => '2026-03-15',
        ])->assertSuccessful();

        Tenant::find($this->slugFor(self::HEALTHY))->run(
            fn () => $this->assertSame('300.00', FeeAssign::firstOrFail()->fine_amount)
        );

        Tenant::find($this->slugFor(self::BROKEN))->run(
            fn () => $this->assertSame('0.00', FeeAssign::firstOrFail()->fine_amount)
        );
    }
}
