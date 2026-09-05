<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\OperatorAuditLog;
use App\Services\TenantProvisioner;
use Illuminate\Console\Command;

/**
 * Provision one association (FR-TEN-6).
 *
 * A THIN WRAPPER. The work lives in TenantProvisioner, because the platform
 * console provisions too and this is the one operation here where a second
 * implementation would be genuinely dangerous - it creates a database and a
 * scoped MySQL user, and a partial failure leaves an orphan that blocks the
 * next attempt on the same slug. One rollback path, two doors to it.
 *
 * All-or-nothing. A half-created tenant - database present, migrations half-run,
 * registry row written - is worse than no tenant at all. Every failure path
 * rolls the whole thing back and records why.
 *
 * Verified by T-13: provisioning fails midway, no orphan database and no orphan
 * registry row remain.
 */
class TenantProvision extends Command
{
    protected $signature = 'tenant:provision
        {slug : The immutable association identifier, e.g. cocsol}
        {--name= : Display name (defaults to the slug)}
        {--legal-name= : Registered legal name}
        {--domain= : Hostname, defaults to <slug>.<central domain>}
        {--admin-name=Administrator : Name of the first superadmin}
        {--admin-email= : Email of the first superadmin; omit to skip creating one}
        {--locale=en}
        {--timezone=Asia/Dhaka}
        {--currency=BDT}';

    protected $description = 'Create an association: database, scoped DB user, migrations, domain';

    public function handle(TenantProvisioner $provisioner): int
    {
        $slug = (string) $this->argument('slug');

        $this->info("Provisioning [{$slug}]...");

        $result = $provisioner->provision([
            'slug' => $slug,
            'name' => $this->option('name'),
            'legal_name' => $this->option('legal-name'),
            'domain' => $this->option('domain'),
            'admin_name' => $this->option('admin-name'),
            'admin_email' => $this->option('admin-email'),
            'locale' => $this->option('locale'),
            'timezone' => $this->option('timezone'),
            'currency' => $this->option('currency'),
        ]);

        foreach ($result->steps as $step) {
            $this->line('  '.$step);
        }

        if (! $result->ok) {
            $this->newLine();
            $this->error("Provisioning failed: {$result->error}");

            if ($result->attempted) {
                $this->warn("Rolled back. {$result->rollbackNotes}");
            }

            return self::FAILURE;
        }

        /*
         * Recorded in the operator log too (FR-PLT-4), with `console` as the
         * source, so provisioning at a server prompt and provisioning from a
         * browser are distinguishable afterwards. In an incident that
         * difference is most of the question.
         */
        OperatorAuditLog::record(
            action: 'tenant.provisioned',
            tenantId: $result->tenant->getKey(),
            after: ['domain' => $result->domain, 'name' => $result->tenant->name],
            reason: 'Provisioned at the server console.',
            source: 'console',
        );

        $this->newLine();
        $this->info("Tenant [{$slug}] is active.");
        $this->line("  database: {$result->tenant->database()->getName()}");
        $this->line("  db user:  {$result->tenant->database()->getUsername()}");
        $this->line("  domain:   {$result->domain}");

        if ($result->setupToken) {
            $this->newLine();
            $this->line("  superadmin: {$this->option('admin-email')}");
            $this->line("  setup token: {$result->setupToken}");
            $this->comment('  The token is shown once. It sets the first password; it is not a password.');
        }

        return self::SUCCESS;
    }
}
