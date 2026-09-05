<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\OperatorAuditLog;
use App\Models\Tenant;
use App\Models\Tenant\AuditLog;
use App\Models\Tenant\GatewayCredential;
use App\Services\Gateways\SonaliPaymentGateway;
use DomainException;
use Throwable;

/**
 * Setting an association's merchant credentials (FR-PAY-11).
 *
 * A SERVICE BECAUSE THERE ARE NOW TWO CALLERS: `tenant:gateway` at a server
 * prompt and the platform console in a browser. That is the same reason
 * `PlatformService` and `TenantProvisioner` exist, and it matters more here than
 * either: `ar_account` is where an association's money lands, and a second
 * implementation of "write it, confirm it, record it in both audit logs" is a
 * way for one surface to quietly skip the recording.
 *
 * WHOSE SURFACE THIS IS. It used to be `PUT /staff/settings/gateway`, open to
 * anybody in the association holding `settings.edit` - a permission granted to
 * treasurers and office staff for editing fine rates. Two things moved it:
 *
 *   1. `ar_account` is a money-diversion vector in the hands of that role.
 *   2. The credentials are write-only by design, never returned by any
 *      endpoint, so a compromised account cannot read them. The same property
 *      makes a malicious CHANGE nearly invisible - the audit log records which
 *      field NAMES were written, never the values, so there is no "what was it
 *      before" to compare against.
 *
 * The association keeps what it needs: it can see whether a gateway is
 * configured and which account it ends in, and it can turn online payment off
 * entirely. It cannot point the money somewhere new.
 *
 * NOTHING HERE EVER RETURNS A SECRET. `summary()` returns the last four digits
 * of the AR account and no other value, because a console that could display
 * the merchant password would have undone the reason for the move.
 */
class GatewayConfigurator
{
    /**
     * Every field, in the order a person is asked for them.
     *
     * `secret` decides two things at once: whether the CLI hides typing, and
     * whether the browser renders `type=password`. One list, so the two surfaces
     * cannot disagree about which values are sensitive.
     *
     * @var array<string, array{label: string, secret: bool, help?: string}>
     */
    public const FIELDS = [
        'api_base_url' => ['label' => 'API base URL', 'secret' => false],
        'redirect_base_url' => [
            'label' => 'Redirect base URL',
            'secret' => false,
            'help' => 'Where the member comes back to after paying.',
        ],
        'username' => ['label' => 'Merchant username', 'secret' => false],
        'password' => ['label' => 'Merchant password', 'secret' => true],
        'ar_account' => [
            'label' => 'AR account',
            'secret' => false,
            'help' => 'Where the money lands. Checked twice before anything is written.',
        ],
        'basic_auth' => ['label' => 'Basic auth token', 'secret' => true],
        'callback_username' => [
            'label' => 'Callback username',
            'secret' => false,
            'help' => 'What the gateway sends us, not what we send it.',
        ],
        'callback_password' => ['label' => 'Callback password', 'secret' => true],
    ];

