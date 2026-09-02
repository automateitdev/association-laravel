<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Controllers\Controller;
use App\Models\Tenant\AuditLog;
use App\Models\Tenant\GatewayCredential;
use App\Models\Tenant\Setting;
use App\Services\Gateways\SonaliPaymentGateway;
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

                // Whether a gateway is configured - never the credentials
                // themselves, not even to a superadmin. They are write-only.
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

    /**
     * Store the association's Sonali Payment Gateway credentials.
     *
     * WRITE-ONLY. They are never read back by any endpoint, including for the
     * superadmin who set them: an API that can display a merchant password
     * turns one compromised staff token into a compromised merchant account.
     * Rotating means re-entering, which is the correct trade.
     */
    public function updateGateway(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider' => ['sometimes', 'in:spg'],
            'api_base_url' => ['required', 'url', 'max:255'],
            'redirect_base_url' => ['required', 'url', 'max:255'],
            'username' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'ar_account' => ['required', 'string', 'max:50'],
            'basic_auth' => ['required', 'string', 'max:255'],

            // What Sonali sends US in the callback body, so we can tell a real
            // callback from anyone who guessed the URL.
            'callback_username' => ['required', 'string', 'max:255'],
            'callback_password' => ['required', 'string', 'max:255'],

            'is_active' => ['sometimes', 'boolean'],
        ]);

        $provider = $validated['provider'] ?? SonaliPaymentGateway::PROVIDER;

        GatewayCredential::updateOrCreate(
            ['provider' => $provider],
            [
                'credentials' => collect($validated)
                    ->except(['provider', 'is_active'])
                    ->all(),
                'is_active' => $validated['is_active'] ?? true,
            ]
        );

        AuditLog::create([
            'actor_type' => $request->user()::class,
            'actor_id' => $request->user()->id,
            'subject_type' => GatewayCredential::class,
            'subject_id' => 0,
            'action' => 'gateway.credentials.updated',

            // Never the values. The record is that it happened, by whom.
            'after' => ['provider' => $provider, 'fields' => array_keys($validated)],
            'ip' => $request->ip(),
        ]);

        return response()->json(['data' => $this->gatewaySummary()]);
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

    private function gatewaySummary(): array
    {
        $credential = GatewayCredential::query()
            ->where('provider', SonaliPaymentGateway::PROVIDER)
            ->first();

        return [
            'provider' => SonaliPaymentGateway::PROVIDER,
            'configured' => $credential !== null,
            'is_active' => (bool) $credential?->is_active,

            // Enough to confirm WHICH account is configured, without exposing
            // anything that would let someone use it.
            'ar_account_last4' => $credential
                ? substr((string) $credential->credential('ar_account'), -4)
                : null,
        ];
    }
}
