<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One identity document belonging to one member or nominee.
 *
 * A ROW, NOT A PATH. The columns the legacy left behind on `members` hold a
 * bare filename, which is enough only while there is exactly one place files
 * can be. Since ADR-0011 there are two - S3 when it is configured, local when
 * it fails - so the disk travels with the file, along with the MIME type a
 * download has to send and the original name a person recognises.
 *
 * `path` is never given to a client. Every read goes through a controller that
 * checks ownership or permission and streams the bytes (NFR-SEC-4), so nothing
 * about where the file lives leaks into a URL somebody could forward.
 */
class Document extends Model
{
    protected $fillable = [
        'documentable_type',
        'documentable_id',
        'slot',
        'status',
        'disk',
        'path',
        'original_name',
        'mime',
        'size',
        'uploaded_by',
        'decision_reason',
        'decided_by',
        'decided_at',
    ];

    /** What the association holds. */
    public const STATUS_LIVE = 'live';

    /** A member's submission, waiting on a decision (FR-MEM-8). */
    public const STATUS_PENDING = 'pending';

    /** Refused. Keeps its metadata so the member can see what happened; the
     *  file itself is deleted, which is why `disk` and `path` are nullable. */
    public const STATUS_REJECTED = 'rejected';

    protected function casts(): array
    {
        return ['size' => 'integer', 'decided_at' => 'datetime'];
    }

    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Null for a document a member uploaded themselves, and for one whose staff
     * account has since been deleted. Neither makes the document less valid.
     */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** Which officer decided, for a document that went through review. */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** A rejected document has metadata and no bytes. */
    public function hasFile(): bool
    {
        return $this->disk !== null && $this->path !== null;
    }
}
