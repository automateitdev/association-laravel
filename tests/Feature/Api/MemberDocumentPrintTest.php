<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Tenant\AssociatorInfo;
use App\Models\Tenant\Document;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Models\Tenant\Signatory;
use App\Models\User;
use App\Reports\MemberDocumentRenderer;
use App\Services\TenantSeedService;
use Illuminate\Support\Facades\Storage;
use Tests\Support\TenantFixtures;
use Tests\TenantTestCase;

/**
 * Share certificates, ID cards and the people who sign them.
 *
 * A PORT OF SOMETHING THAT HAS NEVER WORKED. The legacy has both documents and
 * `signatures` holds **0 rows in production**, so no certificate that system
 * ever produced has been signed - the template matches a free-text title
 * against the literals `secretary` and `chairman`, and both `@if`s have always
 * been false.
 *
 * What these pin are the five things the legacy version gets wrong, in the
 * order they would hurt:
 *
 *   1. IT IS HARDCODED TO ONE ASSOCIATION - name, registration number, email,
 *      authorised capital, share value, return address. A second association
 *      printing from those templates hands its members COCSOL's card.
 *   2. Every Bengali name came out as empty boxes, because dompdf has no
 *      glyphs in U+0980-09FF. Not asserted here - it is mPDF configuration, and
 *      ReportExporter carries the measurement - but it is why these render
 *      through the same path as every other document in this system.
 *   3. Nobody has ever signed one.
 *   4. `sh` is not a word, and a member recorded as `other` gets a bare dash
 *      where their name's title and their pronoun should be.
 *   5. A certificate for a member with no shares asserts something false over
 *      two signatures.
 */
class MemberDocumentPrintTest extends TenantTestCase
{
    use TenantFixtures;

    private function headers(?string $token = null): array
    {
        return array_filter([
            'X-Tenant' => $this->slug(),
            'Accept' => 'application/json',
            'Authorization' => $token ? "Bearer {$token}" : null,
        ]);
    }

    private function staffToken(): string
    {
        return $this->inTenant(function () {
            app(TenantSeedService::class)->seedAll();

            static $sequence = 0;
            $sequence++;

            $user = User::create([
                'name' => "Clerk {$sequence}",
                'email' => "print{$sequence}@assoc.test",
                'password' => 'secret-password',
            ]);
            $user->assignRole('superadmin');

            return $user->createToken('test', $user->getAllPermissions()->pluck('name')->all())
                ->plainTextToken;
        });
    }

    /** An association that has filled its own details in. */
    private function society(): void
    {
        Setting::put(Setting::SOCIETY_NAME, 'Test Officers Co-operative Society Ltd.');
        Setting::put(Setting::SOCIETY_REGISTRATION_NO, '07/2026');
        Setting::put(Setting::SOCIETY_REGISTERED_ON, '2026-02-23');
        Setting::put(Setting::SOCIETY_ADDRESS, 'House 12, Road 3, Dhaka');
        Setting::put(Setting::SOCIETY_EMAIL, 'office@assoc.test');
        Setting::put(Setting::SOCIETY_AUTHORISED_CAPITAL, '10000000');
        Setting::put(Setting::SOCIETY_TOTAL_SHARES, '10000');
        Setting::put(Setting::SOCIETY_SHARE_VALUE, '1000');
    }

    private function shareholder(int $shares = 5, array $attributes = []): Member
    {
        $member = $this->makeMember($attributes + [
            'name' => 'Rahim Uddin',
            'father_name' => 'Karim Uddin',
            'permanent_address' => 'Bhola',
            'bcs_batch' => '31st BCS',
        ]);

        AssociatorInfo::create([
            'member_id' => $member->id,
            'membership_no' => 'TEST-'.$member->id,
            'num_or_shares' => $shares,
        ]);

        return $member->fresh();
    }

    private function print(string $token, array $ids, string $type)
    {
        return $this->withHeaders($this->headers($token))
            ->post('/api/v1/staff/members/print', ['type' => $type, 'member_ids' => $ids]);
    }

    // ------------------------------------------------------------ certificates

    public function test_it_prints_a_share_certificate(): void
    {
        $token = $this->staffToken();
        $id = $this->inTenant(function () {
            $this->society();

            return $this->shareholder()->id;
        });

        $response = $this->print($token, [$id], MemberDocumentRenderer::CERTIFICATE)
            ->assertStatus(200);

        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        self::assertStringStartsWith('%PDF', $response->getContent());
        self::assertStringContainsString(
            'share-certificates',
            (string) $response->headers->get('Content-Disposition'),
        );
    }

