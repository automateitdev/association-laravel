<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\GatewayConfigurator;
use DomainException;
use Illuminate\Console\Command;

/**
 * Set an association's payment-gateway credentials at the server (FR-PAY-11).
 *
 * THE SAME WORK THE CONSOLE DOES, through the same `GatewayConfigurator`. This
 * command came first and was for a while the only surface; the console form
 * exists now because onboarding an association should not require a server
 * prompt. Neither is the "real" one, and neither may quietly skip the recording
 * the other does - which is why the writing lives in the service and not here.
 *
 * WHAT THIS SURFACE IS STILL FOR: a deployment with the console switched off,
 * and a machine where somebody is already provisioning at a prompt anyway.
 *
 * SECRETS ARE PROMPTED, NEVER PASSED AS ARGUMENTS. A merchant password in
 * `--password=` lands in shell history, in `ps` output while it runs, and in any
 * terminal recording. The prompt is the whole reason this is interactive.
 */
class TenantGateway extends Command
{
    protected $signature = 'tenant:gateway
        {slug : The association to configure}
        {--show : Print what is configured, without changing it}
        {--disable : Stop taking online payment, leaving the credentials in place}
        {--enable : Resume with the credentials already stored}';

    protected $description = 'Set or inspect an association payment gateway (prompts for secrets)';

    public function handle(GatewayConfigurator $gateways): int
    {
        $tenant = Tenant::find((string) $this->argument('slug'));

        if (! $tenant) {
            $this->error("No association [{$this->argument('slug')}].");

            return self::FAILURE;
        }

        try {
            return match (true) {
                (bool) $this->option('show') => $this->show($gateways, $tenant),
                (bool) $this->option('disable') => $this->toggle($gateways, $tenant, false),
                (bool) $this->option('enable') => $this->toggle($gateways, $tenant, true),
                default => $this->setCredentials($gateways, $tenant),
            };
        } catch (DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function show(GatewayConfigurator $gateways, Tenant $tenant): int
    {
        $summary = $gateways->summary($tenant);

        $this->line("Association: {$tenant->getKey()}");

        if (! $summary) {
            $this->warn('  No gateway configured. Online payment cannot be taken.');

            return self::SUCCESS;
        }

        $this->line('  Provider:   '.$summary['provider']);
        $this->line('  Active:     '.($summary['is_active'] ? 'yes' : 'no'));

        // The last four only, here as everywhere else. A command that printed a
        // merchant password to a terminal would undo the reason this moved.
        $this->line('  AR account: ...'.$summary['ar_account_last4']);
        $this->line('  Updated:    '.$summary['updated_at']);

        return self::SUCCESS;
    }

    private function toggle(GatewayConfigurator $gateways, Tenant $tenant, bool $on): int
    {
        $on ? $gateways->enable($tenant, 'console') : $gateways->disable($tenant, 'console');

        $this->info($on
            ? 'Online payment resumed with the credentials already stored.'
            : 'Online payment stopped. The credentials are kept, so resuming does not need them re-entered.');

        return self::SUCCESS;
    }

    /** Named `setCredentials`, not `configure`: Symfony's Command reserves that. */
    private function setCredentials(GatewayConfigurator $gateways, Tenant $tenant): int
    {
        $existing = $gateways->summary($tenant);

        $this->line("Configuring the payment gateway for [{$tenant->getKey()}].");

        if ($existing) {
            $this->warn('A gateway is already configured. Continuing REPLACES every field.');

            if (! $this->confirm('Replace it?', false)) {
                $this->line('Cancelled.');

                return self::SUCCESS;
            }
        }

        $values = [];

        // Driven by the service's field list, so a field added there is asked
        // for here without anybody having to remember to add it twice.
        foreach (GatewayConfigurator::FIELDS as $field => $meta) {
            if (isset($meta['help'])) {
                $this->comment('  '.$meta['help']);
            }

            $values[$field] = $meta['secret']
                ? (string) $this->secret($meta['label'])
                : (string) $this->ask($meta['label']);
        }

        /*
         * Re-typed, not confirmed with a yes. The AR account is the one field
         * where a typo does not fail loudly - it succeeds, and the money goes
         * somewhere else - and a confirmation that only needs a keypress is one
         * people learn to press.
         */
        $this->newLine();
        $confirm = (string) $this->ask('Type the AR account again to confirm');

        $gateways->store($tenant, $values, 'console', $confirm);

        $this->newLine();
        $this->info('Gateway configured.');
        $this->line('The association can see that it is set and can turn online payment off, but cannot change it.');

        return self::SUCCESS;
    }
}
