<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One point at which an unpaid assignment accrues a further fine increment.
 *
 * The series is the source of truth for the fine, which is what makes accrual
 * idempotent: the fine is recomputed as (elapsed dates x rate), never
 * incremented. Running the job twice in a day changes nothing (FR-FINE-3).
 */
class FineDate extends Model
{
    public const STATUS_INCOMPLETE = 'incomplete';
    public const STATUS_COMPLETE = 'complete';

    protected $fillable = [
        'fee_assign_id',
        'fine_date',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'fine_date' => 'date',
        ];
    }

    public function feeAssign(): BelongsTo
    {
        return $this->belongsTo(FeeAssign::class);
    }
}