    /**
     * THE ASSOCIATION'S OWN DETAILS, from settings.
     *
     * The legacy blades carry COCSOL's name, registration number, email and
     * authorised capital as literals. A second association printing from them
     * hands its members a certificate belonging to somebody else - which on a
     * multi-tenant platform is the defect that matters most.
     */
    public function test_the_certificate_carries_the_association_that_issued_it(): void
    {
        $this->inTenant(function () {
            $this->society();
            $member = $this->shareholder(12);

            $html = app(MemberDocumentRenderer::class)
                ->render([$member->id], MemberDocumentRenderer::CERTIFICATE);

            // Rendered through the view, so assert on the view - the same
            // reasoning as the profile PDF: mPDF breaks words into positioned
            // fragments and a test at that layer is testing mPDF.
            $view = view('reports.certificate', $this->viewData($member))->render();

            self::assertStringContainsString('Test Officers Co-operative Society Ltd.', $view);
            self::assertStringContainsString('07/2026', $view);
            self::assertStringContainsString('office@assoc.test', $view);
            self::assertStringNotContainsString('COCSOL', $view);

            // The member's own facts, and the share count spelled as a figure.
            self::assertStringContainsString('Rahim Uddin', $view);
            self::assertStringContainsString('Karim Uddin', $view);
            self::assertStringContainsString('TEST-'.$member->id, $view);
            self::assertStringContainsString('12', $view);

            self::assertSame(200, $html->getStatusCode());
        });
    }

    /**
     * NO PRONOUNS AT ALL.
     *
     * The legacy writes `{he|sh}` and `{His|Her|-}` from a ternary on gender,
     * which prints `sh` for every woman and a bare dash for anybody recorded as
     * `other`: "This is to certify - Rahim Uddin ... - is entitled". Writing
     * round it is correct for everybody and shorter than getting it right three
     * ways.
     */
    public function test_the_certificate_uses_no_pronouns(): void
    {
        $this->inTenant(function () {
            $this->society();

            foreach (['male', 'female', 'other'] as $gender) {
                $member = $this->shareholder(3, [
                    'gender' => $gender,
                    'mobile' => '0171000'.strlen($gender).'111',
                ]);

                $view = view('reports.certificate', $this->viewData($member))->render();

                foreach ([' he ', ' sh ', ' she ', ' his ', ' her ', 'Mr.', 'Mrs.'] as $pronoun) {
                    self::assertStringNotContainsString(
                        $pronoun,
                        $view,
                        "The certificate should not say [{$pronoun}].",
                    );
                }

                // And it still names the person, which is the point of it.
                self::assertStringContainsString('Rahim Uddin', $view);
            }
        });
    }

    /**
     * A certificate states a number of shares, and zero is not one.
     *
     * The legacy prints "is entitled 0 share of taka 1000/-" over two signature
     * lines. Refused here BY NAME, because somebody printing forty needs to
     * know which ones to take out.
     */
    public function test_it_refuses_a_certificate_for_a_member_with_no_shares(): void
    {
        $token = $this->staffToken();

        [$with, $without] = $this->inTenant(function () {
            $this->society();

            return [
                $this->shareholder(4, ['mobile' => '01710000201'])->id,
                $this->shareholder(0, ['name' => 'Nobody Shareless', 'mobile' => '01710000202'])->id,
            ];
        });

        $response = $this->print($token, [$with, $without], MemberDocumentRenderer::CERTIFICATE)
            ->assertStatus(422);

        // Named, not counted.
        self::assertStringContainsString('Nobody Shareless', $response->json('error.message'));

        // ...and an ID card for the same member is fine: a card says who
        // somebody is, not what they own.
        $this->print($token, [$without], MemberDocumentRenderer::ID_CARD)->assertStatus(200);
    }

    // --------------------------------------------------------------- ID cards

    public function test_it_prints_id_cards_for_several_members_in_one_file(): void
    {
        $token = $this->staffToken();

        $ids = $this->inTenant(function () {
            $this->society();

            return [
                $this->shareholder(1, ['mobile' => '01710000301'])->id,
                $this->shareholder(1, ['name' => 'Karim Mia', 'mobile' => '01710000302'])->id,
                $this->shareholder(1, ['name' => 'Shirin Akter', 'mobile' => '01710000303'])->id,
            ];
        });

        $response = $this->print($token, $ids, MemberDocumentRenderer::ID_CARD)->assertStatus(200);

        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        self::assertStringContainsString('id-cards', (string) $response->headers->get('Content-Disposition'));
    }

    // ------------------------------------------------------------ signatories

    /**
     * Every role is listed, filled or not.
     *
     * "We have no secretary's signature on file" is the finding, and a list of
     * only what exists cannot show it. In the legacy data the answer is all
     * three: `signatures` holds 0 rows.
     */
    public function test_every_role_is_listed_whether_or_not_somebody_holds_it(): void
    {
        $token = $this->staffToken();

        $this->withHeaders($this->headers($token))
            ->getJson('/api/v1/staff/signatories')
            ->assertStatus(200)
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.role', 'chairman')
            ->assertJsonPath('data.0.name', null)
            ->assertJsonPath('data.0.has_signature', false);
    }

    /** A person AND a signature are different facts, and the list says both. */
    public function test_recording_a_name_is_not_the_same_as_holding_a_signature(): void
    {
        $token = $this->staffToken();

        $this->withHeaders($this->headers($token))
            ->putJson('/api/v1/staff/signatories/secretary', ['name' => 'Md. Abdul Karim'])
            ->assertStatus(200);

        $response = $this->withHeaders($this->headers($token))
            ->getJson('/api/v1/staff/signatories')
            ->assertStatus(200);

        $secretary = collect($response->json('data'))->firstWhere('role', 'secretary');

        self::assertSame('Md. Abdul Karim', $secretary['name']);
        self::assertFalse($secretary['has_signature']);
    }

