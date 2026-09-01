<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One member's liability for one fee head for one period.
 *
 * The atom of the money model - everything else aggregates these.
 *
 * `amount` is the INSTALMENT. `fine_amount` is the PENALTY. They are never
 * added together into a single figure that is then stored or returned (ADR-0005).
 */
class FeeAssign extends Model
{
    public const STATUS_UNPAID = 'Unpaid';
    public const STATUS_REQUESTED = 'Requested';
    public const STATUS_PAID = 'Paid';

    protected $fillable = [
        'member_id',
        'fee_setup_id',
        'period',
        'assign_date',
        'fine_date',
        'amount',
        'fine_amount',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'assign_date' => 'date',
            'fine_date' => 'date',
            'amount' => 'decimal:2',
            'fine_amount' => 'decimal:2',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function feeSetup(): BelongsTo
    {
        return $this->belongsTo(FeeSetup::class);
    }

    public function fineDates(): HasMany
    {
        return $this->hasMany(FineDate::class);
    }

    /**
     * Deliberately hasMany, not hasOne.
     *
     * An assignment can carry several items: pending attempts that were never
     * completed, plus at most one completed one (I-1). The legacy model declares
     * this as hasOne and silently shows the first row it finds (defect D-15).
     */
    public function items(): HasMany
    {
        return $this->hasMany(PaymentInfoItem::class);
    }

    /** What the member owes right now. A convenience, never a stored column. */
    public function totalDue(): string
    {
        return bcadd((string) $this->amount, (string) $this->fine_amount, 2);
    }

    public function isSettled(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function scopeOutstanding($query)
    {
        return $query->whereIn('status', [self::STATUS_UNPAID, self::STATUS_REQUESTED]);
    }
}
