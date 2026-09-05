<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant;
use App\Models\Tenant\Setting;
use App\Services\Gateways\SonaliPaymentGateway;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * What is still missing before an association can actually be used.
 *
 * WHY THIS EXISTS. Provisioning succeeds and leaves you on a page of green
 * figures, and the association still cannot take a payment, because its gateway
 * was never configured and nobody said so. The old shape of that failure was a
 * phone call three weeks later. Onboarding is a SEQUENCE, and a console that
 * shows only the current state leaves the operator to remember the sequence
 * from memory - which is exactly the thing people are bad at and software is
 * good at.
 *
 * EVERY CHECK NAMES ITS FIX. A checklist that says "gateway: missing" and stops
 * is a nag. Each item below carries the words that finish it, so the answer to
 * "what now" is on the screen rather than in somebody's head.
 *
 * BLOCKING VERSUS ADVISORY, and the distinction is deliberate. Blocking means
 * members cannot use the association at all. Advisory means it works and
 * something is worth a look. Sorting them into one undifferentiated list of
 * warnings is how a real problem ends up sitting under four cosmetic ones.
 *
 * ONE TENANT AT A TIME, through `$tenant->run()`. As in `PlatformService`:
 * FR-PLT-5 forbids cross-database queries, and the reason is not performance -
 * a query that reaches across tenant databases is one bug away from showing one
 * association another's numbers.
 */
class TenantReadiness
{
    /**
     * @return array{ready: bool, reachable: bool, blocking: int, checks: list<array<string, mixed>>}
     */
    public function for(Tenant $tenant): array
    {
        if ($tenant->status === Tenant::STATUS_PROVISIONING) {
            return $this->unreachable('Still provisioning. Nothing to check yet.');
        }

        try {
            $facts = $tenant->run(fn () => [
                'staff' => DB::table('users')->count(),
                'superadmins' => $this->superadminCount(),
                'ledgers' => DB::table('ledgers')->count(),
                'fee_setups' => DB::table('fee_setups')->count(),
                'members' => DB::table('members')->count(),
                'gateway' => DB::table('gateway_credentials')
                    ->where('provider', SonaliPaymentGateway::PROVIDER)
                    ->first(),
                /*
                 * Through the model, not `DB::table(...)->value('value')`.
                 * `settings.value` is a JSON column, so the raw value of a
                 * false switch is the four characters "false" - and
                 * `(bool) 'false'` is true. The cast is the whole reason this
                 * one line does not use the query builder like its neighbours.
                 */
                'online_enabled' => (bool) Setting::get(Setting::ONLINE_PAYMENT_ENABLED),
            ]);
        } catch (Throwable $e) {
            /*
             * As in PlatformService::health(): if `run()` threw part way
             * through initialising, the connection can be left pointing at a
             * database that will not answer, and the next query anywhere fails
             * for a reason that has nothing to do with it.
             */
            tenancy()->end();

            return $this->unreachable($e->getMessage());
        }

        $checks = [
            [
                'key' => 'staff',
                'label' => 'An administrator exists',
                'ok' => $facts['superadmins'] > 0,
                'blocking' => true,
                'detail' => $facts['superadmins'] > 0
                    ? $facts['superadmins'].' superadmin'.($facts['superadmins'] === 1 ? '' : 's')
                        .', '.$facts['staff'].' staff account'.($facts['staff'] === 1 ? '' : 's')
                    : 'Nobody can administer this association.',
                /*
                 * Named because it is the one gap an operator cannot close from
                 * here. Provisioning issues a single-use setup token; if it was
                 * lost, the fix is a new one, not a password somebody chooses on
                 * the association's behalf.
                 */
                'fix' => 'Re-run provisioning\'s administrator step, or issue a new setup token. '
                    .'Nobody here should be choosing their password.',
            ],
            [
                'key' => 'gateway',
                'label' => 'Payment gateway configured',
                'ok' => $facts['gateway'] !== null,
                'blocking' => false,
                'detail' => $facts['gateway'] === null
                    ? 'Online payment cannot be taken. Counter collection still works.'
                    : ((bool) $facts['gateway']->is_active
                        ? 'Configured and active.'
                        : 'Configured but switched off.'),
                'fix' => 'Set it below, or run tenant:gateway at the server.',
            ],
            [
                'key' => 'online_switch',
                'label' => 'Online payment switched on',
                /*
                 * Only meaningful once a gateway exists. Reported as satisfied
                 * otherwise, so the list does not show two red lines for one
                 * missing thing.
                 */
                'ok' => $facts['gateway'] === null || $facts['online_enabled'],
                'blocking' => false,
                'detail' => $facts['gateway'] === null
                    ? 'Not applicable until a gateway is configured.'
                    : ($facts['online_enabled']
                        ? 'Members are offered "Pay now".'
                        : 'The association has turned it off in their own settings.'),
                // Theirs to change, not ours. Said so rather than offering a button.
                'fix' => 'The association controls this switch. Ask them, do not change it.',
            ],
            [
                'key' => 'ledgers',
                'label' => 'Chart of accounts seeded',
                'ok' => $facts['ledgers'] > 0,
                'blocking' => true,
                'detail' => $facts['ledgers'].' ledger'.($facts['ledgers'] === 1 ? '' : 's'),
                'fix' => 'Seeding is part of provisioning. Zero here means it did not finish — check the run log.',
            ],
            [
                'key' => 'fees',
                'label' => 'At least one fee head',
                'ok' => $facts['fee_setups'] > 0,
                'blocking' => false,
                'detail' => $facts['fee_setups'] === 0
                    ? 'Nothing can be charged until the association defines one.'
                    : $facts['fee_setups'].' defined',
                'fix' => "The association's own work, in Fees. Not an operator's to create.",
            ],
            [
                'key' => 'members',
                'label' => 'Members registered',
                'ok' => $facts['members'] > 0,
                'blocking' => false,
                'detail' => $facts['members'] === 0
                    ? 'Nobody has registered yet. Expected on a new association.'
                    : number_format($facts['members']).' registered',
                'fix' => 'Members register themselves; staff approve them.',
            ],
        ];

        $blocking = count(array_filter($checks, fn ($c) => ! $c['ok'] && $c['blocking']));

        return [
            'reachable' => true,
            // "Ready" is about the blocking ones only. An association with no
            // members yet is not broken; one with no administrator is.
            'ready' => $blocking === 0,
            'blocking' => $blocking,
            'checks' => $checks,
        ];
    }

    /**
     * @return array{ready: bool, reachable: bool, blocking: int, checks: list<array<string, mixed>>}
     */
    private function unreachable(string $reason): array
    {
        return [
            'reachable' => false,
            'ready' => false,
            'blocking' => 1,
            'checks' => [[
                'key' => 'database',
                'label' => 'Database reachable',
                'ok' => false,
                'blocking' => true,
                'detail' => $reason,
                'fix' => 'Nothing else can be checked until this is fixed.',
            ]],
        ];
    }

    private function superadminCount(): int
    {
        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.name', 'superadmin')
            ->where('model_has_roles.model_type', \App\Models\User::class)
            ->distinct()
            ->count('model_has_roles.model_id');
    }
}
