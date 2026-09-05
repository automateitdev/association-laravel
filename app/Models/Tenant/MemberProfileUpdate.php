<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A change a member has asked for, waiting on the office (FR-MEM-8).
 *
 * WHY MEMBERS DO NOT EDIT THEMSELVES. The fields in here are how the office
 * identifies somebody at the counter and how the association reaches them:
 * name, mobile, national ID. A member who can change them unilaterally can also
 * change them to somebody else's, and the association would have no record that
 * the old values ever existed.
 *
 * So the member proposes and the office decides. `changes` holds only what they
 * asked to change, and the decision - either way - keeps the request.
 *
 * NOTHING IS APPLIED UNTIL APPROVAL. A pending request has no effect on the
 * member's record at all, which is why the API returns both the current value
 * and the proposed one: an officer deciding needs to see what it is now.
 */
class MemberProfileUpdate extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    /**
     * What a member may ask to change.
     *
     * Deliberately narrow. Money fields, status and membership number are not
     * here and never will be - those are the association's record of a member,
     * not the member's description of themselves.
     */
    public const ALLOWED = [
        'name',
        'father_name',
        'mother_name',
        'spouse_name',
        'birth_date',
        'gender',
        'mobile',
        'email',
        'nid',
        'present_address',
        'permanent_address',
        'office_address',
        'emergency_contact',
    ];

    protected $fillable = [
        'member_id',
        'changes',
        'status',
        'decision_reason',
        'decided_by',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'decided_at' => 'datetime',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }
}
