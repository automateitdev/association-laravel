<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\TenantSnapshot;
use App\Services\TenantReadiness;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Gather each association's figures into the central registry (FR-PLT-5).
 *
 * THE FAN-OUT IS THE REQUIREMENT, not an implementation detail. Every number
 * here is read inside one tenant's own database, through `$tenant->run()`, and
 * written to a central row. No query in this file spans two tenant databases,
 * because the isolation that a database per tenant buys (ADR-0001) is worth
 * only as much as the discipline never to reach across it - and a reporting
 * query is the most tempting place to break that discipline.
 *
 * ONE TENANT FAILING MUST NOT STOP THE REST. A suspended association, or one
 * whose database is mid-restore, records its error in its own row and the run
 * carries on. A collection that aborted on the first bad tenant would leave the
 * console showing stale figures for everybody with no indication why.
 *
 * Scheduled daily; safe to run by hand.
 */
class CollectTenantSnapshots extends Command
{
    protected $signature = 'platform:snapshot
        {--tenant=* : Only these associations; defaults to all that are not archived}';

    protected $description = 'Collect per-association figures into the central registry (FR-PLT-5)';

    public function __construct(private readonly TenantReadiness $readiness)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $only = (array) $this->option('tenant');

        $tenants = Tenant::query()
            ->when($only !== [], fn ($q) => $q->whereIn('id', $only))
            ->when($only === [], fn ($q) => $q->where('status', '!=', Tenant::STATUS_ARCHIVED))
            ->get();

        if ($tenants->isEmpty()) {
            $this->warn('No associations to collect.');

            return self::SUCCESS;
        }

        $collectedAt = now();
        $ok = 0;
        $failed = 0;

        foreach ($tenants as $tenant) {
            try {
                $figures = $this->collect($tenant);
                $ok++;
            } catch (Throwable $e) {
                // Recorded, not thrown. The row IS the report that this tenant
                // could not be reached.
                $figures = ['error' => $e->getMessage()];
                $failed++;

                $this->warn("  {$tenant->getKey()}: {$e->getMessage()}");
            }

            /*
             * Readiness rides along with the figures rather than being asked
             * for when the list renders: one connection swap per association
             * per page load does not survive a platform with fifty of them.
             *
             * Outside the try above, and with its own guard, because an
             * association whose figures failed is exactly the one whose
             * readiness is worth recording - and a throw here would lose both.
             */
            try {
                $readiness = $this->readiness->for($tenant);
            } catch (Throwable $e) {
                $readiness = ['blocking' => 1, 'checks' => []];
            }

            TenantSnapshot::create($figures + [
                'tenant_id' => $tenant->getKey(),
                'collected_at' => $collectedAt,
                'blocking_issues' => $readiness['blocking'],
                'readiness' => $readiness['checks'],
            ]);
        }

        $this->info("Collected {$ok} association(s)".($failed > 0 ? ", {$failed} unreachable." : '.'));

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function collect(Tenant $tenant): array
    {
        $size = $this->databaseSize($tenant);

        return $tenant->run(function () use ($size) {
            $completed = DB::table('payment_infos')->where('status', 'completed');

            return [
                'members' => DB::table('members')->count(),
                'active_members' => DB::table('members')->where('status', 'active')->count(),
                'staff_accounts' => DB::table('users')->count(),

                'completed_payments' => (clone $completed)->count(),
                'pending_payments' => DB::table('payment_infos')->where('status', 'pending')->count(),

                /*
                 * Instalments and fines summed SEPARATELY, all the way up to the
                 * platform total (ADR-0005). A combined "collected" figure would
                 * lose the distinction at exactly the level where somebody is
                 * most likely to quote it as savings.
                 */
                'collected_instalments' => (clone $completed)->sum('payable_amount') ?: '0.00',
                'collected_fines' => (clone $completed)->sum('fine_amount') ?: '0.00',

                'outstanding_instalments' => DB::table('fee_assigns')
                    ->whereIn('status', ['Unpaid', 'Requested'])->sum('amount') ?: '0.00',
                'outstanding_fines' => DB::table('fee_assigns')
                    ->whereIn('status', ['Unpaid', 'Requested'])->sum('fine_amount') ?: '0.00',

                'last_fine_accrual' => DB::table('fine_dates')->max('updated_at'),
                'database_size_mb' => $size,
                'error' => null,
            ];
        });
    }

    /**
     * Read from `information_schema` OUTSIDE the tenant context, because the
     * size of a database is a fact about the server rather than about anything
     * inside the tenant.
     */
    private function databaseSize(Tenant $tenant): float
    {
        try {
            $row = DB::selectOne(
                'SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS mb
                 FROM information_schema.TABLES WHERE table_schema = ?',
                [$tenant->database()->getName()]
            );

            return (float) ($row->mb ?? 0);
        } catch (Throwable) {
            return 0.0;
        }
    }
}