    /**
     * The signature goes with the person.
     *
     * Leaving the image behind would mean the next holder of the role inherits
     * the last one's signature, which is the worst possible default.
     */
    public function test_clearing_a_role_removes_its_signature_too(): void
    {
        Storage::fake('local');

        $token = $this->staffToken();

        $this->withHeaders($this->headers($token))
            ->putJson('/api/v1/staff/signatories/chairman', ['name' => 'Md. Riaz Uddin'])
            ->assertStatus(200);

        $this->inTenant(function () {
            Storage::disk('local')->put('signatures/one.png', $this->png());

            Document::create([
                'documentable_type' => Signatory::class,
                'documentable_id' => Signatory::where('role', 'chairman')->firstOrFail()->id,
                'slot' => 'signature',
                'status' => Document::STATUS_LIVE,
                'disk' => 'local',
                'path' => 'signatures/one.png',
                'original_name' => 'sign.png',
                'mime' => 'image/png',
                'size' => 100,
            ]);
        });

        $response = $this->withHeaders($this->headers($token))
            ->getJson('/api/v1/staff/signatories')
            ->assertStatus(200);

        self::assertTrue(collect($response->json('data'))->firstWhere('role', 'chairman')['has_signature']);

        // Clearing the name removes the person and the signature with them.
        $this->withHeaders($this->headers($token))
            ->putJson('/api/v1/staff/signatories/chairman', ['name' => ''])
            ->assertStatus(200);

        $this->inTenant(function () {
            self::assertSame(0, Signatory::where('role', 'chairman')->count());
            self::assertSame(0, Document::where('documentable_type', Signatory::class)->count());
        });
    }

    /**
     * An unsigned certificate SAYS it is unsigned.
     *
     * The legacy prints empty space above the lines, which reads as a printing
     * fault rather than a document nobody has authorised - and since
     * `signatures` holds no rows, that is every certificate it ever made.
     */
    public function test_a_certificate_with_no_signatories_says_so(): void
    {
        $this->inTenant(function () {
            $this->society();
            $member = $this->shareholder();

            $view = view('reports.certificate', $this->viewData($member))->render();

            self::assertStringContainsString('No signatories are recorded', $view);
        });
    }

    public function test_an_unknown_role_is_a_404(): void
    {
        $token = $this->staffToken();

        $this->withHeaders($this->headers($token))
            ->putJson('/api/v1/staff/signatories/president', ['name' => 'Somebody'])
            ->assertStatus(404);
    }

    /** A signature belongs to somebody; there is nobody to attach it to yet. */
    public function test_a_signature_needs_a_person_first(): void
    {
        $token = $this->staffToken();

        $this->withHeaders($this->headers($token))
            ->post('/api/v1/staff/signatories/treasurer/signature', [
                'file' => \Illuminate\Http\UploadedFile::fake()->image('sign.png'),
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'NO_SIGNATORY');
    }

    // ------------------------------------------------------------------ refusals

    public function test_an_unknown_document_type_is_refused(): void
    {
        $token = $this->staffToken();
        $id = $this->inTenant(fn () => $this->shareholder()->id);

        $this->print($token, [$id], 'poster')->assertStatus(422);
    }

    public function test_it_refuses_a_batch_with_nobody_in_it(): void
    {
        $token = $this->staffToken();

        $this->print($token, [], MemberDocumentRenderer::ID_CARD)->assertStatus(422);
    }

    public function test_it_refuses_a_request_with_no_token(): void
    {
        $this->postJson(
            '/api/v1/staff/members/print',
            ['type' => 'id-card', 'member_ids' => [1]],
            $this->headers(),
        )->assertStatus(401);
    }

    // ------------------------------------------------------------------ helpers

    /** @return array<string, mixed> */
    private function viewData(Member $member): array
    {
        return [
            'society' => [
                'name' => (string) Setting::get(Setting::SOCIETY_NAME),
                'registration_no' => (string) Setting::get(Setting::SOCIETY_REGISTRATION_NO),
                'registered_on' => (string) Setting::get(Setting::SOCIETY_REGISTERED_ON),
                'address' => (string) Setting::get(Setting::SOCIETY_ADDRESS),
                'email' => (string) Setting::get(Setting::SOCIETY_EMAIL),
                'website' => (string) Setting::get(Setting::SOCIETY_WEBSITE),
                'authorised_capital' => (string) Setting::get(Setting::SOCIETY_AUTHORISED_CAPITAL),
                'total_shares' => (string) Setting::get(Setting::SOCIETY_TOTAL_SHARES),
                'share_value' => (string) Setting::get(Setting::SOCIETY_SHARE_VALUE),
            ],
            'members' => [
                ['record' => $member->fresh('associatorInfo'), 'photo' => ['uri' => null, 'note' => 'No photograph on file']],
            ],
            'signatories' => [],
            'generatedAt' => '12 Sep 2026',
        ];
    }

    private function png(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        );
    }
}
