{{--
    Share certificates, one per page (legacy `certificate`).

    NO PRONOUNS. The legacy writes "{Mr.|Mrs.|-} {name} ... {he|sh} is entitled
    ... {His|Her|-}" from a three-way ternary on gender - which prints `sh` for
    every woman, because the branch was typed short, and a bare dash for anybody
    recorded as `other`: "This is to certify - Rahim Uddin ... - is entitled".
    Writing round it is correct for everybody and shorter than getting it right
    three ways.

    NO font-family, deliberately - the same reasoning as every other template
    here. Member names are Bengali and mPDF selects the font per script; naming
    a family overrides that. The legacy renders this through dompdf, which has
    zero glyphs in U+0980-09FF, so every Bengali name on every certificate it
    produced came out as empty boxes.

    A BLANK IS A BLANK. Where the association has not filled a setting in, the
    line is empty rather than carrying somebody else's registration number.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Share certificate</title>
    <style>
        @page { margin: 12mm; }

        body { font-size: 11pt; color: #1b1a16; margin: 0; }

        .sheet {
            border: 3px double #6b6550;
            padding: 10mm 12mm;
            height: 168mm;
            position: relative;
        }

        .society { font-size: 17pt; font-weight: bold; text-align: center; }
        .registration { text-align: center; font-size: 9.5pt; color: #55503f; margin-top: 3px; }

        .title {
            text-align: center;
            font-size: 13pt;
            font-weight: bold;
            letter-spacing: 1px;
            margin: 8mm 0 2mm 0;
            text-transform: uppercase;
        }
        .subtitle { text-align: center; font-size: 9pt; color: #55503f; }

        .body { margin-top: 7mm; line-height: 1.9; font-size: 11.5pt; }

        /* A ruled blank where a fact goes, which is what makes it read as a
           certificate rather than a letter. */
        .fact {
            border-bottom: 1px dotted #6b6550;
            padding: 0 4px;
            font-weight: bold;
        }

        .signatures { position: absolute; bottom: 6mm; left: 12mm; right: 12mm; }
        .signatures table { width: 100%; }
        .signatures td { width: 50%; vertical-align: bottom; font-size: 9.5pt; }
        .signatures img { height: 14mm; display: block; }
        .signatures .line {
            border-top: 0.8px solid #6b6550;
            padding-top: 3px;
            width: 60mm;
        }
        .signatures .right { text-align: right; }
        .signatures .right .line { margin-left: auto; }

        .serial { font-size: 8pt; color: #6b6550; }
    </style>
</head>
<body>
    @foreach ($members as $index => $entry)
        @php
            $member = $entry['record'];
            $info = $member->associatorInfo;
        @endphp

        <div class="sheet">
            <div class="society">{{ $society['name'] }}</div>

            <div class="registration">
                @if ($society['registration_no'])
                    Registration no. {{ $society['registration_no'] }}
                    @if ($society['registered_on'])
                        · {{ $society['registered_on'] }}
                    @endif
                @endif
                @if ($society['address'])
                    <br>{{ $society['address'] }}
                @endif
                @if ($society['email'])
                    <br>{{ $society['email'] }}
                @endif
            </div>

            <div class="title">Share certificate</div>
            <div class="subtitle">
                Registered under the Co-operative Society Act 2001 (amended 2002 and 2013)
                @if ($society['authorised_capital'] && $society['total_shares'])
                    <br>Authorised capital Tk. {{ number_format((float) $society['authorised_capital'], 2) }},
                    divided into {{ number_format((float) $society['total_shares']) }} shares
                @endif
            </div>

            <div class="body">
                This is to certify that
                <span class="fact">{{ $member->name }}</span>,
                child of
                <span class="fact">{{ $member->father_name ?: '—' }}</span>,
                @if ($member->bcs_batch || $info?->bcs_batch)
                    of
                    <span class="fact">{{ $member->bcs_batch ?: $info?->bcs_batch }}</span>,
                @endif
                permanently of
                <span class="fact">{{ $member->permanent_address ?: '—' }}</span>,
                is a member of this society under membership number
                <span class="fact">{{ $info?->membership_no ?: '—' }}</span>
                and holds
                <span class="fact">{{ number_format((int) ($info?->num_or_shares ?? 0)) }}</span>
                {{ (int) ($info?->num_or_shares ?? 0) === 1 ? 'share' : 'shares' }}
                @if ($society['share_value'])
                    of Tk. {{ number_format((float) $society['share_value'], 2) }} each
                @endif
                as per the Co-operative Society Act, its rules and this society's
                registered bye-laws.
            </div>

            <div class="signatures">
                <table>
                    <tr>
                        @foreach ($signatories as $position => $signatory)
                            <td class="{{ $position === 0 ? '' : 'right' }}">
                                @if ($signatory['image'])
                                    <img src="{{ $signatory['image'] }}" alt=""
                                         style="{{ $position === 0 ? '' : 'margin-left:auto' }}">
                                @endif

                                <div class="line">
                                    {{ $signatory['name'] }}<br>{{ $signatory['label'] }}
                                </div>
                            </td>
                        @endforeach

                        {{--
                            An unsigned certificate says so where the signature
                            would be. The legacy prints empty space, which looks
                            like a printing fault rather than a document nobody
                            has authorised - and since `signatures` holds no
                            rows at all, that is every certificate it ever made.
                        --}}
                        @if (count($signatories) === 0)
                            <td colspan="2" style="text-align:center; color:#a0342a">
                                No signatories are recorded for this association,
                                so this certificate is unsigned.
                            </td>
                        @endif
                    </tr>
                </table>

                <div class="serial" style="margin-top:4mm">
                    Issued {{ $generatedAt }}
                </div>
            </div>
        </div>

        @if ($index < count($members) - 1)
            <pagebreak />
        @endif
    @endforeach
</body>
</html>
