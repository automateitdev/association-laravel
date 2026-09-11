<?php

declare(strict_types=1);

namespace App\Reports;

use App\Models\Tenant\Document;
use App\Models\Tenant\Member;
use App\Models\Tenant\MemberPreference;
use App\Models\Tenant\Nominee;
use App\Services\DocumentService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Symfony\Component\HttpFoundation\Response;

/**
 * One member's record as a PDF (legacy `member-pdf/{id}`).
 *
 * A DOCUMENT, NOT A REPORT - the same judgement as InvoiceRenderer, and see the
 * note there. Report/Column renders a grid of rows with column totals; a
 * profile is sections of labelled facts about one person, and nothing on it is
 * summable. Forcing it through would have produced a two-column table of
 * "field" and "value" with an association name on top.
 *
 * FOUR DELIBERATE DIFFERENCES FROM THE LEGACY VERSION
 * ---------------------------------------------------
 *
 * 1. IT IS NOT CALLED "MEMBERSHIP APPLICATION". The legacy template is headed
 *    that way and then fills the form in from the record of an already-approved
 *    member - including the box marked "office use only". A printed application
 *    that is really a profile invites being filed as an application, and an
 *    association that keeps both will not be able to tell them apart a year
 *    later. This says what it is.
 *
 * 2. IT PRINTS THE STATUS. The legacy profile of a suspended member looks
 *    exactly like the profile of an active one. That is the single most
 *    important fact about a membership and it was the one fact missing.
 *
 * 3. EVERY NOMINEE, not the first. `$member->nominee` in the legacy is a
 *    hasOne; BCS records several, with the share each is to receive, and a
 *    profile that silently prints one of three is worse than one that prints
 *    none.
 *
 * 4. IT SAYS WHAT IS ON FILE. Which documents the association holds, and which
 *    slots are still empty, comes from DocumentService::list - the same "every
 *    slot, filled or not" view the screens use. "Which members still owe us an
 *    NID" is a question an office asks, and the answer belongs on the profile
 *    it prints for them.
 *
 * 5. IT CARRIES THE HOUSING QUESTIONNAIRE, which is the legacy's fourth
 *    section and was missing from the first version of this document because
 *    BCS had no table for it. It now does. Only the projects the member has
 *    actually ANSWERED are printed: three empty blocks on 297 of 315 profiles
 *    would be three blocks nobody reads, which is how the legacy's own count of
 *    that table came to be wrong by a factor of fifteen.
 */
class MemberProfileRenderer
{
    /**
     * What mPDF can actually draw.
     *
     * DocumentService accepts HEIC and WebP as well, because those are what a
     * phone produces and refusing them at the door would send members away from
     * the office. mPDF draws neither reliably, so a photograph in one of them
     * is reported as present-but-unprintable rather than emitted as a broken
     * image tag - a profile that fails to render is a worse outcome than a
     * profile with a line of explanation where the photograph goes.
     *
     * @var list<string>
     */
    private const PRINTABLE = ['image/jpeg', 'image/png'];

    public function __construct(private readonly DocumentService $documents) {}

