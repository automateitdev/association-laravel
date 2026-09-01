<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use RuntimeException;

/**
 * Append-only. A model using this trait can be created and read, never updated
 * or deleted.
 *
 * Used by LedgerTrace (FR-ACC-9) and AuditLog (NFR-SEC-7). A ledger correction is
 * a reversing entry, never an edit; an audit row that can be edited is not an
 * audit row.
 *
 * WHY THIS IS NOT A DATABASE TRIGGER
 * ---------------------------------
 * A BEFORE UPDATE / BEFORE DELETE trigger would be stronger - it would also stop
 * a raw query. But CREATE TRIGGER requires the SUPER privilege while binary
 * logging is enabled, and a tenant's scoped MySQL user deliberately does not
 * have it (ADR-0001 layer 2). Granting SUPER to every tenant user in order to
 * protect one table would dissolve the isolation barrier that layer exists to
 * provide, which is a far worse trade. Managed MySQL commonly withholds SUPER
 * outright, so a trigger would also make the platform unportable.
 *
 * The guard therefore lives here, one layer up, and the gap is stated plainly:
 * a raw `DB::table('ledger_traces')->update(...)` bypasses it. Every write path
 * in this application goes through Eloquent, and ImmutabilityTest proves the
 * guard holds for those. Recorded as DQ-5 in bcs-docs/04-data-model.md.
 */
trait Immutable
{
    public static function bootImmutable(): void
    {
        static::updating(function ($model): never {
            throw new RuntimeException(
                static::class.' is append-only. Post a reversing entry instead of updating it.'
            );
        });

        static::deleting(function ($model): never {
            throw new RuntimeException(
                static::class.' is append-only. Post a reversing entry instead of deleting it.'
            );
        });
    }

    /**
     * Eloquent's UPDATED_AT handling would try to touch a row on save; there is
     * no such thing here.
     */
    public function getUpdatedAtColumn(): ?string
    {
        return null;
    }
}
