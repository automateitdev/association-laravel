<?php

declare(strict_types=1);

namespace App\Reports;

use App\Models\Tenant\Document;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Models\Tenant\Signatory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Symfony\Component\HttpFoundation\Response;

/**
 * Share certificates and ID cards (legacy `certificate`, `id-card`).
 *
 * PRINTED IN BATCHES, which is how an office does it - the legacy screen picks
 * members from a list and produces one file, and that is the one thing about it
 * worth keeping.
 *
 * WHAT THE LEGACY VERSION GETS WRONG
 * ----------------------------------
 *
 * 1. IT IS HARDCODED TO ONE ASSOCIATION. The blades carry COCSOL's name,
 *    registration number and date, its email, its authorised capital of one
 *    crore in ten thousand shares, a share value of 1000/-, and a return
 *    address in Ibrahimpur. A second association printing from those templates
 *    hands its members a card belonging to somebody else. All of it is now
 *    settings, seeded empty: a blank line is a question an association can
 *    answer, a wrong registration number is one nobody thinks to ask.
 *
 * 2. IT RENDERS THROUGH DOMPDF, which has no Bengali glyphs at all - zero in
 *    U+0980-09FF - and performs no complex-script shaping. Every Bengali
 *    member name on every certificate that system produced came out as empty
 *    boxes. mPDF with autoScriptToLang is what the rest of this system uses,
 *    and is the reason it can print these names.
 *
 * 3. NOBODY HAS EVER SIGNED ONE. The template matches a free-text title against
 *    the literals `secretary` and `chairman`, and `signatures` holds 0 rows in
 *    production - so the two `@if`s have always been false and the certificate
 *    has always printed with empty space above the signature lines.
 *
 * 4. `sh` IS NOT A WORD. The certificate reads "As per Co-Operative Society Act
 *    ... {he|sh} is entitled", because the female branch of a ternary was typed
 *    short. And a member whose gender is `others` gets a bare `-` in place of
 *    both their title and their pronoun: "This is to certify - Rahim Uddin ...
 *    - is entitled". This writes around pronouns instead, which is both correct
 *    for everybody and shorter.
 *
 * 5. A CERTIFICATE FOR A MEMBER WITH NO SHARES SAYS SO. The legacy prints
 *    "is entitled 0 share", which is a document asserting something false over
 *    two signatures. Refused here, by name, before anything is rendered.
 */
class MemberDocumentRenderer
{
    public const CERTIFICATE = 'certificate';

    public const ID_CARD = 'id-card';

    public const TYPES = [self::CERTIFICATE, self::ID_CARD];

    /** What mPDF can actually draw - see MemberProfileRenderer. */
    private const PRINTABLE = ['image/jpeg', 'image/png'];

