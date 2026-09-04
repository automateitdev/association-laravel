<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\OperatorAuditLog;
use App\Models\Tenant;
use App\Models\TenantProvisioningRun;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Everything the platform console does to an association (FR-PLT-1 … FR-PLT-3).
 *
 * A SERVICE, NOT A CONTROLLER, because these actions have two callers: the
 * console in a browser and the artisan commands at a server prompt. Anything
 * implemented in only one of them is a difference in behaviour depending on how
 * somebody happened to be sitting, which is exactly the sort of divergence that
 * makes an audit trail worth less than it looks.
 *
 * Every method that changes something records it (FR-PLT-4). That is not left
 * to callers to remember.
 */
class PlatformService
{
    /**
     * Suspend an association.
     *
     * The tenant keeps its database and every row in it. `ResolveTenant`
     * refuses requests for a suspended tenant, so members and staff are locked
     * out immediately and nothing is destroyed - which is the correct shape for
     * a billing dispute or an investigation, the two reasons this exists.
     */
    public function suspend(Tenant $tenant, string $reason, string $source = 'web'): Tenant
    {
        $before = ['status' => $tenant->status];

        $tenant->update([
            'status' => Tenant::STATUS_SUSPENDED,
            'suspended_at' => now(),
        ]);

        OperatorAuditLog::record(
            action: 'tenant.suspended',
            tenantId: $tenant->getKey(),
            before: $before,
            after: ['status' => Tenant::STATUS_SUSPENDED],
            reason: $reason,
            source: $source,
        );

        return $tenant->fresh();
    }

    public function reinstate(Tenant $tenant, string $reason, string $source = 'web'): Tenant
    {
        $before = ['status' => $tenant->status];

        $tenant->update([
            'status' => Tenant::STATUS_ACTIVE,
            'suspended_at' => null,
        ]);

        OperatorAuditLog::record(
            action: 'tenant.reinstated',
            tenantId: $tenant->getKey(),
            before: $before,
            after: ['status' => Tenant::STATUS_ACTIVE],
            reason: $reason,
            source: $source,
        );

        return $tenant->fresh();
    }

    /**
     * Archive an association.
     *
     * ARCHIVING DOES NOT DROP THE DATABASE, and the console says so. An
     * association's ledger is a financial record with a retention period
     * measured in years; a button that quietly destroyed one would be the worst
     * thing in this codebase. Archived means "closed, still readable by an
     * operator with a break-glass grant". Dropping the database is a separate,
     * deliberate act that has to happen at the server with the backup in hand.
     */
    public function archive(Tenant $tenant, string $reason, string $source = 'web'): Tenant
    {
        $before = ['status' => $tenant->status];

        $tenant->update([
            'status' => Tenant::STATUS_ARCHIVED,
            'archived_at' => now(),
        ]);

        OperatorAuditLog::record(
            action: 'tenant.archived',
            tenantId: $tenant->getKey(),
            before: $before,
            after: ['status' => Tenant::STATUS_ARCHIVED],
            reason: $reason,
            source: $source,
        );

        return $tenant->fresh();
    }

    /**
     * Run migrations for one association (FR-PLT-2).
     *
     * Recorded in `tenant_provisioning_runs` with its output, because "did the
     * migration run, and what did it say" is a question asked days later by
     * somebody who was not watching the screen.
     */
    public function migrate(Tenant $tenant, string $source = 'web'): TenantProvisioningRun
    {
        $run = TenantProvisioningRun::begin($tenant->getKey(), 'tenants:migrate');

        try {
            /*
             * stancl's own command, not a hand-rolled `migrate --path`. It uses
             * `tenancy.migration_parameters`, so the console and a server
             * prompt run migrations exactly the same way - including the
             * `--realpath` handling, which is the sort of detail that silently
             * migrates nothing when it is wrong.
             */
            Artisan::call('tenants:migrate', ['--tenants' => [$tenant->getKey()]]);

            $run->succeed(Artisan::output());
        } catch (Throwable $e) {
            $run->fail($e->getMessage());
        }

        OperatorAuditLog::record(
            action: 'tenant.migrated',
            tenantId: $tenant->getKey(),
            after: ['run_id' => $run->id, 'status' => $run->fresh()->status],
            source: $source,
        );

        return $run->fresh();
    }

    /**
     * Per-tenant health (FR-PLT-3).
     *
     * ONE TENANT AT A TIME, ON PURPOSE. Every figure here comes from inside the
     * tenant's own database, reached through `$tenant->run()`. There is no
     * cross-database query anywhere in this file - FR-PLT-5 forbids it, and the
     * reason is not performance: a query that reaches across tenant databases
     * is one bug away from showing one association another's numbers.
     *
     * @return array<string, mixed>
     */
    public function health(Tenant $tenant): array
    {
        if ($tenant->status === Tenant::STATUS_PROVISIONING) {
            return ['reachable' => false, 'reason' => 'Still provisioning.'];
        }

        try {
            return $tenant->run(function () use ($tenant) {
                $lastAccrual = DB::table('fine_dates')->max('updated_at');

                return [
                    'reachable' => true,
                    'members' => DB::table('members')->count(),
                    'completed_payments' => DB::table('payment_infos')->where('status', 'completed')->count(),
                    'pending_payments' => DB::table('payment_infos')->where('status', 'pending')->count(),

                    // The accrual is the job whose silent failure costs members
                    // money, so its last run is the health figure that matters.
                    'last_fine_accrual' => $lastAccrual,

                    'failed_jobs' => $this->failedJobs($tenant),
                    'database_size_mb' => $this->databaseSize($tenant),
                    'staff_accounts' => DB::table('users')->count(),
                ];
            });
        } catch (Throwable $e) {
            /*
             * A tenant whose database will not answer is exactly what this
             * screen exists to surface, so the failure IS the health report.
             *
             * Tenancy is ended explicitly: if `run()` threw while initialising,
             * the connection can be left pointing at the missing database, and
             * the next query - any query, on any model - fails for a reason
             * that has nothing to do with it.
             */
            tenancy()->end();

            return ['reachable' => false, 'reason' => $e->getMessage()];
        }
    }

    /**
     * Failed jobs for this tenant.
     *
     * `failed_jobs` lives in the CENTRAL database - the queue is shared - so
     * this counts by payload rather than reading a tenant table. It is a
     * best-effort match on the tenant id inside the serialised job.
     */
    private function failedJobs(Tenant $tenant): int
    {
        try {
            return DB::connection(config('tenancy.database.central_connection'))
                ->table('failed_jobs')
                ->where('payload', 'like', '%"'.$tenant->getKey().'"%')
                ->count();
        } catch (Throwable) {
            // No failed_jobs table configured; not worth failing health over.
            return 0;
        }
    }

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
