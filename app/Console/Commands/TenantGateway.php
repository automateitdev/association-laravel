<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\Tenant\AuditLog;
use App\Models\Tenant\GatewayCredential;
use App\Services\Gateways\SonaliPaymentGateway;
use Illuminate\Console\Command;

/**
 * Set an association's payment-gateway credentials (FR-PAY-11).
 *
 * WHY THIS IS A COMMAND AND NOT A SCREEN
 * --------------------------------------
 * It used to be a screen: `PUT /staff/settings/gateway`, available to anybody
 * in the association holding `settings.edit`. That followed FR-PAY-11's
 * original wording, "settable by staff", which in turn followed A-1 - each
 * association procures its own merchant account.
 *
 * A-1 is about whose contract and whose bank account it is. It does not follow
 * that the association's own officers should hold the write surface, and two
 * things say they should not:
 *
 *   1. `ar_account` is WHERE THE MONEY LANDS. Anyone with `settings.edit` could
 *      change it. That is a money-diversion vector in the hands of a role held
 *      by treasurers and office staff, granted for editing fine rates.
 *
 *   2. The credentials are deliberately write-only - never returned by any
 *      endpoint, so a compromised account cannot read them. The same property
 *      means a malicious CHANGE is nearly invisible: the audit log records that
 *      the gateway was updated and which field names, never the values. There
 *      is no "what was it before" to compare against.
 *
 * So the surface moved to whoever provisions the association, which is the same
 * person, on the same machine, doing the same onboarding as `tenant:provision`.
 * The association keeps what it needs: it can SEE whether a gateway is
 * configured and which account it ends in, and it can turn online payment off
 * entirely (`payment.online_enabled`). It cannot point the money somewhere new.
 *
 * SECRETS ARE PROMPTED, NEVER PASSED AS ARGUMENTS. A merchant password in
 * `--password=` lands in shell history, in `ps` output while it runs, and in
 * any terminal recording. The prompt is the whole reason this is interactive.
 */
class TenantGateway extends Command
{
    protected $signature = 'tenant:gateway
        {slug : The association to configure}
        {--show : Print what is configured, without changing it}
        {--disable : Mark the gateway inactive, leaving the credentials in place}';

    protected $description = 'Set or inspect an association payment gateway (prompts for secrets)';

    public function handle(): int
    {
        $tenant = Tenant::find((string) $this->argument('slug'));

        if (! $tenant) {
            $this->error("No association [{$this->argument('slug')}].");

            return self::FAILURE;
        }

        return $tenant->run(function () use ($tenant) {
            if ($this->option('show')) {
                return $this->show($tenant);
            }

            if ($this->option('disable')) {
                return $this->disable($tenant);
            }

            return $this->setCredentials($tenant);
        });
    }

    private function show(Tenant $tenant): int
    {
        $credential = GatewayCredential::where('provider', SonaliPaymentGateway::PROVIDER)->first();

        $this->line("Association: {$tenant->getKey()}");

        if (! $credential) {
            $this->warn('  No gateway configured. Online payment cannot be taken.');

            return self::SUCCESS;
        }

        $this->line('  Provider:   '.$credential->provider);
        $this->line('  Active:     '.($credential->is_active ? 'yes' : 'no'));

        // The last four only, here as everywhere else. A command that prints a
        // merchant password to a terminal has undone the reason for the move.
        $this->line('  AR account: ...'.substr((string) $credential->credential('ar_account'), -4));
        $this->line('  Updated:    '.$credential->updated_at?->toDateTimeString());

        return self::SUCCESS;
    }

    private function disable(Tenant $tenant): int
    {
        $credential = GatewayCredential::where('provider', SonaliPaymentGateway::PROVIDER)->first();

        if (! $credential) {
            $this->warn('Nothing to disable: no gateway is configured.');

            return self::SUCCESS;
        }

        $credential->update(['is_active' => false]);

        $this->record($tenant, 'gateway.credentials.disabled', ['provider' => $credential->provider]);

        $this->info('Gateway marked inactive. The credentials are kept, so re-enabling does not need re-entering them.');

        return self::SUCCESS;
    }

    /** Named `setCredentials`, not `configure`: Symfony's Command reserves that. */
    private function setCredentials(Tenant $tenant): int
    {
        $existing = GatewayCredential::where('provider', SonaliPaymentGateway::PROVIDER)->first();

        $this->line("Configuring the payment gateway for [{$tenant->getKey()}].");

        if ($existing) {
            $this->warn('A gateway is already configured. Continuing REPLACES every field.');

            if (! $this->confirm('Replace it?', false)) {
                $this->line('Cancelled.');

                return self::SUCCESS;
            }
        }

        $values = [
            'api_base_url' => $this->ask('API base URL'),
            'redirect_base_url' => $this->ask('Redirect base URL (where the member comes back to)'),
            'username' => $this->ask('Merchant username'),
            'password' => $this->secret('Merchant password'),
            'ar_account' => $this->ask('AR account (where the money lands)'),
            'basic_auth' => $this->secret('Basic auth token'),
            'callback_username' => $this->ask('Callback username (what the gateway sends us)'),
            'callback_password' => $this->secret('Callback password'),
        ];

        foreach ($values as $field => $value) {
            if ($value === null || trim((string) $value) === '') {
                $this->error("{$field} is required. Nothing was written.");

                return self::FAILURE;
            }
        }

        /*
         * Read back and confirmed before writing, because the AR account is the
         * one field where a typo does not fail loudly - it succeeds, and the
         * money goes somewhere else.
         */
        $this->newLine();
        $this->line('  AR account:  '.$values['ar_account']);
        $this->line('  API base:    '.$values['api_base_url']);
        $this->line('  Merchant:    '.$values['username']);
        $this->newLine();

        if (! $this->confirm('Is the AR account above correct?', false)) {
            $this->line('Cancelled. Nothing was written.');

            return self::FAILURE;
        }

        GatewayCredential::updateOrCreate(
            ['provider' => SonaliPaymentGateway::PROVIDER],
            ['credentials' => $values, 'is_active' => true],
        );

        $this->record($tenant, 'gateway.credentials.updated', [
            'provider' => SonaliPaymentGateway::PROVIDER,

            // Field NAMES, never values - the same rule the old endpoint kept.
            'fields' => array_keys($values),
            'set_by' => 'tenant:gateway',
        ]);

        $this->info('Gateway configured.');
        $this->line('The association can see that it is set, and can turn online payment off, but cannot change it.');

        return self::SUCCESS;
    }

    /**
     * Recorded in the ASSOCIATION's audit log, not only the operator's shell.
     *
     * The association is entitled to know its gateway changed and when, even
     * though it cannot make the change itself. `actor_type` is null because the
     * actor is the platform operator, who has no row in the tenant database.
     *
     * @param  array<string, mixed>  $after
     */
    private function record(Tenant $tenant, string $action, array $after): void
    {
        AuditLog::create([
            'actor_type' => null,
            'actor_id' => null,
            'subject_type' => GatewayCredential::class,
            'subject_id' => 0,
            'action' => $action,
            'after' => $after,
            'reason' => 'Set by the platform operator from the server console.',
        ]);
    }
}