    public function render(Member $member, string $association): Response
    {
        $mpdf = new Mpdf([
            'mode' => 'utf-8',

            // Portrait: this is a form somebody files or hands over, and its
            // widest row is a label and an address.
            'format' => 'A4',

            // What makes Bengali member names render at all - see
            // ReportExporter for the measurements behind this.
            'autoScriptToLang' => true,
            'autoLangToFont' => true,

            'tempDir' => $this->tempDir(),
        ]);

        $mpdf->WriteHTML($this->html($member, $association));

        return response($mpdf->Output('', Destination::STRING_RETURN), 200, [
            'Content-Type' => 'application/pdf',

            /*
             * `attachment`, unlike the receipt, and the difference is what the
             * two are for. A receipt is looked at and handed over; a profile
             * carries the member's NID, both addresses and their nominee's NID,
             * and is produced to be filed. It should land in a folder somebody
             * chose rather than open in a tab on a shared office machine.
             */
            'Content-Disposition' => 'attachment; filename="'.$this->filename($member).'.pdf"',

            // Never a shared browser cache. The same reasoning as a document
            // download: this is identity data.
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * The document as HTML, before mPDF turns it into a page.
     *
     * PUBLIC, AND NOT ONLY FOR THE TESTS. Assembling the document and encoding
     * it as a PDF are two jobs, and this is the seam between them: everything
     * that could be WRONG about a profile - a missing nominee, an unreviewed
     * photograph, a suspended membership that does not say so - is decided
     * here, while the step after it is mPDF's business rather than ours.
     *
     * Asserting against the PDF instead would mean asserting against inflated
     * FlateDecode streams in which mPDF has broken every word into positioned
     * fragments. A test that has to reassemble "Su" and "spended" before it can
     * look for "Suspended" is testing the wrong layer, and it fails in ways
     * that say nothing about the defect.
     */
    public function html(Member $member, string $association): string
    {
        $member->loadMissing([
            'associatorInfo',
            'introducedBy.associatorInfo',
            'nominees',
            'preferences.introducedBy:id,name',
        ]);

        return view('reports.member-profile', [
            'association' => $association,
            'member' => $member,
            'info' => $member->associatorInfo,
            'introducer' => $this->introducer($member),
            'photo' => $this->photograph($member),
            'nominees' => $member->nominees->map(fn (Nominee $n) => [
                'record' => $n,
                'photo' => $this->photograph($n),
            ])->all(),
            'documents' => $this->documents->list($member),

            /*
             * ANSWERED ONLY. A profile that printed three empty housing blocks
             * for the 297 members who never filled the form in would teach its
             * readers to skip the section - and this is a housing cooperative,
             * so it is the section that matters most on the eighteen where it
             * says something.
             */
            'preferences' => $member->preferences
                ->filter(fn (MemberPreference $p) => $p->isAnswered())
                ->values()
                ->all(),
            'generatedAt' => now()->format('j M Y, g:i a'),
        ])->render();
    }

    /**
     * `MemberProfile_000123` or `MemberProfile_COCSOL-0042`.
     *
     * The membership number when there is one, because that is what the office
     * files under; the id, zero-padded the way the legacy did it, when the
     * office has not assigned a number yet. A member legitimately exists before
     * they are numbered.
     */
    private function filename(Member $member): string
    {
        $number = $member->associatorInfo?->membership_no;

        // Anything a filesystem or a Content-Disposition header would argue
        // about. A membership number is association-chosen free text.
        $safe = $number === null
            ? str_pad((string) $member->id, 8, '0', STR_PAD_LEFT)
            : preg_replace('/[^A-Za-z0-9._-]+/', '-', $number);

        return 'MemberProfile_'.$safe;
    }

    /**
     * The introducer, named the way the detail screen names them.
     *
     * When the link is set, the name comes from that member's own record rather
     * than from the copy taken when the form was filled in - which is what
     * stops "Md. Riaz uddin" and "Md. Riaz Uddin" being two people on two
     * printed profiles.
     *
     * @return array{name: string, membership_no: ?string, is_member: bool}|null
     */
    private function introducer(Member $member): ?array
    {
        if ($member->introduced_by_member_id !== null) {
            return [
                'name' => (string) $member->introducedBy?->name,
                'membership_no' => $member->introducedBy?->associatorInfo?->membership_no,
                'is_member' => true,
            ];
        }

        if ($member->introduced_by_name === null) {
            return null;
        }

        return [
            'name' => $member->introduced_by_name,
            'membership_no' => null,
            'is_member' => false,
        ];
    }

    /**
     * The photograph in this record's `image` slot, as a data URI.
     *
     * A DATA URI RATHER THAN A PATH OR A URL, and that is not a convenience.
     * The bytes may be in a bucket that must never be public (NFR-SEC-4), so
     * there is no URL for mPDF to fetch; and on the local disk a path would
     * make the PDF depend on where storage happens to be mounted. Reading
     * through the Storage facade works the same either way.
     *
     * LIVE DOCUMENTS ONLY. A photograph waiting for review has not been
     * accepted, and printing it onto an official profile would settle a
     * decision somebody else is supposed to make.
     *
     * @return array{uri: ?string, note: ?string}
     */
    private function photograph(Model $owner): array
    {
        $document = Document::query()
            ->where('documentable_type', $owner->getMorphClass())
            ->where('documentable_id', $owner->getKey())
            ->where('slot', 'image')
            ->where('status', Document::STATUS_LIVE)
            ->first();

        if ($document === null || ! $document->hasFile()) {
            return ['uri' => null, 'note' => 'No photograph on file'];
        }

        if (! in_array($document->mime, self::PRINTABLE, true)) {
            return [
                'uri' => null,
                'note' => 'Photograph on file in a format this document cannot show',
            ];
        }

        try {
            $bytes = Storage::disk($document->disk)->get($document->path);
        } catch (\Throwable $e) {
            /*
             * Logged with the disk named, because the two causes need different
             * people: a missing object in a bucket is an operations question,
             * and a row pointing at a file nobody stored is a data one.
             */
            Log::warning('Could not read a photograph for a member profile PDF.', [
                'disk' => $document->disk,
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);

            $bytes = null;
        }

        if ($bytes === null) {
            // The row outlived the file. Said on the page rather than left
            // blank: "no photograph" and "we lost the photograph" are different
            // findings, and only one of them is the member's to fix.
            return ['uri' => null, 'note' => 'Photograph recorded but no longer stored'];
        }

        return [
            'uri' => 'data:'.$document->mime.';base64,'.base64_encode($bytes),
            'note' => null,
        ];
    }

    /**
     * A writable scratch directory for mPDF.
     *
     * mPDF creates and clears this itself, so on a healthy host the mkdir never
     * fires. It exists for the unhealthy one: mPDF throws if it cannot write,
     * and a profile that fails at the last step with a filesystem error is a
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
