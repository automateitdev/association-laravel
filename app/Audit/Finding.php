<?php

declare(strict_types=1);

namespace App\Audit;

/**
 * One thing the audit found wrong.
 *
 * Deliberately flat and printable. A finding that cannot be read off a sheet of
 * paper by whoever has to fix it is not much use, and the legacy audit's habit
 * of returning nested arrays keyed by check name meant every consumer wrote its
 * own flattening.
 */
final readonly class Finding
{
    public const SEVERITY_HIGH = 'high';
    public const SEVERITY_MEDIUM = 'medium';

    public function __construct(
        /** Machine-readable check id, e.g. `TOTAL_MISMATCH`. */
        public string $check,
        public string $severity,
        /**
         * What the finding is about, in the association's own vocabulary -
         * an invoice number, a membership number. Not a primary key: staff
         * cannot look up row 3,544.
         */
        public string $subject,
        /** One sentence saying what is wrong, with the figures in it. */
        public string $detail,
        /** The row to go and look at, for whoever is fixing it in SQL. */
        public ?int $id = null,
    ) {
    }

    /** @return array<string, string|int|null> */
    public function toArray(): array
    {
        return [
            'check' => $this->check,
            'severity' => $this->severity,
            'subject' => $this->subject,
            'detail' => $this->detail,
            'id' => $this->id,
        ];
    }
}
