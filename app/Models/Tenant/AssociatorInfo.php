<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A member's society record: membership number, share number, batch, employer.
 *
 * Not to be confused with an association. The legacy `assocs` table holds this
 * data for one member; there is no association entity in that system at all.
 */
class AssociatorInfo extends Model
{
    protected $table = 'associators_infos';

    protected $fillable = [
        'member_id', 'membership_no', 'join_date', 'share_no',
        'num_or_shares', 'bcs_batch', 'company', 'designation',
    ];

    protected function casts(): array
    {
        return ['join_date' => 'date'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
