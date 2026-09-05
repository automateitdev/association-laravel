<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A manual accounting document (FR-ACC-4).
 *
 * Payments post themselves. A voucher is for everything else an association
 * does with money - paying the electricity bill, recording bank interest,
 * correcting a misposting - and it is the one place where a person, rather than
 * a payment, decides what the ledger says.
 *
 * WHICH IS WHY IT IS DRAFTED AND THEN APPROVED. A draft posts nothing at all;
 * approval is the moment it reaches the accounts. That separation is the whole
 * control: `vouchers.create` and `vouchers.approve` are different permissions
 * so an association can put them in different hands.
 *
 * AN APPROVED VOUCHER IS NEVER EDITED OR DELETED. Its entries are in the
 * ledger, and reports have been read off them. Undoing one means posting its
 * reverse - which is why `ledger_traces.reverses_id` exists - so the correction
 * is itself a dated, attributable act rather than history quietly changing.
 */
class Voucher extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    /**
     * Payment, receipt, journal - the three the legacy system had, and the
     * three an association actually writes. The distinction is for the reader:
     * all of them are debits and credits underneath.
     */
    public const TYPES = ['payment', 'receipt', 'journal'];

    protected $fillable = [
        'voucher_no',
        'type',
        'voucher_date',
        'narration',
        'status',
        'reverses_id',
        'created_by',
        'approved_by',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'voucher_date' => 'date',
            'approved_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(VoucherLine::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** The document this one undoes, when this voucher is a reversal. */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_id');
    }

    /**
     * The reversal that undid this one, if there is one.
     *
     * `hasOne` because there can only ever be one: `reverse()` refuses a second.
     */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_id');
    }

    /** What this voucher put in the ledger, if it was approved. */
    public function traces(): MorphMany
    {
        return $this->morphMany(LedgerTrace::class, 'source');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    /**
     * Whether the person who wrote it also approved it.
     *
     * Not forbidden - a two-person association would be unable to post anything
     * if it were - but shown wherever a voucher is listed, because an approval
     * step somebody performed on their own work is not the control it looks
     * like, and a reviewer is entitled to notice.
     */
    public function selfApproved(): bool
    {
        return $this->isApproved()
            && $this->created_by !== null
            && $this->created_by === $this->approved_by;
    }
}
