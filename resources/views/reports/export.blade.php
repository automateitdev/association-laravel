{{--
    The PDF body for any report.

    ONE TEMPLATE FOR EVERY REPORT, because a report that looks different
    depending on which menu item produced it is harder to read, not richer. The
    Report object carries its own columns, so this needs no knowledge of what it
    is printing.

    FILTERS ARE PRINTED, NOT ASSUMED. A printed report with no as-at date or
    date range on it cannot be checked, filed or disputed later - the reader has
    no way to know what question it answered. That is worth the two lines it
    costs.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $report->title }}</title>
    <style>
        /*
            NO font-family HERE, AND THAT IS DELIBERATE.

            Member names are Bengali, and Bengali is the whole reason this
            template does not name a font. mPDF is configured with
            autoScriptToLang + autoLangToFont, which detects the script of each
            run of text and selects a font that actually covers it. Naming a
            family here overrides that and puts the Bengali names back into a
            font that has no glyphs for them.

            This was learned the hard way. The first version rendered through
            dompdf with `font-family: 'DejaVu Sans'`, on the stated grounds
            that DejaVu carries Bengali. It does not - DejaVu has zero glyphs
            in the Bengali block (U+0980-09FF), and zero in Devanagari. Every
            Bengali member name came out as empty boxes while the numbers and
            the Latin names looked perfect, so the report generated
            successfully, looked plausible, and was unreadable for most of an
            association's members.
        */

        @page { margin: 18mm 12mm 16mm 12mm; }

        body { font-size: 9pt; color: #1b1a16; margin: 0; }

        .head { border-bottom: 1.5px solid #6b6550; padding-bottom: 6px; margin-bottom: 10px; }
        .association { font-size: 13pt; font-weight: bold; }
        .title { font-size: 10.5pt; margin-top: 2px; }
        .filters { font-size: 8pt; color: #55503f; margin-top: 4px; }

        table { width: 100%; border-collapse: collapse; }

        th {
            background: #efebdd;
            border-bottom: 1px solid #b9b29a;
            padding: 5px 6px;
            font-size: 8.5pt;
            text-align: left;
        }

        td { padding: 4px 6px; border-bottom: 0.5px solid #ddd8c6; }

        .num { text-align: right; }

        /* Repeated on every page: a table whose header only appears on page one
           is unreadable from page two onward. */
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }

        tfoot td {
            font-weight: bold;
            border-top: 1.2px solid #6b6550;
            border-bottom: none;
            padding-top: 6px;
        }

        .foot {
            position: fixed;
            bottom: -10mm;
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
        <div class="title">{{ $report->title }}</div>

        @if (! empty($report->filters))
            <div class="filters">
                @foreach ($report->filters as $label => $value)
                    {{ $label }}: {{ $value }}@if (! $loop->last) &nbsp;·&nbsp; @endif
                @endforeach
            </div>
        @endif
    </div>

    <table>
        <thead>
            <tr>
                @foreach ($report->columns as $column)
                    <th @class(['num' => $column->isNumeric()])>
                        {{ $column->label }}@if ($column->type === 'money') ({{ $report->currency }}) @endif
                    </th>
                @endforeach
            </tr>
        </thead>

        <tbody>
            @forelse ($report->rows as $row)
                <tr>
                    @foreach ($report->columns as $column)
                        <td @class(['num' => $column->isNumeric()])>{{ $row[$column->key] ?? '' }}</td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td colspan="{{ count($report->columns) }}">Nothing to report for these filters.</td>
                </tr>
            @endforelse
        </tbody>

        @if ($report->hasTotals())
            {{--
                The server's own totals, printed as given. Not re-added here:
                a PDF that disagrees with the screen it was generated from is
                worse than one with no totals at all.
            --}}
            <tfoot>
                <tr>
                    @foreach ($report->columns as $column)
                        <td @class(['num' => $column->isNumeric()])>
                            {{ $column->total ?? ($loop->first ? 'Total' : '') }}
                        </td>
                    @endforeach
                </tr>
            </tfoot>
        @endif
    </table>

    <div class="foot">Generated {{ $generatedAt }}</div>
</body>
</html>
