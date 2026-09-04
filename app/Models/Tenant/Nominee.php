<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Who a member's savings go to if they die.
 *
 * The legacy system captured nominees at registration and then had no screen to
 * change them, so a member who married, divorced or was widowed had no way to
 * say so. That is the requirement this model exists for - it is inherited from
 * the SRS rather than ported from the old code, because there was nothing to
 * port.
 *
 * `share_percentage` is what makes several nominees more than a list: it is how
 * a member splits the balance between them. It is validated to total at most
 * 100 across a member's nominees, because a split that adds up to 130% is not a
 * split, it is a dispute waiting for a funeral.
 */
class Nominee extends Model
{
    protected $fillable = [
        'member_id',
        'name',
        'relation',
        'birth_date',
        'nid',
        'mobile',
        'address',
        'image',
        'share_percentage',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'share_percentage' => 'decimal:2',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
