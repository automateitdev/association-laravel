<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * An association member - a subscriber, not staff.
 *
 * Staff live in `users`. The legacy system makes the same split and it trips up
 * everyone at least once: there, `User` means staff and `Member` means member.
 */
class Member extends Authenticatable
{
    use HasApiTokens;
    use SoftDeletes;

    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';

    protected $fillable = [
        'name', 'father_name', 'mother_name', 'spouse_name',
        'bcs_batch', 'cadre_id', 'joining_date', 'birth_date', 'gender',
        'mobile', 'email', 'password',
        'nid', 'present_address', 'permanent_address', 'office_address',
        'emergency_contact',
        'image', 'nid_front', 'nid_back', 'signature',
        'proof_joining_cadre', 'proof_signed_by_sup_author',
        'status', 'created_by', 'updated_by',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'joining_date' => 'date',
            'birth_date' => 'date',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function associatorInfo(): HasOne
    {
        return $this->hasOne(AssociatorInfo::class);
    }

    public function nominees(): HasMany
    {
        return $this->hasMany(Nominee::class);
    }

    public function feeAssigns(): HasMany
    {
        return $this->hasMany(FeeAssign::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PaymentInfo::class);
    }

    public function shareBalances(): HasMany
    {
        return $this->hasMany(MemberShareBalance::class);
    }

    /**
     * Login is refused for inactive and suspended members, with DISTINCT error
     * codes so the app can explain which (FR-AUTH-3). They are entirely
     * different situations - "waiting for approval" versus "you owe money" - and
     * a shared message sends both to the office to ask which.
     */
    public function canAuthenticate(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
}
