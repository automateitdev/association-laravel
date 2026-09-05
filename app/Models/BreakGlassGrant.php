<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * One operator's time-boxed permission to read one area of one association
 * (FR-SEC-6, NFR-SEC-6).
 *
 * CENTRAL CONNECTION, PINNED. Without the trait this model would follow the
 * default connection - which is repointed at whichever association is currently
 * initialised - so the grant authorising a read would be looked up inside the
 * database it is authorising a read of. It would find nothing, and the failure
 * would look like a missing grant rather than a wiring bug.
 *
 * The interesting method is `isLive()`. Everything else is bookkeeping.
 */
class BreakGlassGrant extends Model
{
    use CentralConnection;

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_DENIED = 'denied';
    public const STATUS_REVOKED = 'revoked';

    /** The areas the console can actually show. See the migration. */
    public const SCOPES = ['members', 'payments'];

    /**
     * What an operator may ask for, in minutes.
     *
     * A bounded list rather than a free number. "How long do you need?" answered
     * into an empty box gets 480 from somebody who does not want to be
     * interrupted, and a grant long enough to forget about is a grant that
     * outlives its reason.
     */
    public const DURATIONS = [15, 30, 60, 120];

    protected $fillable = [
        'tenant_id',
        'scope',
        'reason',
        'requested_by',
        'requested_by_email',
        'duration_minutes',
        'status',
        'decided_by',
        'decided_by_email',
        'decided_at',
        'decision_note',
        'expires_at',
        'notified_at',
        'notified_to',
        'notify_error',
        'reads',
        'last_read_at',
    ];

    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
            'expires_at' => 'datetime',
            'notified_at' => 'datetime',
            'last_read_at' => 'datetime',
            'notified_to' => 'array',
            'duration_minutes' => 'integer',
            'reads' => 'integer',
        ];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(Operator::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(Operator::class, 'decided_by');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /**
     * Whether this grant opens anything RIGHT NOW.
     *
     * Three conditions, and all three are load-bearing:
     *
     *   approved  - a second operator said so;
     *   notified  - the association's superadmins have actually been told,
     *               which is the control this whole feature rests on;
     *   unexpired - asked of the clock, on every read, so no scheduled job
     *               stands between a grant and its ending.
     *
     * Called on every request that reads tenant data. Nothing else decides.
     */
    public function isLive(): bool
    {
        return $this->status === self::STATUS_APPROVED
            && $this->notified_at !== null
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }

    /**
     * Approved, told, and simply out of time.
     *
     * Distinguished from `isLive()` because the console says so in words: an
     * operator whose access stopped working is owed the difference between
     * "your hour is up" and "this was never approved".
     */
    public function hasExpired(): bool
    {
        return $this->status === self::STATUS_APPROVED
            && $this->expires_at !== null
            && $this->expires_at->isPast();
    }

    /**
     * Approved but not yet usable, because nobody has been told.
     *
     * Its own state rather than a variety of failure: the notification is
     * retryable, and an operator staring at a grant that says "approved" and
     * refuses to open anything needs to be told which half is missing.
     */
    public function awaitingNotification(): bool
    {
        return $this->status === self::STATUS_APPROVED && $this->notified_at === null;
    }

    /** For the console, in one word. */
    public function state(): string
    {
        return match (true) {
            $this->status === self::STATUS_PENDING => 'awaiting approval',
            $this->status === self::STATUS_DENIED => 'denied',
            $this->status === self::STATUS_REVOKED => 'revoked',
            $this->awaitingNotification() => 'approved, not yet notified',
            $this->hasExpired() => 'expired',
            default => 'live',
        };
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_APPROVED)
            ->whereNotNull('notified_at')
            ->where('expires_at', '>', now());
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_APPROVED]);
    }
}
