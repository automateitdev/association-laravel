{{--
    One member's record (legacy `member-pdf/{id}`).

    A DOCUMENT, NOT A REPORT. It does not go through the Report / Column
    machinery for the same reason the receipt does not: that renders a grid of
    rows with column totals, and this is sections of labelled facts about one
    person with nothing summable on the page.

    AN EMPTY FIELD PRINTS A DASH, never a blank cell. A blank reads as a
    rendering fault; a dash says the association does not hold that fact, which
    is information the office acts on.

    NO font-family, deliberately - the same reasoning as the report and receipt
    templates. Member names are Bengali, mPDF is configured with
    autoScriptToLang/autoLangToFont, and naming a family here overrides the font
    selection that makes those names render at all. DejaVu, the obvious guess,
    has zero glyphs in U+0980-09FF.
--}}
@php
    /** A value, or a dash. Dates arrive as Carbon and print as `3 Sep 2026`. */
    $show = function ($value) {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('j M Y');
        }

        $text = trim((string) ($value ?? ''));

        return $text === '' ? '—' : $text;
    };
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Member profile</title>
    <style>
        @page { margin: 16mm 14mm 18mm 14mm; }

        body { font-size: 9.5pt; color: #1b1a16; margin: 0; }

        .head { border-bottom: 1.5px solid #6b6550; padding-bottom: 8px; margin-bottom: 12px; }
        .association { font-size: 15pt; font-weight: bold; }
        .doc { font-size: 11pt; margin-top: 2px; }

        h2 {
            font-size: 10pt;
            background: #efebdd;
            border-bottom: 1px solid #b9b29a;
            padding: 5px 6px;
            margin: 16px 0 0 0;
        }

        table { width: 100%; border-collapse: collapse; }

        /* A page break between a heading and its first row leaves an orphaned
           bar at the foot of a page. */
        h2, tr { page-break-inside: avoid; }
        h2 { page-break-after: avoid; }

        table.fields td {
            padding: 4px 6px;
            border-bottom: 0.5px solid #ddd8c6;
            vertical-align: top;
        }
        table.fields td.label { color: #55503f; width: 26%; }

        .photo {
            width: 30mm;
            border: 0.7px solid #b9b29a;
            padding: 3px;
            text-align: center;
        }
        .photo img { width: 28mm; }
        .photo .missing {
            font-size: 7.5pt;
            color: #6b6550;
            padding: 14mm 2px;
            display: block;
        }

        /*
            The status, and the only colour on the page.

            Suspended is the one a reader must not miss - a profile that looks
            like every other profile is how a suspended membership gets treated
            as current - so it is the one that is loud.
        */
        .status { font-weight: bold; }
        .status-suspended { color: #a0342a; }
        .status-inactive { color: #8a6d1f; }

        table.slots th {
            background: #efebdd;
            border-bottom: 1px solid #b9b29a;
            padding: 5px 6px;
            font-size: 8.5pt;
            text-align: left;
        }
        table.slots td { padding: 4px 6px; border-bottom: 0.5px solid #ddd8c6; }
        thead { display: table-header-group; }

        .note { margin-top: 16px; font-size: 8pt; color: #55503f; }

        .sign { margin-top: 26px; width: 100%; }
        .sign td { font-size: 9pt; color: #55503f; padding-top: 24px; }
        .sign .line { border-top: 0.7px solid #6b6550; width: 160px; padding-top: 4px; }

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
        {{--
            NOT "Membership Application". The legacy template is headed that way
            and then fills the form in from the record of an approved member,
            office-use box included. An association that files both will not be
            able to tell them apart later.
        --}}
        <div class="doc">Member profile</div>
    </div>

    <table class="fields">
        <tr>
            <td class="label">Membership no.</td>
            <td><strong>{{ $show($info?->membership_no) }}</strong></td>
            <td class="label">Status</td>
            <td>
                <span class="status status-{{ $member->status }}">{{ ucfirst($member->status) }}</span>
            </td>
        </tr>
        <tr>
            <td class="label">Date of joining the society</td>
            <td>{{ $show($info?->join_date) }}</td>
            <td class="label">Share no.</td>
            <td>{{ $show($info?->share_no) }}</td>
        </tr>
        <tr>
            {{--
                "Shares", not "Number of installments". The legacy prints
                `num_or_shares` under that label, which is a different thing
                entirely and reads on the page as an instalment count.
            --}}
            <td class="label">Shares held</td>
            <td>{{ (int) ($info?->num_or_shares ?? 0) }}</td>
            <td class="label">Introduced by</td>
            <td>
                @if ($introducer === null)
                    —
                @else
                    {{ $introducer['name'] ?: '—' }}
                    @if ($introducer['membership_no'])
                        ({{ $introducer['membership_no'] }})
                    @elseif (! $introducer['is_member'])
                        (not a member)
                    @endif
                @endif
            </td>
        </tr>
    </table>

    <h2>The member</h2>

    <table class="fields">
        <tr>
            <td class="label">Name</td>
            <td><strong>{{ $show($member->name) }}</strong></td>
            <td rowspan="6" class="photo">
                @if ($photo['uri'])
                    <img src="{{ $photo['uri'] }}" alt="">
                @else
                    <span class="missing">{{ $photo['note'] }}</span>
                @endif
            </td>
        </tr>
        <tr>
            <td class="label">Father's name</td>
            <td>{{ $show($member->father_name) }}</td>
        </tr>
        <tr>
            <td class="label">Mother's name</td>
            <td>{{ $show($member->mother_name) }}</td>
        </tr>
        <tr>
            <td class="label">Spouse's name</td>
            <td>{{ $show($member->spouse_name) }}</td>
        </tr>
        <tr>
            <td class="label">Date of birth</td>
            <td>{{ $show($member->birth_date) }}</td>
        </tr>
        <tr>
            <td class="label">Gender</td>
            <td>{{ $member->gender ? ucfirst($member->gender) : '—' }}</td>
        </tr>
        <tr>
            <td class="label">BCS batch</td>
            <td>{{ $show($member->bcs_batch ?: $info?->bcs_batch) }}</td>
            <td class="label">Cadre ID</td>
            <td>{{ $show($member->cadre_id) }}</td>
        </tr>
        <tr>
            <td class="label">Date of joining the cadre</td>
            <td>{{ $show($member->joining_date) }}</td>
            <td class="label">National ID</td>
            <td>{{ $show($member->nid) }}</td>
        </tr>
        <tr>
            <td class="label">Mobile</td>
            {{-- The country only when it is NOT Bangladesh. "(BD)" against
                 three hundred numbers is noise; "(US)" against eight is the
                 thing a reader has to know before dialling. --}}
            <td>
                {{ $show($member->mobile) }}{{ $member->country_code && $member->country_code !== 'BD' ? ' ('.$member->country_code.')' : '' }}
            </td>
            <td class="label">Email</td>
            <td>{{ $show($member->email) }}</td>
        </tr>
        <tr>
            <td class="label">Employer</td>
            <td>{{ $show($info?->company) }}</td>
            <td class="label">Designation</td>
            <td>{{ $show($info?->designation) }}</td>
        </tr>
        <tr>
            <td class="label">Office address</td>
            <td colspan="3">{{ $show($member->office_address) }}</td>
        </tr>
        <tr>
            <td class="label">Present address</td>
            <td colspan="3">{{ $show($member->present_address) }}</td>
        </tr>
        <tr>
            <td class="label">Permanent address</td>
            <td colspan="3">{{ $show($member->permanent_address) }}</td>
        </tr>
        <tr>
            <td class="label">Emergency contact</td>
            <td colspan="3">{{ $show($member->emergency_contact) }}</td>
        </tr>
    </table>

    {{--
        EVERY NOMINEE. The legacy prints one - `$member->nominee` is a hasOne -
        and a profile that silently shows one of three is worse than one that
        shows none, because it looks complete.
    --}}
    <h2>Nominees @if (count($nominees) > 1)({{ count($nominees) }})@endif</h2>

    @forelse ($nominees as $index => $entry)
        @php $nominee = $entry['record']; @endphp

        <table class="fields">
            <tr>
                <td class="label">Name</td>
                <td><strong>{{ $show($nominee->name) }}</strong></td>
                <td rowspan="5" class="photo">
                    @if ($entry['photo']['uri'])
                        <img src="{{ $entry['photo']['uri'] }}" alt="">
                    @else
                        <span class="missing">{{ $entry['photo']['note'] }}</span>
                    @endif
                </td>
            </tr>
            <tr>
                <td class="label">Relation to the member</td>
                <td>{{ $show($nominee->relation) }}</td>
            </tr>
            <tr>
                <td class="label">Father's name</td>
                <td>{{ $show($nominee->father_name) }}</td>
            </tr>
            <tr>
                <td class="label">Mother's name</td>
                <td>{{ $show($nominee->mother_name) }}</td>
            </tr>
            <tr>
                <td class="label">Date of birth</td>
                <td>{{ $show($nominee->birth_date) }}</td>
            </tr>
            <tr>
                <td class="label">Gender</td>
                <td>{{ $nominee->gender ? ucfirst($nominee->gender) : '—' }}</td>
                <td class="label">Mobile</td>
                <td>
                    {{ $show($nominee->mobile) }}{{ $nominee->country_code && $nominee->country_code !== 'BD' ? ' ('.$nominee->country_code.')' : '' }}
                </td>
            </tr>
            <tr>
                <td class="label">National ID</td>
                <td>{{ $show($nominee->nid) }}</td>
                {{--
                    The share this nominee is to receive. Printed because it is
                    the thing the document is for: an association reading a
                    profile after a death needs to know who gets what, and that
                    figure lives nowhere a person would otherwise look.
                --}}
                <td class="label">Share</td>
                <td>
                    {{ $nominee->share_percentage === null ? '—' : rtrim(rtrim((string) $nominee->share_percentage, '0'), '.').'%' }}
                </td>
            </tr>
            <tr>
                <td class="label">Profession</td>
                <td colspan="3">{{ $show($nominee->profession) }}</td>
            </tr>
            <tr>
                <td class="label">Address</td>
                <td colspan="3">{{ $show($nominee->address) }}</td>
            </tr>
        </table>
    @empty
        <table class="fields">
            <tr>
                {{--
                    Said, not left empty. A membership with no nominee recorded
                    is a gap the association has to close, and a profile that
                    simply omits the section hides it.
                --}}
                <td>No nominee has been recorded for this member.</td>
            </tr>
        </table>
    @endforelse

    {{--
        WHAT IS ON FILE - every slot, filled or not.

        New here; the legacy profile says nothing about documents. "Which
        members still owe us an NID" is a question an office asks, and the
        answer belongs on the profile it prints for them.
    --}}
    <h2>Documents on file</h2>

    <table class="slots">
        <thead>
            <tr>
                <th>Document</th>
                <th>Held</th>
                <th>Filed on</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($documents as $slot)
                <tr>
                    <td>{{ $slot['label'] }}</td>
                    <td>
                        @if ($slot['uploaded'])
                            Yes
                        @elseif ($slot['pending'])
                            Awaiting review
                        @else
                            No
                        @endif
                    </td>
                    <td>
                        {{ $slot['uploaded_at']
                            ? \Illuminate\Support\Carbon::parse($slot['uploaded_at'])->format('j M Y')
                            : '—' }}
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="note">
        A dash means the association does not hold that information. This
        profile is produced from the member's record as it stands on the date
        below; it is not an application and does not by itself change anything.
    </div>

    <table class="sign">
        <tr>
            <td><div class="line">Member</div></td>
            <td style="text-align:right"><div class="line" style="margin-left:auto">For the association</div></td>
        </tr>
    </table>

    <div class="foot">
        {{ $member->name }}{{ $info?->membership_no ? ' · '.$info->membership_no : '' }} ·
        generated {{ $generatedAt }}
    </div>
</body>
</html>
