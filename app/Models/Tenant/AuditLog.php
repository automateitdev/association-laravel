<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only record of every money, membership and permission change
 * (NFR-SEC-7). Written inside the same transaction as the change it records, so
 * an audit gap means a rollback rather than a silent hole.
 */
class AuditLog extends Model
{
    use Immutable;

    public const UPDATED_AT = null;

    protected $fillable = [
        'actor_type', 'actor_id', 'subject_type', 'subject_id',
        'action', 'before', 'after', 'reason', 'ip', 'request_id',
    ];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array'];
    }
}
