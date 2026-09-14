<?php

declare(strict_types=1);

namespace App\Services\Migration;

/**
 * What the document pass found, copied, and could not find.
 *
 * `missing` is the number that matters and the reason this is a report rather
 * than a count of successes: a run that copies nothing and says "done" and a
 * run that copies everything both finish quietly otherwise.
 */
class DocumentMigrationReport
{
    /** Filenames named by a column - the number that SHOULD arrive. */
    public int $referenced = 0;

    public int $copied = 0;

    /** Named in the database, absent from the filesystem. */
    public int $missing = 0;

    /** Present, but could not be opened - a permissions problem, not a gap. */
    public int $unreadable = 0;

    /** @var array<string, int> */
    public array $copiedBySlot = [];

    /** @var array<string, int> */
    public array $missingBySlot = [];

    public function complete(): bool
    {
        return $this->referenced > 0 && $this->missing === 0 && $this->unreadable === 0;
    }
}
