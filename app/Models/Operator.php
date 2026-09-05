<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Totp;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
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
        'mfa_secret',
        'mfa_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',

            /*
             * ENCRYPTED, not hashed. A TOTP secret must be recoverable to
             * verify a code. So a database dump alone yields no working second
             * factors - but a dump plus the app key does, which is why the key
             * lives outside the database it protects.
             */
            'mfa_secret' => 'encrypted',
            'mfa_recovery_codes' => 'encrypted:array',
            'mfa_confirmed_at' => 'datetime',
        ];
    }

    /** Enrolled AND proved: a secret nobody has verified is a lockout waiting. */
    public function hasMfa(): bool
    {
        return $this->mfa_secret !== null && $this->mfa_confirmed_at !== null;
    }

    /**
     * Check a TOTP code, refusing one already used.
     *
     * REPLAY IS THE POINT of `mfa_last_used_step`. A code stays valid for its
     * whole 30-second window, so without recording the step it was accepted at,
     * the same six digits - read over a shoulder, or lifted from a proxy log -
     * work again until the window closes.
     */
    public function verifyMfaCode(string $code, Totp $totp): bool
    {
        if (! $this->hasMfa()) {
            return false;
        }

        $step = $totp->verify($this->mfa_secret, $code);

        if ($step === null) {
            return false;
        }

        if ($this->mfa_last_used_step !== null && $step <= $this->mfa_last_used_step) {
            return false;
        }

        $this->forceFill(['mfa_last_used_step' => $step])->save();

        return true;
    }

    /**
     * Spend a recovery code, if it matches one.
     *
     * SINGLE USE. The matched code is removed before this returns, so a list
     * photographed once cannot be replayed. They are bcrypt-hashed because they
     * are passwords in every respect that matters - the only difference is that
     * we generated them.
     */
    public function consumeRecoveryCode(string $code): bool
    {
        $codes = $this->mfa_recovery_codes ?? [];
        $normalised = strtoupper(trim($code));

        foreach ($codes as $index => $hash) {
            if (Hash::check($normalised, $hash)) {
                unset($codes[$index]);

                $this->forceFill(['mfa_recovery_codes' => array_values($codes)])->save();

                return true;
            }
        }

        return false;
    }

    /** How many are left, so the console can say when they are running out. */
    public function recoveryCodesRemaining(): int
    {
        return count($this->mfa_recovery_codes ?? []);
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
