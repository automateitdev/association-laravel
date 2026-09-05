<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Shares moving from one member to another (FR-SHR-3).
 *
 * THE RECORD IS THE POINT. A transfer changes two members' balances, and the
 * balances themselves keep no history - `member_share_balances` holds a number,
 * not a story. Without this row, "why do I have three fewer shares than last
 * year" has no answer, and the share-balance audit (FR-REP-6) cannot tell a
 * legitimate transfer from the drift that D-19 caused.
 *
 * Kept per fee head, because shares are: a member holding ten Monthly Savings
 * shares and two of something else has two balances, and a transfer moves one
 * of them.
 */
class ShareTransfer extends Model
{
    protected $fillable = [
        'seller_id',
        'buyer_id',
        'fee_setup_id',
        'shares',
        'amount',
        'transferred_on',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'shares' => 'integer',

            // What changed hands between the two members, if anything. It is
            // not the association's money and never touches the ledger - the
            // association is recording a transfer, not taking a payment.
            'amount' => 'decimal:2',
            'transferred_on' => 'date',
        ];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'seller_id');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'buyer_id');
    }

    public function feeSetup(): BelongsTo
    {
        return $this->belongsTo(FeeSetup::class);
    }
}
