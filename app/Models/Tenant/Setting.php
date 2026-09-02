<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-association configuration.
 *
 * This table is why a second association can be onboarded without a code change.
 * The legacy system holds the fine rate in a class constant and the fine ledger
 * in a config file - both single-tenant assumptions (FR-SET-1, FR-SET-2).
 */
class Setting extends Model
{
    /** Overdue periods x this = the fine. Legacy behaviour: 100/-. */
    public const FINE_RATE = 'fine.rate';

    /** Days after the period starts before the fine clock begins. */
    public const FINE_GRACE_DAYS = 'fine.grace_days';

    /** Overdue periods before a member is suspended. Legacy behaviour: 3. */
    public const SUSPENSION_THRESHOLD = 'fine.suspension_threshold';

    public const INVOICE_FORMAT = 'invoice.format';

    /*
     * Where a member should transfer money for a manual payment.
     *
     * Not decoration: with online payment optional, a member reading "pay at
     * the bank and upload your slip" needs to be told WHICH account. Without
     * these the manual flow is incomplete - it asks for money and does not say
     * where to send it.
     *
     * Per association, because each holds its own account (A-1).
     */
    public const BANK_ACCOUNT_NAME = 'bank.account_name';

    public const BANK_ACCOUNT_NUMBER = 'bank.account_number';

    public const BANK_NAME = 'bank.bank_name';

    public const BANK_BRANCH = 'bank.branch';

    public const BANK_ROUTING_NUMBER = 'bank.routing_number';

    /** Free text shown under the account details, e.g. a reference to quote. */
    public const BANK_INSTRUCTIONS = 'bank.instructions';

    /** Whether members may start an online payment at all. */
    public const ONLINE_PAYMENT_ENABLED = 'payment.online_enabled';

    /** How long an online payment intent may sit unconfirmed (FR-PAY-8). */
    public const PAYMENT_INTENT_TTL_MINUTES = 'payment.intent_ttl_minutes';

    protected $fillable = ['key', 'value', 'group'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    /**
     * Values seeded at provisioning. Chosen to preserve legacy behaviour exactly
     * (migration transformation M-9) - an association that migrates must not see
     * its fines change on day one.
     */
    public static function defaults(): array
    {
        return [
            self::FINE_RATE => ['value' => '100.00', 'group' => 'fine'],
            self::FINE_GRACE_DAYS => ['value' => 0, 'group' => 'fine'],
            self::SUSPENSION_THRESHOLD => ['value' => 3, 'group' => 'fine'],
            self::INVOICE_FORMAT => ['value' => 'INV-{YYYY}-{SEQ:6}', 'group' => 'invoice'],
            self::PAYMENT_INTENT_TTL_MINUTES => ['value' => 60, 'group' => 'payment'],

            // Blank until the association fills them in. Deliberately seeded
            // empty rather than omitted, so the settings screen shows the
            // fields and their absence is visible rather than implicit.
            self::BANK_ACCOUNT_NAME => ['value' => '', 'group' => 'bank'],
            self::BANK_ACCOUNT_NUMBER => ['value' => '', 'group' => 'bank'],
            self::BANK_NAME => ['value' => '', 'group' => 'bank'],
            self::BANK_BRANCH => ['value' => '', 'group' => 'bank'],
            self::BANK_ROUTING_NUMBER => ['value' => '', 'group' => 'bank'],
            self::BANK_INSTRUCTIONS => ['value' => '', 'group' => 'bank'],

            // Off until an association configures a gateway and turns it on.
            self::ONLINE_PAYMENT_ENABLED => ['value' => false, 'group' => 'payment'],
        ];
    }

    public static function get(string $key, mixed $fallback = null): mixed
    {
        $row = static::query()->where('key', $key)->first();

        if ($row) {
            return $row->value;
        }

        return $fallback ?? (static::defaults()[$key]['value'] ?? null);
    }

    public static function put(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'group' => static::defaults()[$key]['group'] ?? 'general']
        );
    }
}