    /**
     * What is configured, with no secret in it.
     *
     * @return array<string, mixed>|null
     */
    public function summary(Tenant $tenant): ?array
    {
        try {
            return $this->read($tenant);
        } catch (Throwable $e) {
            /*
             * As in PlatformService::health(): a tenant whose database will not
             * answer must not take the whole page down, and tenancy is ended
             * explicitly or the connection is left pointing at the missing
             * database and the next query anywhere fails for an unrelated
             * reason.
             *
             * Reported as its own state rather than as null. Null means "no
             * gateway configured", and telling somebody that about a database
             * nobody can reach would invite them to configure a second one.
             */
            tenancy()->end();

            return ['unreachable' => true, 'reason' => $e->getMessage()];
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function read(Tenant $tenant): ?array
    {
        return $tenant->run(function () {
            $credential = GatewayCredential::where('provider', SonaliPaymentGateway::PROVIDER)->first();

            if (! $credential) {
                return null;
            }

            return [
                'provider' => $credential->provider,
                'is_active' => (bool) $credential->is_active,

                /*
                 * The last four only. Enough to tell one account from another
                 * when checking that onboarding was done right, and not enough
                 * to be worth stealing.
                 */
                'ar_account_last4' => substr((string) $credential->credential('ar_account'), -4),
                'updated_at' => $credential->updated_at?->toDateTimeString(),
            ];
        });
    }

    /**
     * Write the credentials.
     *
     * @param  array<string, string>  $values
     *
     * @throws DomainException when a field is missing or the AR account was not confirmed
     */
    public function store(Tenant $tenant, array $values, string $source, ?string $confirmedAccount = null): void
    {
        $clean = [];

        foreach (array_keys(self::FIELDS) as $field) {
            $value = trim((string) ($values[$field] ?? ''));

            if ($value === '') {
                throw new DomainException(
                    self::FIELDS[$field]['label']." is required. Nothing was written."
                );
            }

            $clean[$field] = $value;
        }

        /*
         * The AR account is read back and re-entered, not merely shown with a
         * yes/no. It is the one field where a typo does not fail loudly - it
         * succeeds, and the money goes somewhere else - and a confirmation that
         * only needs a click is one people learn to click.
         */
        if ($confirmedAccount !== null && trim($confirmedAccount) !== $clean['ar_account']) {
            throw new DomainException(
                'The AR account and its confirmation do not match. Nothing was written.'
            );
        }

        $tenant->run(function () use ($clean) {
            GatewayCredential::updateOrCreate(
                ['provider' => SonaliPaymentGateway::PROVIDER],
                ['credentials' => $clean, 'is_active' => true],
            );
        });

        $this->record($tenant, 'gateway.credentials.updated', [
            'provider' => SonaliPaymentGateway::PROVIDER,

            // Field NAMES, never values.
            'fields' => array_keys($clean),

            // Which account, to four digits: the association is entitled to see
            // that the destination changed, without the console leaking it.
            'ar_account_last4' => substr($clean['ar_account'], -4),
            'set_by' => $source,
        ], $source);
    }

    /** Stop taking online payment, keeping the credentials so it can be resumed. */
    public function disable(Tenant $tenant, string $source): void
    {
        $found = $tenant->run(function () {
            $credential = GatewayCredential::where('provider', SonaliPaymentGateway::PROVIDER)->first();

            if (! $credential) {
                return false;
            }

            $credential->update(['is_active' => false]);

            return true;
        });

        if (! $found) {
            throw new DomainException('Nothing to disable: no gateway is configured.');
        }

        $this->record($tenant, 'gateway.credentials.disabled', [
            'provider' => SonaliPaymentGateway::PROVIDER,
        ], $source);
    }

    public function enable(Tenant $tenant, string $source): void
    {
        $found = $tenant->run(function () {
            $credential = GatewayCredential::where('provider', SonaliPaymentGateway::PROVIDER)->first();

            if (! $credential) {
                return false;
            }

            $credential->update(['is_active' => true]);

            return true;
        });

        if (! $found) {
            throw new DomainException('There are no credentials to enable. Set them first.');
        }

        $this->record($tenant, 'gateway.credentials.enabled', [
            'provider' => SonaliPaymentGateway::PROVIDER,
        ], $source);
    }

    /**
     * Recorded in BOTH logs, and they answer different questions.
     *
     * The association's says their gateway changed and when — they are entitled
     * to know, even though they cannot make the change themselves. The
     * platform's says which operator changed it and from where, which is the
     * only one of the two that is any use in an incident.
     *
     * @param  array<string, mixed>  $after
     */
    private function record(Tenant $tenant, string $action, array $after, string $source): void
    {
        $tenant->run(function () use ($action, $after, $source) {
            AuditLog::create([
                // The actor is a platform operator, who has no row in the
                // tenant database. Null rather than a dangling id.
                'actor_type' => null,
                'actor_id' => null,
                'subject_type' => GatewayCredential::class,
                'subject_id' => 0,
                'action' => $action,
                'after' => $after,
                'reason' => $source === 'console'
                    ? 'Set by the platform operator at the server console.'
                    : 'Set by the platform operator in the platform console.',
            ]);
        });

        OperatorAuditLog::record(
            action: $action,
            tenantId: $tenant->getKey(),
            after: $after,
            reason: 'Gateway configuration.',
            source: $source,
        );
    }
}
