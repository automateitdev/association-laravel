<?php

declare(strict_types=1);

namespace App\Reports;

use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A report as a downloadable file (FR-REP-7).
 *
 * THE RULE THAT SHAPES ALL THREE FORMATS
 * --------------------------------------
 * "Numeric exports MUST be plain numbers without currency suffixes, so
 * spreadsheets can sum them."
 *
 * The legacy reports wrote `৳1,000.00` into the cells, which Excel stores as
 * TEXT. Every column of a downloaded report was therefore unsummable, and the
 * one thing a person downloads a report to do is add it up. That is the defect
 * this class exists to not repeat, and it is why the currency is named once in
 * the column header and never again in a cell.
 *
 * THE APP DOES NOT DO MONEY ARITHMETIC - AND AN EXPORT IS THE ONE BOUNDARY
 * WHERE THAT CHANGES HANDS
 * ------------------------------------------------------------------------
 * Money is carried as a decimal STRING everywhere in this system precisely so
 * that no float ever rounds a member's balance. A spreadsheet cell cannot hold
 * that; xlsx numbers are IEEE doubles. So the conversion happens here, once, at
 * the boundary, and deliberately:
 *
 *   - the ROWS become numbers, because their whole purpose is to be summed by
 *     the person who downloaded them;
 *   - the TOTALS row is still the SERVER'S figure, written as a literal, never
 *     a `=SUM()` formula. The association's answer is the one the server
 *     computed with bcmath - not one Excel re-derives from rounded cells, and
 *     not one that silently changes when a row is filtered out.
 */
class ReportExporter
{
    public const FORMATS = ['csv', 'xlsx', 'pdf'];

    public function download(Report $report, string $format, string $filename): Response
    {
        return match ($format) {
            'csv' => $this->csv($report, $filename),
            'xlsx' => $this->xlsx($report, $filename),
            'pdf' => $this->pdf($report, $filename),
            default => throw new \InvalidArgumentException("Unsupported export format [{$format}]."),
        };
    }

    /**
     * CSV, streamed.
     *
     * Streamed rather than built in memory because this is the format someone
     * reaches for when they want EVERYTHING, and a report is not paginated -
     * see the note on FR-REP-10. Holding 5,000 rows as one string to send it is
     * avoidable, so it is avoided.
     */
    private function csv(Report $report, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($report) {
            $handle = fopen('php://output', 'wb');

            /*
             * A UTF-8 BOM, and it is not optional here.
             *
             * Member names are Bengali. Excel on Windows opens a BOM-less UTF-8
             * CSV as the system codepage and renders every one of them as
             * mojibake - the file is correct and unreadable at the same time,
             * which is the worst way for this to fail.
             */
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, array_map(
                fn (Column $c) => $this->headerLabel($c, $report),
                $report->columns,
            ));

            foreach ($report->rows as $row) {
                fputcsv($handle, array_map(
                    fn (Column $c) => $this->cell($row[$c->key] ?? null, $c),
                    $report->columns,
                ));
            }

            if ($report->hasTotals()) {
                fputcsv($handle, $this->totalsRow($report));
            }

            fclose($handle);
        }, $filename.'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function xlsx(Report $report, string $filename): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($this->sheetTitle($report->title));

        $lastColumn = 0;
        foreach ($report->columns as $index => $column) {
            $lastColumn = $index + 1;
            $sheet->setCellValue([$lastColumn, 1], $this->headerLabel($column, $report));
        }

        $rowNumber = 1;
        foreach ($report->rows as $row) {
            $rowNumber++;

            foreach ($report->columns as $index => $column) {
                $this->writeCell($sheet, $index + 1, $rowNumber, $row[$column->key] ?? null, $column);
            }
        }

        if ($report->hasTotals()) {
            $rowNumber++;

            foreach ($report->columns as $index => $column) {
                $position = $index + 1;

                if ($column->total !== null) {
                    $this->writeCell($sheet, $position, $rowNumber, $column->total, $column);
                    continue;
                }

                // The label goes in the first column, so the row reads as a
                // total rather than as one more member.
                $sheet->setCellValue([$position, $rowNumber], $index === 0 ? 'Total' : '');
            }

            $sheet->getStyle([1, $rowNumber, $lastColumn, $rowNumber])->applyFromArray([
                'font' => ['bold' => true],
                'borders' => ['top' => ['borderStyle' => Border::BORDER_THIN]],
            ]);
        }

