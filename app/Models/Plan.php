<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A commercial plan. Central database.
 */
class Plan extends Model
{
    /*
     * Pinned to the central database.
     *
     * These rows live in the registry, so following the default connection
     * would make them "exist" or not depending on whichever association
     * happened to be initialised - and if a tenant's database is unreachable,
     * reading them fails for a reason that has nothing to do with them.
     */
    use CentralConnection;

    protected $fillable = [
        'name',
        'member_limit',
        'sms_included',
        'features',
        'price',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'features' => 'array',
            'price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function tenants(): HasMany
    {
        return $this->hasMany(Tenant::class);
    }
}
