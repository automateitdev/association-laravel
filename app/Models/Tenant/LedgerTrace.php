<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One side of a double-entry posting.
 *
 * APPEND-ONLY (FR-ACC-9). A correction is a reversing entry, never an edit or a
 * delete - see the Immutable concern for why this is a model guard rather than a
 * database trigger.
 *
 * Debit and credit are separate columns rather than one signed amount, so an
 * unbalanced document is a simple SUM comparison (FR-ACC-6).
 */
class LedgerTrace extends Model
{
    use Immutable;

    public const UPDATED_AT = null;

    protected $fillable = [
        'ledger_id',
        'debit',
        'credit',
        'source_type',
        'source_id',
        'reference',
        'posted_on',
        'narration',
        'reverses_id',
    ];

    protected function casts(): array
    {
        return [
            'debit' => 'decimal:2',
            'credit' => 'decimal:2',
            'posted_on' => 'date',
        ];
    }

    public function ledger(): BelongsTo
    {
        return $this->belongsTo(Ledger::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_id');
    }
}
