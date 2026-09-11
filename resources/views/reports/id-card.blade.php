{{--
    Membership ID cards, printed several to a sheet (legacy `id-card`).

    EIGHT TO A PAGE, in two columns, because an office printing forty cards
    wants forty cards and not forty pages. The legacy's own template half
    attempts this - it has a `$key % 2` test with an empty body - and then
    prints one per row anyway.

    NO font-family, deliberately: member names are Bengali and mPDF selects the
    font per script. The legacy renders this through dompdf, which has no
    Bengali glyphs at all, so those names printed as empty boxes on the cards
    handed to the members they belong to.

    EVERYTHING ABOUT THE ASSOCIATION IS A SETTING. The legacy hardcodes the
    name, the registration number and date, the website and a return address in
    Ibrahimpur - which a second association would hand to its own members.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Membership cards</title>
    <style>
        @page { margin: 10mm; }

        body { font-size: 8pt; color: #1b1a16; margin: 0; }

        table.sheet { width: 100%; border-collapse: separate; border-spacing: 4mm 4mm; }
        table.sheet td { width: 50%; vertical-align: top; }

        /* 86 x 54 mm is a bank card, which is the size a wallet expects. */
        .card {
            border: 0.8px solid #6b6550;
            border-radius: 2mm;
            height: 54mm;
            padding: 3mm;
            position: relative;
        }

        .society { font-size: 8.5pt; font-weight: bold; line-height: 1.25; }
        .registration { font-size: 6.5pt; color: #55503f; margin-top: 1px; }

        .kind {
            position: absolute;
            top: 3mm;
            right: 3mm;
            font-size: 6.5pt;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            color: #55503f;
        }

        .who { margin-top: 2.5mm; }
        .who td { vertical-align: top; }

        .photo {
            width: 17mm;
            height: 21mm;
            border: 0.6px solid #b9b29a;
            text-align: center;
            overflow: hidden;
        }
        .photo img { width: 17mm; }
        .photo .missing { font-size: 5.5pt; color: #6b6550; display: block; padding-top: 8mm; }

        .name { font-size: 10pt; font-weight: bold; }
        .field { font-size: 7.5pt; margin-top: 0.8mm; }
        .field span { color: #55503f; }

        .foot {
            position: absolute;
            bottom: 2.5mm;
            left: 3mm;
            right: 3mm;
            font-size: 6pt;
            color: #55503f;
            border-top: 0.5px solid #ddd8c6;
            padding-top: 1mm;
        }
    </style>
</head>
<body>
    <table class="sheet">
        @foreach (array_chunk($members, 2) as $row)
            <tr>
                @foreach ($row as $entry)
                    @php
                        $member = $entry['record'];
                        $info = $member->associatorInfo;
                    @endphp

                    <td>
                        <div class="card">
                            <div class="kind">Member</div>

                            <div class="society">{{ $society['name'] }}</div>
                            <div class="registration">
                                @if ($society['registration_no'])
                                    Registration no. {{ $society['registration_no'] }}
                                    @if ($society['registered_on'])
                                        · {{ $society['registered_on'] }}
                                    @endif
                                @endif
                            </div>

                            <table class="who">
                                <tr>
                                    <td class="photo">
                                        @if ($entry['photo']['uri'])
                                            <img src="{{ $entry['photo']['uri'] }}" alt="">
                                        @else
                                            {{-- Said, not left blank: a card with a
                                                 hole in it looks like a printing
                                                 fault rather than a member the
                                                 office has no photograph of. --}}
                                            <span class="missing">No photo</span>
                                        @endif
                                    </td>
                                    <td style="padding-left:2.5mm">
                                        <div class="name">{{ $member->name }}</div>

                                        <div class="field">
                                            <span>Member no.</span>
                                            {{ $info?->membership_no ?: '—' }}
                                        </div>

                                        @if ($member->bcs_batch || $info?->bcs_batch)
                                            <div class="field">
                                                <span>BCS</span>
                                                {{ $member->bcs_batch ?: $info?->bcs_batch }}
                                            </div>
                                        @endif

                                        <div class="field">
                                            <span>Mobile</span>
                                            {{ $member->mobile ?: '—' }}{{ $member->country_code && $member->country_code !== 'BD' ? ' ('.$member->country_code.')' : '' }}
                                        </div>
                                    </td>
                                </tr>
                            </table>

                            <div class="foot">
                                This card is the property of {{ $society['name'] }}.
                                @if ($society['address'])
                                    If found, please return it to {{ $society['address'] }}.
                                @endif
                                @if ($society['website'])
                                    · {{ $society['website'] }}
                                @endif
                            </div>
                        </div>
                    </td>
                @endforeach

                {{-- An odd last row keeps its column width rather than
                     stretching the single card across the page. --}}
                @if (count($row) === 1)
                    <td></td>
                @endif
            </tr>
        @endforeach
    </table>
</body>
</html>
