<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\TenantProvisioningRun;
use App\Services\FineService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Nightly fine accrual, once per active association (FR-FINE-1, FR-TEN-11).
 *
 * ISOLATION IS THE POINT
 * ----------------------
 * Each tenant runs in its own try/catch. A failure in one association must not
 * prevent the others from accruing - and a silent accrual failure is unusually
 * expensive here, because nobody notices fines NOT being charged. It surfaces
 * weeks later as a month of wrong dues that has to be explained to every
 * affected member, one at a time.
 *
 * That is why every run is recorded whether it succeeded or not, and why the
 * monitoring alert in bcs-docs/09-environments-and-devops.md fires when a
 * tenant has had no successful run in 25 hours.
 *
 * Accrual is idempotent (FR-FINE-3): the fine is recomputed from the fine-date
 * series rather than incremented, so re-running after a failure is safe and
 * catching up several missed days needs no special handling.
 */
class AccrueFines extends Command
{
    protected $signature = 'fines:accrue
        {--tenant= : Restrict to one association slug}
        {--as-of= : Accrue as at this date (YYYY-MM-DD) instead of today}';

    protected $description = 'Accrue overdue fines for every active association';

    public function handle(): int
    {
        $asOf = $this->option('as-of')
            ? CarbonImmutable::parse($this->option('as-of'))->startOfDay()
            : CarbonImmutable::now()->startOfDay();

        $tenants = Tenant::query()
            ->where('status', Tenant::STATUS_ACTIVE)
            ->when($this->option('tenant'), fn ($q, $slug) => $q->where('id', $slug))
            ->get();

        if ($tenants->isEmpty()) {
            $this->warn('No active associations to process.');

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($tenants as $tenant) {
            $run = TenantProvisioningRun::begin($tenant->getKey(), 'fines:accrue');

            try {
                $summary = $tenant->run(
                    fn () => app(FineService::class)->accrueAll($asOf)
                );

                $line = sprintf(
                    '%s: examined %d, fined %d, suspended %d',
                    $tenant->getKey(),
                    $summary['examined'],
                    $summary['fined'],
                    $summary['suspended'],
                );

                if ($summary['failed'] !== []) {
                    /*
                     * Per-member containment means CONTINUE PROCESSING, not
                     * REPORT SUCCESS.
                     *
                     * FineService catches each member's error so one bad row
                     * cannot cost the association a night's accrual. But that
                     * also means a systemic fault - a missing table, a
                     * permissions problem, a dead connection - arrives here as
                     * "every member failed" rather than as an exception. Marking
                     * the run succeeded would make the two indistinguishable,
                     * which is precisely the silent failure this command exists
                     * to avoid: nobody notices fines NOT being charged.
                     *
                     * So a run with any failed member is a failed run, even
                     * though the other members accrued correctly.
                     */
                    $failed++;

                    $line .= sprintf(', %d member(s) FAILED', count($summary['failed']));
                    $this->warn($line);

                    foreach ($summary['failed'] as $failure) {
                        $this->line("    {$failure}");
                    }

                    $run->fail(implode("\n", $summary['failed']));

                    continue;
                }

                $this->info($line);
                $run->succeed($line);
            } catch (Throwable $e) {
                $failed++;

                // Contained. The next tenant still runs.
                $this->error("{$tenant->getKey()}: {$e->getMessage()}");
                $run->fail($e->getMessage());
            }
        }

        if ($failed > 0) {
            $this->newLine();
            $this->error("{$failed} of {$tenants->count()} associations failed to accrue.");

            // Non-zero so the scheduler and monitoring notice, even though the
            // other associations were processed successfully.
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
