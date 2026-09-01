<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Every inbound callback and verification response, raw, recorded BEFORE it is
 * acted on (FR-PAY-9).
 *
 * This is the evidence trail for the conversation nobody wants to have: the
 * member says they paid, the gateway says something else, and the association
 * needs to know what actually arrived and when. Recorded even when the
 * signature fails - a forged callback is worth a record too.
 */
class GatewayEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'provider', 'event_type', 'reference', 'payload',
        'payment_info_id', 'received_at', 'processed_at', 'processing_error',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(PaymentInfo::class, 'payment_info_id');
    }
}
