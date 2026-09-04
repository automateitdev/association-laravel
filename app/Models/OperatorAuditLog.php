<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * What an operator did (FR-PLT-4).
 *
 * APPEND-ONLY BY INTENT. There is no update path here and no screen that edits
 * an entry. That is intent, not enforcement: a log nobody can tamper with needs
 * signing or off-box shipping, and neither is built. Said plainly rather than
 * implied, because an audit log people over-trust is worse than one they know
 * the limits of.
 *
 * WRITTEN FROM BOTH SURFACES. The console commands write here too, with
 * `source = 'console'`. An action taken at the server and the same action taken
 * in a browser are different facts about how somebody had access, and in an
 * incident that difference is most of the question.
 */
class OperatorAuditLog extends Model
{
    use CentralConnection;

    /** Only `created_at`; an entry is never updated. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'operator_id',
        'operator_email',
        'action',
        'tenant_id',
        'before',
        'after',
        'reason',
        'source',
        'ip',
    ];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /**
     * Record an action.
     *
     * The operator's email is copied in alongside the foreign key, so an entry
     * still says who did it even if the account row is later removed.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public static function record(
        string $action,
        ?string $tenantId = null,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
        string $source = 'web',
        ?Operator $operator = null,
        ?string $ip = null,
    ): self {
        $actor = $operator ?? Auth::guard('operator')->user();

        return self::create([
            'operator_id' => $actor?->id,
            'operator_email' => $actor?->email,
            'action' => $action,
            'tenant_id' => $tenantId,
            'before' => $before,
            'after' => $after,
            'reason' => $reason,
            'source' => $source,
            'ip' => $ip ?? request()?->ip(),
        ]);
    }
}
