<?php

declare(strict_types=1);

namespace App\Reports;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * What every downloadable listing needs, in one place.
 *
 * This began as private helpers on ReportController. Then the members list, the
 * approvals queue and the fee heads all became downloadable too, and four
 * copies of "validate the format, name the file, refuse if it is enormous" is
 * four chances for them to disagree about what a filename looks like or when a
 * report is too big.
 *
 * A trait rather than a base controller because these controllers already
 * extend the framework's, and the shared thing here is a handful of mechanics -
 * not a kind of controller.
 */
trait ExportsListings
{
    /**
     * The point at which a listing stops being a request and becomes a job.
     *
     * FR-REP-10 requires asynchronous generation over large ranges, delivered
     * by notification. That is NOT BUILT. What is built is this limit, so the
     * failure is an explanation rather than a timeout: at 5,000 rows a
     * synchronous PDF is already tens of seconds of mPDF, and a phone client on
     * a mobile connection will have given up long before the server does.
     */
    private const MAX_SYNCHRONOUS_ROWS = 5000;

    private function exportFormat(Request $request): string
    {
        $validated = $request->validate([
            'format' => ['required', Rule::in(ReportExporter::FORMATS)],
        ]);

        return $validated['format'];
    }

    private function sendExport(Report $report, string $format): Response
    {
        return app(ReportExporter::class)->download(
            $report,
            $format,
            $report->filename((string) tenant()->getKey(), now()->toDateString()),
        );
    }

    /**
     * @param  list<array<string, string|int|null>>  $rows
     */
    private function rejectIfTooLarge(array $rows): ?JsonResponse
    {
        if (count($rows) <= self::MAX_SYNCHRONOUS_ROWS) {
            return null;
        }

        return response()->json([
            'error' => [
                'code' => 'REPORT_TOO_LARGE',
                'message' => 'This is too large to download directly. Narrow the filters and try again.',
                'details' => [
                    'rows' => count($rows),
                    'max_rows' => self::MAX_SYNCHRONOUS_ROWS,
                ],
            ],
        ], 422);
    }

    private function associationName(): string
    {
        return (string) (tenant()->name ?? tenant()->getKey());
    }

    private function currency(): string
    {
        return (string) (tenant()->currency ?? 'BDT');
    }

    /**
     * `2026-01-01 to 2026-09-03`, or an honest word when a bound is missing.
     *
     * Printed on the PDF. A listing with no period written on it cannot be
     * checked, filed or disputed later - the reader has no way to know what
     * question it answered.
     */
    private function describePeriod(?string $from, ?string $to, string $unbounded = 'All time'): string
    {
        return match (true) {
            $from !== null && $to !== null => $from.' to '.$to,
            $from !== null => 'From '.$from,
            $to !== null => 'Up to '.$to,
            default => $unbounded,
        };
    }

    /**
     * Money leaves this API as a string with two decimals, never a JSON number
     * - a JSON number is a float, and floats do not reconcile.
     */
    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    /**
     * A validated `sort` / `direction` pair, or null for the query's own order.
     *
     * WHY SORTING IS A SERVER CONCERN FOR A PAGINATED LISTING
     * ------------------------------------------------------
     * The reports load every row, so the table can sort them in the browser and
     * be right. The members list and the approvals queue are paginated, and
     * sorting one PAGE of a paginated listing is not sorting the listing - it
     * reorders 25 rows out of 300 and presents the result as if it were the
     * top of the list. Either the server sorts, or the control is a lie.
     *
     * @param  array<string, string>  $sortable  column key => SQL column
     * @return array{column: string, direction: string}|null
     */
    private function sortFrom(Request $request, array $sortable): ?array
    {
        $validated = $request->validate([
            'sort' => ['nullable', Rule::in(array_keys($sortable))],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);

        if (($validated['sort'] ?? null) === null) {
            return null;
        }

        return [
            'column' => $sortable[$validated['sort']],
            'direction' => $validated['direction'] ?? 'asc',
        ];
    }
}
