<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Somebody who signs the association's documents (legacy `signatures`).
 *
 * See the migration for what this replaces. The short version: the legacy
 * matches a free-text title against two hardcoded strings, holds no name at
 * all, and has 0 rows in production - so no certificate that system produced
 * has ever been signed.
 */
class Signatory extends Model
{
    /**
     * The roles a cooperative society's documents are signed by, and what to
     * print under the line.
     *
     * @var array<string, string>
     */
    public const ROLES = [
        'chairman' => 'Chairman',
        'secretary' => 'Secretary',
        'treasurer' => 'Treasurer',
    ];

    protected $fillable = ['role', 'name'];

    /**
     * The signature image, through the same machinery as every other file.
     *
     * `documents` carries the disk, the mime and the uploader, and already
     * knows how to read bytes back from a bucket that must never be public.
     */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function label(): string
    {
        return self::ROLES[$this->role] ?? ucfirst($this->role);
    }
}
