{{--
    A payment receipt (FR-PAY-14).

    INSTALMENT AND FINE ARE SEPARATE LINES, which the requirement states and
    which is the whole point. A receipt showing one merged figure leaves the
    member unable to see what they were penalised, and leaves the association
    unable to answer when they ask months later.

    A DOCUMENT, NOT A REPORT. It deliberately does not go through the Report /
    Column machinery: that renders a grid of rows with column totals, and a
    receipt is a header, a short table and a pair of totals a person signs
    against. Forcing it through would have produced a table with an association
    name bolted on top.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $payment->invoice_no }}</title>
    <style>
        /*
            NO font-family, deliberately - the same reasoning as the report
            template. Member names are Bengali, mPDF is configured with
            autoScriptToLang/autoLangToFont, and naming a family here overrides
            the font selection that makes those names render at all.
        */
        @page { margin: 18mm 16mm 20mm 16mm; }

        body { font-size: 10pt; color: #1b1a16; margin: 0; }

        .head { border-bottom: 1.5px solid #6b6550; padding-bottom: 8px; margin-bottom: 14px; }
        .association { font-size: 15pt; font-weight: bold; }
        .doc { font-size: 11pt; margin-top: 2px; }

        .meta { width: 100%; margin-bottom: 16px; }
        .meta td { padding: 2px 0; font-size: 9.5pt; vertical-align: top; }
        .meta .label { color: #55503f; width: 90px; }

        table.lines { width: 100%; border-collapse: collapse; margin-top: 4px; }
        table.lines th {
            background: #efebdd;
            border-bottom: 1px solid #b9b29a;
            padding: 6px;
            font-size: 9pt;
            text-align: left;
        }
        table.lines td { padding: 5px 6px; border-bottom: 0.5px solid #ddd8c6; }
        .num { text-align: right; }

        tfoot td {
            font-weight: bold;
            border-top: 1.2px solid #6b6550;
            border-bottom: none;
            padding-top: 7px;
        }

        .note { margin-top: 18px; font-size: 8.5pt; color: #55503f; }

        .sign {
            margin-top: 34px;
            width: 100%;
        }
        .sign td { font-size: 9pt; color: #55503f; padding-top: 26px; }
        .sign .line { border-top: 0.7px solid #6b6550; width: 170px; padding-top: 4px; }

        .foot {
            position: fixed;
            bottom: -12mm;
            left: 0;
            right: 0;
            font-size: 7.5pt;
            color: #6b6550;
        }
    </style>
</head>
<body>
    <div class="head">
        <div class="association">{{ $association }}</div>
        <div class="doc">Payment receipt</div>
    </div>

    <table class="meta">
        <tr>
            <td class="label">Invoice</td>
            <td><strong>{{ $payment->invoice_no }}</strong></td>
            <td class="label">Date</td>
            <td>{{ $paidOn }}</td>
        </tr>
        <tr>
            <td class="label">Member</td>
            <td>{{ $payment->member->name }}</td>
            <td class="label">Membership no.</td>
            {{-- A dash rather than a blank: a member legitimately exists before
                 the office assigns a number. --}}
            <td>{{ $membershipNo ?: '—' }}</td>
        </tr>
        <tr>
            <td class="label">Method</td>
            <td>{{ ucfirst($payment->payment_type) }}</td>
            <td class="label">Received into</td>
            <td>{{ $ledger ?: '—' }}</td>
        </tr>
    </table>

    <table class="lines">
        <thead>
            <tr>
                <th>Fee head</th>
                <th>Period</th>
                <th class="num">Instalment ({{ $currency }})</th>
                <th class="num">Fine ({{ $currency }})</th>
                <th class="num">Line total ({{ $currency }})</th>
            </tr>
        </thead>

        <tbody>
            @foreach ($lines as $line)
                <tr>
                    <td>{{ $line['fee_head'] }}</td>
                    <td>{{ $line['period'] }}</td>
                    <td class="num">{{ $line['instalment'] }}</td>
                    <td class="num">{{ $line['fine'] }}</td>
                    <td class="num">{{ $line['total'] }}</td>
                </tr>
            @endforeach
        </tbody>

        {{--
            The server's own figures, printed as given. Not re-added here: a
            receipt that disagrees with the payment record it was produced from
            is worse than no receipt.
        --}}
        <tfoot>
            <tr>
                <td colspan="2">Total</td>
                <td class="num">{{ $payment->payable_amount }}</td>
                <td class="num">{{ $payment->fine_amount }}</td>
                <td class="num">{{ $payment->total_amount }}</td>
            </tr>
        </tfoot>
    </table>

    <div class="note">
        Instalments and fines are shown separately and are never added together
        into a single figure. The total above is the sum of both.
    </div>

    <table class="sign">
        <tr>
            <td><div class="line">Received by</div></td>
            <td class="num"><div class="line" style="margin-left:auto">Member</div></td>
        </tr>
    </table>

    <div class="foot">{{ $payment->invoice_no }} · generated {{ $generatedAt }}</div>
</body>
</html>
