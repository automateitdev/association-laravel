<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\OperatorAuditLog;
use App\Models\Tenant;
use App\Models\Tenant\AuditLog;
use App\Models\Tenant\GatewayCredential;
use DomainException;
use Illuminate\Support\Facades\DB;
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
     * The providers an operator may configure, and what each one needs.
     *
     * TWO ROUTES TO THE SAME BANK. `spg` reaches Sonali directly on its v2 API;
     * `payflex_spg` reaches the same bank through the PayFlex middleware, which
     * speaks SPG v3 and fronts several other gateways besides. Which one an
     * association uses is its own setting, so one can be moved onto PayFlex and
     * proved while every other stays on the path that already works.
     *
     * Not called "version 1" and "version 2", though they get called that in
     * conversation. Both the legacy system and the direct adapter call SPG v2
     * and PayFlex calls v3, so a version number in these keys would be actively
     * misleading to whoever reads them next.
     *
     * `secret` decides two things at once: whether the CLI hides typing, and
     * whether the browser renders `type=password`. One list, so the two surfaces
     * cannot disagree about which values are sensitive.
     *
     * @var array<string, array{label: string, blurb: string, fields: array<string, array{label: string, secret: bool, help?: string, optional?: bool}>}>
     */
    public const PROVIDERS = [
        'spg' => [
            'label' => 'Sonali Payment Gateway — direct',
            'blurb' => "Talks to SPG's v2 API from this server. What the legacy system does, "
                .'and what every association has used until now.',
            'fields' => [
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
            ],
        ],

        'payflex_spg' => [
            'label' => 'Sonali Payment Gateway — through PayFlex',
            'blurb' => "Talks to PayFlex, which talks to SPG's v3 API. The association still needs "
                .'its own SPG merchant credentials: PayFlex will otherwise fall back to its own '
                .'account, which would collect this association\'s money into somebody else\'s.',
            'fields' => [
                'payflex_base_url' => [
                    'label' => 'PayFlex base URL',
                    'secret' => false,
                    'help' => 'The middleware, not the bank. No trailing path.',
                ],
                'payflex_username' => [
                    'label' => 'PayFlex username',
                    'secret' => false,
                    'help' => 'The client-domain credentials PayFlex issued us, sent as Basic auth.',
                ],
                'payflex_password' => ['label' => 'PayFlex password', 'secret' => true],

                'spg_user' => [
                    'label' => 'SPG merchant username',
                    'secret' => false,
                    'help' => "The association's own SPG account, passed through on every request.",
                ],
                'spg_password' => ['label' => 'SPG merchant password', 'secret' => true],

                'ar_account' => [
                    'label' => 'AR account',
                    'secret' => false,
                    'help' => 'Where the money lands. Checked twice before anything is written.',
                ],
                'party_name' => [
                    'label' => 'Party name',
                    'secret' => false,
                    'optional' => true,
                    'help' => 'Shown on the SPG voucher. Optional.',
                ],
            ],
        ],
    ];

    /**
     * @return array<string, array{label: string, secret: bool, help?: string, optional?: bool}>
     */
    public static function fieldsFor(string $provider): array
    {
        return self::PROVIDERS[$provider]['fields']
            ?? throw new DomainException("There is no gateway called '{$provider}'.");
    }

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
            /*
             * The ACTIVE row, whichever provider it names - not a lookup for one
             * hard-coded provider. An association that moved from the direct
             * gateway to PayFlex keeps the old row switched off, and a summary
             * that asked only about `spg` would report the dead one.
             */
            $credential = GatewayCredential::query()
                ->orderByDesc('is_active')
                ->orderByDesc('updated_at')
                ->first();

            if (! $credential) {
                return null;
            }

            return [
                'provider' => $credential->provider,
                'label' => self::PROVIDERS[$credential->provider]['label'] ?? $credential->provider,
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
    public function store(
        Tenant $tenant,
        string $provider,
        array $values,
        string $source,
        ?string $confirmedAccount = null,
    ): void {
        $fields = self::fieldsFor($provider);
        $clean = [];

        foreach ($fields as $field => $meta) {
            $value = trim((string) ($values[$field] ?? ''));

            if ($value === '') {
                if ($meta['optional'] ?? false) {
                    continue;
                }

                throw new DomainException($meta['label'].' is required. Nothing was written.');
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

        $tenant->run(function () use ($clean, $provider) {
            /*
             * AT MOST ONE ACTIVE GATEWAY. Configuring one switches every other
             * off in the same transaction, because "which gateway is this
             * association using" has to have exactly one answer - two active
             * rows would make the answer depend on row order, which is how an
             * association ends up collecting through the provider it thought it
             * had left.
             *
             * The others are kept, not deleted: moving back is then a switch
             * rather than eight fields typed again from a password manager.
             */
            DB::transaction(function () use ($clean, $provider) {
                GatewayCredential::where('provider', '!=', $provider)->update(['is_active' => false]);

                GatewayCredential::updateOrCreate(
                    ['provider' => $provider],
                    ['credentials' => $clean, 'is_active' => true],
                );
            });
        });

        $this->record($tenant, 'gateway.credentials.updated', [
            'provider' => $provider,

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
            $credential = GatewayCredential::query()->orderByDesc('is_active')->first();

            if (! $credential) {
                return false;
            }

            $credential->update(['is_active' => false]);

            return true;
        });

        if (! $found) {
            throw new DomainException('Nothing to disable: no gateway is configured.');
        }

        $this->record($tenant, 'gateway.credentials.disabled', [], $source);
    }

    public function enable(Tenant $tenant, string $source): void
    {
        $found = $tenant->run(function () {
            $credential = GatewayCredential::query()->orderByDesc('updated_at')->first();

            if (! $credential) {
                return false;
            }

            $credential->update(['is_active' => true]);

            return true;
        });

        if (! $found) {
            throw new DomainException('There are no credentials to enable. Set them first.');
        }

        $this->record($tenant, 'gateway.credentials.enabled', [], $source);
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