        $sheet->getStyle([1, 1, $lastColumn, 1])->applyFromArray([
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'EFEBDD'],
            ],
        ]);

        // The header stays put while someone scrolls 300 members. Without it a
        // long report is a wall of unlabelled numbers.
        $sheet->freezePane([1, 2]);

        foreach ($report->columns as $index => $column) {
            $sheet->getColumnDimensionByColumn($index + 1)->setAutoSize(true);

            if ($column->isNumeric()) {
                $sheet->getStyle([$index + 1, 1, $index + 1, $rowNumber])
                    ->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            }
        }

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $filename.'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * PDF, through mPDF - and the choice of engine is about Bengali.
     *
     * dompdf was tried first and cannot do this. Two separate reasons, either
     * of which is disqualifying for a Bangladeshi cooperative whose members'
     * names are Bengali:
     *
     *   1. No font it ships covers the script. DejaVu Sans, the usual answer
     *      for "a font with everything", has ZERO glyphs in the Bengali block
     *      (U+0980-09FF). Verified against its own metrics, then seen on the
     *      page: every Bengali name rendered as empty boxes beside perfectly
     *      correct numbers.
     *
     *   2. Even given the glyphs it would still be wrong, because dompdf does
     *      no complex-script shaping. Bengali needs conjunct formation and
     *      vowel-sign reordering; drawn one codepoint at a time in logical
     *      order it produces text that is not the name.
     *
     * mPDF implements Indic shaping and ships fonts that cover the script.
     * `autoScriptToLang` and `autoLangToFont` are what make it automatic: mPDF
     * detects the script of each run and picks a font that can render it, so a
     * report with Bengali names and Latin column headings gets both right
     * without the template knowing anything about either.
     */
    private function pdf(Report $report, string $filename): Response
    {
        $html = view('reports.export', [
            'report' => $report,
            'association' => $report->association,
            'generatedAt' => now()->format('j M Y, g:i a'),
        ])->render();

        $mpdf = new Mpdf([
            'mode' => 'utf-8',

            // Landscape because these reports are wide - a member name plus
            // four money columns does not fit a portrait A4 without the
            // numbers wrapping, and a wrapped number is a misread number.
            'format' => 'A4-L',

            'autoScriptToLang' => true,
            'autoLangToFont' => true,

            /*
             * mPDF writes font subsets to disk while it works, and its default
             * temp directory is inside vendor/ - which is not writable on a
             * deployed host and is wiped by `composer install` anyway. Pointing
             * it at storage keeps a generated report from depending on the
             * state of the dependency directory.
             */
            'tempDir' => $this->mpdfTempDir(),
        ]);

        $mpdf->WriteHTML($html);

        return response($mpdf->Output('', Destination::STRING_RETURN), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'.pdf"',
        ]);
    }

    /**
     * A writable scratch directory for mPDF.
     *
     * mPDF creates this itself and clears it up afterwards, so on a healthy
     * host the mkdir here never fires. It exists for the unhealthy one: mPDF
     * throws if it cannot write, and a report that fails at the last step with
     * a filesystem error is a confusing way to learn that storage/ is not
     * writable.
     */
    private function mpdfTempDir(): string
    {
        $path = storage_path('app/mpdf');

        if (! is_dir($path)) {
            mkdir($path, 0775, true);
        }

        return $path;
    }

    /**
     * `Instalments paid (BDT)` - the unit named ONCE, in the header.
     *
     * This is the whole of FR-REP-7's currency rule: the reader still knows
     * what the numbers are, and the cells stay summable.
     */
    private function headerLabel(Column $column, Report $report): string
    {
        return $column->type === Column::TYPE_MONEY
            ? $column->label.' ('.$report->currency.')'
            : $column->label;
    }

    /** A value as it goes into a text-based format. */
    private function cell(mixed $value, Column $column): string
    {
        if ($value === null) {
            return '';
        }

        // Already a plain decimal string from the report - "1000.00". Written
        // through untouched: no thousands separator, no symbol, nothing a
        // spreadsheet would have to parse back out.
        return (string) $value;
    }

    private function writeCell(
        \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
        int $column,
        int $row,
        mixed $value,
        Column $definition,
    ): void {
        if ($value === null || $value === '') {
            $sheet->setCellValue([$column, $row], '');

            return;
        }

        if (! $definition->isNumeric()) {
            /*
             * Written as an explicit STRING.
             *
             * A membership number like "0012" is digits, and letting the writer
             * guess would store it as the number 12 and drop the leading zeros
             * - a member's identifier, quietly corrupted by a spreadsheet.
             */
            $sheet->setCellValueExplicit([$column, $row], (string) $value, DataType::TYPE_STRING);

            return;
        }

        $sheet->setCellValueExplicit([$column, $row], (float) $value, DataType::TYPE_NUMERIC);

        $sheet->getStyle([$column, $row, $column, $row])
            ->getNumberFormat()
            ->setFormatCode($definition->type === Column::TYPE_MONEY ? '#,##0.00' : '#,##0');
    }

    /** @return list<string> */
    private function totalsRow(Report $report): array
    {
        $cells = [];

        foreach ($report->columns as $index => $column) {
            $cells[] = $column->total !== null
                ? $column->total
                : ($index === 0 ? 'Total' : '');
        }

        return $cells;
    }

    /**
     * Excel refuses a sheet name over 31 characters or containing any of
     * : \ / ? * [ ] - and rejects the whole file rather than trimming it.
     */
    private function sheetTitle(string $title): string
    {
        return mb_substr(str_replace([':', '\\', '/', '?', '*', '[', ']'], '-', $title), 0, 31);
    }
}
