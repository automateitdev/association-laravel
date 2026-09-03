<?php

declare(strict_types=1);

namespace App\Reports;

use App\Models\Tenant\PaymentInfo;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Symfony\Component\HttpFoundation\Response;

/**
 * A payment receipt as a PDF (FR-PAY-14).
 *
 * SEPARATE FROM ReportExporter, and that is a judgement rather than an
 * oversight. That class turns a Report - columns, rows, column totals - into
 * three file formats. A receipt is one format, has a header block rather than
 * columns, and its "totals" are two figures a person signs against. Pushing it
 * through the Report shape would have produced a grid with an association name
 * bolted on top, and would have made every future change to reports a risk to
 * receipts.
 *
 * What the two DO share is mPDF and the reason for it: dompdf renders no
 * Bengali - the font it ships has zero glyphs in U+0980-09FF and it performs no
 * complex-script shaping - so a receipt made with it would carry the member's
 * name as a row of empty boxes. See ReportExporter for the measurements.
 */
class InvoiceRenderer
{
    public function render(PaymentInfo $payment, string $association, string $currency): Response
    {
        $payment->loadMissing(['member.associatorInfo', 'items.feeAssign.feeSetup', 'ledger']);

        $lines = $payment->items->map(function ($item) {
            $instalment = number_format((float) $item->amount, 2, '.', '');
            $fine = number_format((float) $item->fine_amount, 2, '.', '');

            return [
                'fee_head' => $item->feeAssign?->feeSetup?->fee_head ?? 'Fee',
                'period' => $item->period,
                'instalment' => $instalment,
                'fine' => $fine,

                /*
                 * Added with bcmath, on the server, per line.
                 *
                 * This IS money arithmetic, and it belongs here rather than in
                 * the app for exactly the usual reason - but note it is a line
                 * total only. The document's totals are the payment's own
                 * stored figures, not a sum of these lines: if the two ever
                 * disagreed, the record is the truth and the receipt must show
                 * the record.
                 */
                'total' => bcadd($instalment, $fine, 2),
            ];
        })->all();

        $html = view('reports.invoice', [
            'payment' => $payment,
            'association' => $association,
            'currency' => $currency,
            'lines' => $lines,
            'membershipNo' => $payment->member?->associatorInfo?->membership_no,
            'ledger' => $payment->ledger?->name,
            'paidOn' => ($payment->payment_date ?? $payment->created_at)?->format('j M Y') ?? '',
            'generatedAt' => now()->format('j M Y, g:i a'),
        ])->render();

        $mpdf = new Mpdf([
            'mode' => 'utf-8',

            // Portrait: a receipt is a document somebody files or hands over,
            // and five columns fit an A4 page without help.
            'format' => 'A4',

            // What makes Bengali member names render at all.
            'autoScriptToLang' => true,
            'autoLangToFont' => true,

            'tempDir' => $this->tempDir(),
        ]);

        $mpdf->WriteHTML($html);

        return response($mpdf->Output('', Destination::STRING_RETURN), 200, [
            'Content-Type' => 'application/pdf',
            /*
             * `inline`, not `attachment`, and this differs from the report
             * exports on purpose. A receipt is looked at - and often printed -
             * far more often than it is filed, so it opens in the viewer rather
             * than landing silently in a downloads folder.
             */
            'Content-Disposition' => 'inline; filename="'.$payment->invoice_no.'.pdf"',
        ]);
    }

    /**
     * A writable scratch directory for mPDF.
     *
     * mPDF creates and clears this itself, so on a healthy host the mkdir never
     * fires. It exists for the unhealthy one: mPDF throws if it cannot write,
     * and a receipt that fails at the last step with a filesystem error is a
     * confusing way to learn that storage/ is not writable.
     */
    private function tempDir(): string
    {
        $path = storage_path('app/mpdf');

        if (! is_dir($path)) {
            mkdir($path, 0775, true);
        }

        return $path;
    }
}
