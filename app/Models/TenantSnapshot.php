<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * One association's figures as of one collection run (FR-PLT-5).
 *
 * Read the platform totals off this table, never by querying across tenant
 * databases. See the migration for why that distinction is a safety property
 * rather than a performance one.
 */
class TenantSnapshot extends Model
{
    use CentralConnection;

    protected $fillable = [
        'tenant_id',
        'members',
        'active_members',
        'staff_accounts',
        'completed_payments',
        'pending_payments',
        'collected_instalments',
        'collected_fines',
        'outstanding_instalments',
        'outstanding_fines',
        'database_size_mb',
        'last_fine_accrual',
        'error',
        'collected_at',
    ];

    protected function casts(): array
    {
        return [
            'collected_at' => 'datetime',
            'last_fine_accrual' => 'datetime',
            'collected_instalments' => 'decimal:2',
            'collected_fines' => 'decimal:2',
            'outstanding_instalments' => 'decimal:2',
            'outstanding_fines' => 'decimal:2',
            'database_size_mb' => 'decimal:2',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /**
     * The most recent snapshot for each tenant.
     *
     * History is kept, so "latest" has to be asked for rather than assumed -
     * a total that silently summed every run ever would be nonsense.
     */
    public function scopeLatestPerTenant(Builder $query): Builder
    {
        return $query->whereIn('id', function ($sub) {
            $sub->selectRaw('MAX(id)')->from('tenant_snapshots')->groupBy('tenant_id');
        });
    }
}
