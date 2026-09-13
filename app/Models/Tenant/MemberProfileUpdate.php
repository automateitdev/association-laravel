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
        // Who they are
        'name',
        'father_name',
        'mother_name',
        'spouse_name',
        'birth_date',
        'gender',
        'nid',

        /*
         * How to reach them.
         *
         * `country_code` WAS MISSING, and its absence was a silent one.
         * ProfileController validates it, the member's request carries it, an
         * officer sees it on screen and approves - and then this list drops it
         * on the way to `$member->update()`, because approval filters against
         * ALLOWED. A member who moved abroad got their new number applied
         * against the old country. The two travel together by design; they
         * have to be permitted together too.
         */
        'mobile',
        'country_code',
        'email',
        'emergency_contact',
        'present_address',
        'permanent_address',
        'office_address',

        /*
         * The cadre service record, which is what makes an applicant eligible
         * for this kind of association at all - the legacy form asks for the
         * batch, the joining date and the cadre ID on its first tab, and this
         * list did not carry any of them. A member whose cadre ID was typed
         * wrong at registration had no way to say so.
         */
        'bcs_batch',
        'cadre_id',
        'joining_date',

        /*
         * The reference: who vouched for this applicant. Legacy `ref_name`,
         * `ref_mobile` and `ref_memeber_id_no`.
         *
         * `introduced_by_member_id` is the link when the introducer is
         * themselves a member; `introduced_by_name` is the record when they
         * are not. Both, because the legacy data has 63 members with a
         * reference and no guarantee it resolves to a row here.
         */
        'introduced_by_member_id',
        'introduced_by_name',
        'introduced_by_mobile',
    ];

    /**
     * The prefix nominee fields wear inside `changes`.
     *
     * ONE PENDING ROW COVERS BOTH, which is how the legacy does it: its
     * `member_profile_updates` table carries `name` and `nominee_name`,
     * `nid` and `nominee_nid`, side by side, and the office decides them
     * together. That is the right shape for the thing being decided - a
     * member changing their nominee is one act, and splitting it into two
     * queue entries an officer could approve separately would let a nominee's
     * name be accepted while their NID was refused.
     *
     * Flat prefixed keys rather than a nested object, so `changes` stays a
     * map of field to value: the staff screen lists the keys, the applier
     * walks them, and neither has to learn a second shape.
     */
    public const NOMINEE_PREFIX = 'nominee_';

    /**
     * What a member may ask to change about their nominee.
     *
     * The legacy member form's second tab, mapped onto this schema -
     * `relation_with_user` is `relation` here, `professional_details` is
     * `profession`, and `permanent_address` is `address`.
     *
     * `share_percentage` IS NOT HERE. It is not on the legacy form either,
     * and it is the kind of field that decides who receives what: a member
     * proposing it is reasonable, but it has never been collected this way
     * and inventing it under "as it is in legacy" would be adding a feature
     * while claiming parity. Staff set it.
     */
    public const NOMINEE_ALLOWED = [
        'name',
        'relation',
        'father_name',
        'mother_name',
        'gender',
        'birth_date',
        'nid',
        'mobile',
        'country_code',
        'address',
        'profession',
    ];

    /**
     * The nominee half of a change set, unprefixed.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    public static function nomineeChanges(array $changes): array
    {
        $out = [];

        foreach ($changes as $key => $value) {
            if (! str_starts_with($key, self::NOMINEE_PREFIX)) {
                continue;
            }

            $field = substr($key, strlen(self::NOMINEE_PREFIX));

            if (in_array($field, self::NOMINEE_ALLOWED, true)) {
                $out[$field] = $value;
            }
        }

        return $out;
    }

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
