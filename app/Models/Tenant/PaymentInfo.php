<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One payment covering one or more assignments for one member.
 *
 * THE column to understand here is `payable_amount`, and the thing to understand
 * is what it does NOT contain:
 *
 *     payable_amount = SUM(item.amount)   -- instalments only, never a fine
 *     total_amount   = payable_amount + fine_amount
 *
 * The legacy online path writes the gateway's total (instalments PLUS fine) into
 * payable_amount, and every "savings" figure on every report reads that column
 * (defect D-1). The gateway's own figure belongs in `spg_pay_amount`.
 */
class PaymentInfo extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_EXPIRED = 'expired';

    public const TYPE_MANUAL = 'manual';
    public const TYPE_ONLINE = 'online';

    protected $fillable = [
        'invoice_no', 'member_id', 'ledger_id',
        'payable_amount', 'fine_amount', 'total_amount', 'spg_pay_amount',
        'status', 'payment_type', 'gateway_reference', 'expires_at',
        'payment_date', 'reason', 'documents',
        'created_by', 'decided_by', 'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'payable_amount' => 'decimal:2',
            'fine_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'spg_pay_amount' => 'decimal:2',
            'documents' => 'array',
            'payment_date' => 'date',
            'expires_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PaymentInfoItem::class);
    }

    public function ledger(): BelongsTo
    {
        return $this->belongsTo(Ledger::class);
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
