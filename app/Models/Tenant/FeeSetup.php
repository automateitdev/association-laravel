<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A fee head: a monthly subscription, a share purchase, an admission fee.
 */
class FeeSetup extends Model
{
    protected $fillable = [
        'fee_head',
        'monthly',
        'amount',
        'is_share',
        'ledger_id',
        'fine_ledger_id',
        'fine_rate',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'monthly' => 'boolean',
            'is_share' => 'boolean',
            'is_active' => 'boolean',
            'amount' => 'decimal:2',
        ];
    }

    public function assigns(): HasMany
    {
        return $this->hasMany(FeeAssign::class);
    }

    /** Instalments are credited here. */
    public function ledger(): BelongsTo
    {
        return $this->belongsTo(Ledger::class);
    }

    /**
     * Fines are credited HERE - a different account (FR-ACC-2).
     *
     * Chosen by staff, not stamped from config. The legacy system reads a global
     * config value, which cannot work across tenants with different charts of
     * accounts.
     */
    public function fineLedger(): BelongsTo
    {
        return $this->belongsTo(Ledger::class, 'fine_ledger_id');
    }
}