    /**
     * @param  list<int>  $memberIds
     *
     * @throws \DomainException when a chosen member cannot be given the document
     */
    public function render(array $memberIds, string $type): Response
    {
        $members = Member::query()
            ->with('associatorInfo')
            ->whereIn('id', $memberIds)
            ->orderBy('name')
            ->get();

        if ($members->isEmpty()) {
            throw new \DomainException('No members were chosen.');
        }

        if ($type === self::CERTIFICATE) {
            $this->assertEveryoneHasShares($members);
        }

        $html = view("reports.{$type}", [
            'society' => $this->society(),
            'members' => $members->map(fn (Member $m) => [
                'record' => $m,
                'photo' => $this->image($m, 'image'),
            ])->all(),
            'signatories' => $this->signatories(),
            'generatedAt' => now()->format('j M Y'),
        ])->render();

        $mpdf = new Mpdf([
            'mode' => 'utf-8',

            // A certificate is landscape and an ID card is a sheet of cards;
            // both are set by the template's own @page rule, so this only has
            // to not fight it.
            'format' => $type === self::CERTIFICATE ? 'A4-L' : 'A4',

            // What makes Bengali member names render at all, and the single
            // largest difference from the legacy version of this document.
            'autoScriptToLang' => true,
            'autoLangToFont' => true,

            'tempDir' => $this->tempDir(),
        ]);

        $mpdf->WriteHTML($html);

        $name = $type === self::CERTIFICATE ? 'share-certificates' : 'id-cards';

        return response($mpdf->Output('', Destination::STRING_RETURN), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$name.'-'.now()->toDateString().'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * A share certificate states a number of shares. Zero is not one.
     *
     * The legacy prints "is entitled 0 share of taka 1000/-" over two signature
     * lines, which is a document asserting something false. Refused by NAME,
     * because the person printing a batch of forty needs to know which ones to
     * take out rather than that "something" was wrong.
     *
     * @param  Collection<int, Member>  $members
     */
    private function assertEveryoneHasShares(Collection $members): void
    {
        $without = $members
            ->filter(fn (Member $m) => (int) ($m->associatorInfo?->num_or_shares ?? 0) === 0)
            ->pluck('name');

        if ($without->isNotEmpty()) {
            throw new \DomainException(
                'A share certificate states how many shares a member holds, and these hold none: '
                .$without->join(', ', ' and ').'.'
            );
        }
    }

    /**
     * Who the association is, from its own settings.
     *
     * @return array<string, string>
     */
    private function society(): array
    {
        return [
            'name' => (string) Setting::get(Setting::SOCIETY_NAME),
            'registration_no' => (string) Setting::get(Setting::SOCIETY_REGISTRATION_NO),
            'registered_on' => (string) Setting::get(Setting::SOCIETY_REGISTERED_ON),
            'address' => (string) Setting::get(Setting::SOCIETY_ADDRESS),
            'email' => (string) Setting::get(Setting::SOCIETY_EMAIL),
            'website' => (string) Setting::get(Setting::SOCIETY_WEBSITE),
            'authorised_capital' => (string) Setting::get(Setting::SOCIETY_AUTHORISED_CAPITAL),
            'total_shares' => (string) Setting::get(Setting::SOCIETY_TOTAL_SHARES),
            'share_value' => (string) Setting::get(Setting::SOCIETY_SHARE_VALUE),
        ];
    }

    /**
     * The association's signatories, with their signature images.
     *
     * IN A FIXED ORDER, so a certificate always has the secretary on the left
     * and the chairman on the right however the rows happen to be stored.
     *
     * @return list<array{role: string, label: string, name: string, image: ?string}>
     */
    private function signatories(): array
    {
        $held = Signatory::all()->keyBy('role');
        $order = ['secretary', 'chairman'];

        $out = [];

        foreach ($order as $role) {
            $signatory = $held->get($role);

            if ($signatory === null) {
                continue;
            }

            $out[] = [
                'role' => $role,
                'label' => $signatory->label(),
                'name' => (string) $signatory->name,
                'image' => $this->image($signatory, 'signature')['uri'],
            ];
        }

        return $out;
    }

    /**
     * A live document in a slot, as a data URI.
     *
     * A DATA URI RATHER THAN A PATH OR A URL: the bytes may be in a bucket that
     * must never be public, so there is nothing for mPDF to fetch, and on local
     * storage a path would tie the PDF to where storage happens to be mounted.
     *
     * @return array{uri: ?string, note: ?string}
     */
    private function image(Model $owner, string $slot): array
    {
        $document = Document::query()
            ->where('documentable_type', $owner->getMorphClass())
            ->where('documentable_id', $owner->getKey())
            ->where('slot', $slot)
            ->where('status', Document::STATUS_LIVE)
            ->first();

        if ($document === null || ! $document->hasFile()) {
            return ['uri' => null, 'note' => 'No photograph on file'];
        }

        if (! in_array($document->mime, self::PRINTABLE, true)) {
            return ['uri' => null, 'note' => 'Photograph in a format this document cannot show'];
        }

        try {
            $bytes = Storage::disk($document->disk)->get($document->path);
        } catch (\Throwable $e) {
            Log::warning('Could not read an image for a printed member document.', [
                'disk' => $document->disk,
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);

            $bytes = null;
        }

        return $bytes === null
            ? ['uri' => null, 'note' => 'Photograph recorded but no longer stored']
            : ['uri' => 'data:'.$document->mime.';base64,'.base64_encode($bytes), 'note' => null];
    }

    private function tempDir(): string
    {
        $path = storage_path('app/mpdf');

        if (! is_dir($path)) {
            mkdir($path, 0775, true);
        }

        return $path;
    }
}
