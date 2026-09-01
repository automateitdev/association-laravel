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
