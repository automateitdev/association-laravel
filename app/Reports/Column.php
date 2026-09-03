<?php

declare(strict_types=1);

namespace App\Reports;

/**
 * One column of a report, and what kind of thing it holds.
 *
 * The type is not decoration. It decides three separate things that a plain
 * label could not:
 *
 *   - whether the column carries a total at all (FR-REP-8);
 *   - whether a spreadsheet receives a NUMBER it can sum, or text it cannot
 *     (FR-REP-7);
 *   - which way the column is aligned when a human reads it.
 */
final readonly class Column
{
    public const TYPE_TEXT = 'text';
    public const TYPE_INTEGER = 'integer';
    public const TYPE_MONEY = 'money';

    public function __construct(
        /** The key this column reads from each row. */
        public string $key,
        public string $label,
        public string $type = self::TYPE_TEXT,
        /**
         * The column's total, already computed by the report.
         *
         * Deliberately a value rather than something the exporter derives. A
         * spreadsheet SUM() over the exported rows and the figure the server
         * computed are two different numbers the moment a row is filtered or
         * hidden, and only one of them is the association's answer. The export
         * carries the server's.
         */
        public ?string $total = null,
    ) {
    }

    public function isNumeric(): bool
    {
        return $this->type !== self::TYPE_TEXT;
    }
}
