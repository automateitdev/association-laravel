<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * Us. The people who provision and support associations.
 *
 * CENTRAL CONNECTION, PINNED. Without the trait this model would follow the
 * default connection, which tenancy repoints at whichever association is
 * currently initialised - so an operator would "exist" or not depending on
 * which tenant a request happened to touch. `App\Models\User`, by contrast, is
 * the association's own staff model and is meant to follow the swap.
 *
 * An operator is NOT a member of any association and holds no role inside one.
 * Reading an association's member or money data needs an explicit, logged
 * break-glass grant (FR-SEC-6), which is not built yet - see the platform
 * console's own notes.
 */
class Operator extends Authenticatable
{
    use CentralConnection;
    use Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(OperatorAuditLog::class);
    }

    /**
     * Disabled accounts cannot sign in.
     *
     * Checked at login rather than by deleting the row: the audit entries have
     * to keep resolving to a name long after somebody has left.
     */
    public function canAuthenticate(): bool
    {
        return $this->is_active;
    }
}
