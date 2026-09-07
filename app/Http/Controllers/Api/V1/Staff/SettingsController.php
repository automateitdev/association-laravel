<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Controllers\Controller;
use App\Models\Tenant\AuditLog;
use App\Models\Tenant\GatewayCredential;
use App\Models\Tenant\Setting;
use App\Services\GatewayConfigurator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Per-association configuration (FR-SET-1).
 *
 * This controller is why a second association can be onboarded without a code
 * change. Everything the legacy system hard-codes - the fine rate, the fine
 * ledger, the gateway credentials, the bank account members pay into - is a
 * row here instead.
 */
class SettingsController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => [
                'fine' => [
                    'rate' => Setting::get(Setting::FINE_RATE),
                    'grace_days' => Setting::get(Setting::FINE_GRACE_DAYS),
                    'suspension_threshold' => Setting::get(Setting::SUSPENSION_THRESHOLD),
                ],
                'invoice' => [
                    'format' => Setting::get(Setting::INVOICE_FORMAT),
                ],
                'payment' => [
                    'intent_ttl_minutes' => Setting::get(Setting::PAYMENT_INTENT_TTL_MINUTES),
                    'online_enabled' => (bool) Setting::get(Setting::ONLINE_PAYMENT_ENABLED),
                ],
                'bank' => $this->bankDetails(),

                /*
                 * READ-ONLY, and not merely because the credentials are secret.
                 *
                 * The gateway is set by whoever provisions the association
                 * (`php artisan tenant:gateway`), not from this API at all.
                 * `ar_account` is where the money lands, and an endpoint that
                 * lets anybody holding `settings.edit` change it puts a
                 * money-diversion vector in a role granted for editing fine
                 * rates. Worse, because credentials are never readable, a
                 * malicious change leaves almost nothing to compare against.
                 *
                 * What the association keeps is what it needs: whether a
                 * gateway is set, which account it ends in, and the separate
                 * `payment.online_enabled` switch - which can turn collection
                 * OFF but cannot point it somewhere new.
                 */
                'gateway' => $this->gatewaySummary(),
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'fine.rate' => ['sometimes', 'numeric', 'min:0'],
            'fine.grace_days' => ['sometimes', 'integer', 'min:0', 'max:90'],
            'fine.suspension_threshold' => ['sometimes', 'integer', 'min:1', 'max:60'],
            'invoice.format' => ['sometimes', 'string', 'max:100'],
            'payment.intent_ttl_minutes' => ['sometimes', 'integer', 'min:5', 'max:1440'],
            'payment.online_enabled' => ['sometimes', 'boolean'],

            'bank.account_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'bank.account_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'bank.bank_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'bank.branch' => ['sometimes', 'nullable', 'string', 'max:255'],
            'bank.routing_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'bank.instructions' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $before = [];
        $after = [];

        DB::transaction(function () use ($validated, &$before, &$after) {
            foreach ($validated as $group => $values) {
                foreach ($values as $name => $value) {
                    $key = "{$group}.{$name}";

                    $before[$key] = Setting::get($key);
                    Setting::put($key, $value);
                    $after[$key] = $value;
                }
            }
        });

        /*
         * Audited, and the fine rate especially.
         *
         * FR-SET-3: changing the rate must not retroactively alter fines
         * already accrued - accrual recomputes from elapsed fine dates at the
         * CURRENT rate, so a member who asks "why did my fine change?" needs an
         * answer with a date and a name on it.
         */
        AuditLog::create([
            'actor_type' => $request->user()::class,
            'actor_id' => $request->user()->id,
            'subject_type' => Setting::class,
            'subject_id' => 0,
            'action' => 'settings.updated',
            'before' => $before,
            'after' => $after,
            'ip' => $request->ip(),
        ]);

        return $this->index();
    }

    private function bankDetails(): array
    {
        return [
            'account_name' => Setting::get(Setting::BANK_ACCOUNT_NAME),
            'account_number' => Setting::get(Setting::BANK_ACCOUNT_NUMBER),
            'bank_name' => Setting::get(Setting::BANK_NAME),
            'branch' => Setting::get(Setting::BANK_BRANCH),
            'routing_number' => Setting::get(Setting::BANK_ROUTING_NUMBER),
            'instructions' => Setting::get(Setting::BANK_INSTRUCTIONS),
        ];
    }

    /**
     * WHICHEVER GATEWAY THE ASSOCIATION IS ON - not a hard-coded `spg`.
     *
     * An association reaches the same bank one of two ways, directly or through
     * PayFlex, and it uses one of them (the table now refuses two active rows).
     * This asked only about the direct provider, so an association moved onto
     * PayFlex read its own settings screen as "no gateway configured, online
     * payment cannot be taken" while it was in fact taking payments - and one
     * that had been on the direct route first was shown the AR account of the
     * dead row it had left.
     *
     * The active row first, then the most recent: a gateway switched off is
     * still the one they have, and saying "not configured" about it would send
     * somebody to ask the platform for credentials that are already there.
     */
    private function gatewaySummary(): array
    {
        $credential = GatewayCredential::query()
            ->orderByDesc('is_active')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first();

        return [
            'provider' => $credential?->provider,

            // The name a person uses for it. `payflex_spg` is a routing key we
            // made up, and the app was rendering it verbatim in capitals.
            'label' => $credential
                ? (GatewayConfigurator::PROVIDERS[$credential->provider]['label'] ?? $credential->provider)
                : null,

            'configured' => $credential !== null,
            'is_active' => (bool) $credential?->is_active,

            // Enough to confirm WHICH account is configured, without exposing
            // anything that would let someone use it.
            'ar_account_last4' => $credential
                ? substr((string) $credential->credential('ar_account'), -4)
                : null,

            // Said plainly, so an association looking for the missing form
            // knows it is missing on purpose and who to ask.
            'managed_by' => 'platform',
        ];
    }
}
