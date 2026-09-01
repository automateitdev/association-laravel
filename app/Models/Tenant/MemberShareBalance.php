<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberShareBalance extends Model
{
    protected $fillable = ['member_id', 'fee_setup_id', 'shares'];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function feeSetup(): BelongsTo
    {
        return $this->belongsTo(FeeSetup::class);
    }
}
