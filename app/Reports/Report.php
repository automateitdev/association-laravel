<?php

declare(strict_types=1);

namespace App\Reports;

use Illuminate\Support\Str;

/**
 * A report, ready to be rendered - on screen or into a file.
 *
 * WHY THIS EXISTS RATHER THAN THREE EXPORT METHODS
 * ------------------------------------------------
 * FR-REP-8 requires column totals in the on-screen view AND in all three export
 * formats. That is only meaningful if they AGREE, and the way to guarantee
 * agreement is for one query to feed all four outputs rather than for the CSV
 * writer to re-run its own version of the report.
 *
 * So the controller builds this once, hands it to the JSON response or to the
 * exporter, and a discrepancy between the screen and the download becomes
 * impossible rather than merely unlikely.
 */
final readonly class Report
{
    public function __construct(
        /** Used for the filename and as the heading in a PDF. */
        public string $title,
        /**
         * Whose report this is.
         *
         * Printed at the head of the PDF. In a system where staff may hold
         * accounts at more than one association, a printed report that does
         * not name the association it came from is a filing error waiting to
         * happen.
         */
        public string $association,
        /** @var list<Column> */
        public array $columns,
        /** @var list<array<string, string|int|null>> */
        public array $rows,
        /**
         * What was asked for - the date range, the status filter, the as-at
         * date. Printed on the PDF, because a report without its own filters
         * written on it cannot be checked later by whoever is holding the
         * paper.
         *
         * @var array<string, string>
         */
        public array $filters = [],
        /** Shown in a header so a number is never read without its unit. */
        public string $currency = 'BDT',
    ) {
    }

    /**
     * `demo-one-memberwise-paid-2026-09-03`, without the extension.
     *
     * The association is in the name on purpose: staff who work across more
     * than one download the same report from each, and two files called
     * `memberwise-paid.csv` in one folder is a mistake waiting to be made.
     */
    public function filename(string $tenant, string $date): string
    {
        return Str::slug($tenant.'-'.$this->title.'-'.$date);
    }

    /** @return list<Column> */
    public function totalledColumns(): array
    {
        return array_values(array_filter($this->columns, fn (Column $c) => $c->total !== null));
    }

    public function hasTotals(): bool
    {
        return $this->totalledColumns() !== [];
    }
}
