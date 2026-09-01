<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

/**
 * An association.
 *
 * Lives in the central database. Owns exactly one tenant database, holding every
 * member, payment and ledger row for that association (ADR-0001).
 *
 * The primary key is the slug, and it is immutable (FR-TEN-5) - it is baked into
 * the database name, storage paths, tokens and DNS.
 */
class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase;
    use HasDomains;

    /**
     * The slug is the key, and it is a string we supply - never generated.
     *
     * These two overrides are load-bearing. stancl's GeneratesIds trait derives
     * both from whether an id generator is bound in the container, so with
     * `tenancy.id_generator => null` the key silently falls back to an
     * auto-incrementing integer: the tenant row keeps its slug, but the model in
     * memory gets key 0, the domain foreign key is written as 0, and the tenant
     * database is never created. Stating them explicitly is what makes the slug
     * the identity (FR-TEN-5).
     */
    public $incrementing = false;

    protected $keyType = 'string';

    public function getIncrementing(): bool
    {
        return false;
    }

    public function getKeyType(): string
    {
        return 'string';
    }

    public const STATUS_PROVISIONING = 'provisioning';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_ARCHIVED = 'archived';
    public const STATUS_FAILED = 'failed';

    /**
     * Columns that are real columns rather than keys in the `data` JSON blob.
     *
     * stancl/tenancy's VirtualColumn trait puts every unlisted attribute into
     * `data`. Anything we want to query or index has to be named here.
     */
    public static function getCustomColumns(): array
    {
        return [
            'id',
            'name',
            'legal_name',
            'status',
            'plan_id',
            'locale',
            'timezone',
            'currency',
            'onboarded_at',
            'suspended_at',
            'archived_at',
        ];
    }

    protected function casts(): array
    {
        return [
            'onboarded_at' => 'datetime',
            'suspended_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Only an active tenant may serve API requests (FR-TEN-3).
     *
     * A suspended tenant keeps its data and keeps accruing fines (FR-TEN-8); it
     * simply cannot be reached over HTTP.
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }
}
