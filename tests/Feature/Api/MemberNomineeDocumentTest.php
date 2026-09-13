<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Tenant\Document;
use App\Models\Tenant\Member;
use App\Models\Tenant\Nominee;
use App\Models\User;
use App\Services\DocumentService;
use App\Services\TenantSeedService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\TenantFixtures;
use Tests\TenantTestCase;

/**
 * A member sends their nominee's photograph and NID.
 *
 * WHERE THEY COULD BEFORE: nowhere. Every nominee document route is behind
 * `nominees.manage`, a staff permission, so a member could NAME their nominee
 * through the form and not attach the NID that proves who they are - which is
 * most of the point of recording one. The legacy uploads both on the same tab
 * that names them.
 *
 * THE SAME QUEUE AS THEIR OWN DOCUMENTS, not the profile-update row the legacy
 * uses. A photograph is decided by LOOKING at it, and the review screen
 * already shows the file and already distinguishes a nominee's from a
 * member's. Putting images inside a text change-set would have an officer
 * approving a filename.
 */
class MemberNomineeDocumentTest extends TenantTestCase
{
    use TenantFixtures;

    private function headers(string $token): array
    {
        return [
            'X-Tenant' => $this->slug(),
            'Accept' => 'application/json',
            'Authorization' => "Bearer {$token}",
        ];
    }

    private function member(): Member
    {
        return $this->inTenant(function () {
            app(TenantSeedService::class)->seedAll();

            return $this->makeMember();
        });
    }

    private function memberToken(Member $member): string
    {
        return $this->inTenant(
            fn () => $member->createToken('member', [
                'member.dues.view', 'member.profile.request-change',
            ])->plainTextToken
        );
    }

    private function staffToken(): string
    {
        return $this->inTenant(function () {
            static $n = 0;
            $n++;

            app(TenantSeedService::class)->seedAll();

            $user = User::create([
                'name' => "Officer {$n}",
                'email' => "nomdoc{$n}@assoc.test",
                'password' => 'secret-password',
            ]);
            $user->assignRole('superadmin');

            return $user->createToken('test', $user->getAllPermissions()->pluck('name')->all())
                ->plainTextToken;
        });
    }

    private function photo(string $name = 'nid.jpg'): UploadedFile
    {
        // A real JPEG, so the content sniff in the service is exercised rather
        // than bypassed by a fake().
        return UploadedFile::fake()->image($name, 600, 400);
    }

    // ---- sending one ------------------------------------------------------

    public function test_a_member_sends_their_nominees_nid_and_it_waits(): void
    {
        $member = $this->member();
        $token = $this->memberToken($member);

        $this->inTenant(fn () => $member->nominees()->create([
            'name' => 'Rahima Begum',
            'relation' => 'Wife',
        ]));

        $this->post('/api/v1/me/nominee-documents', [
            'slot' => 'nid_front',
            'file' => $this->photo(),
        ], $this->headers($token))->assertStatus(201);

        $this->inTenant(function () use ($member) {
            $nominee = $member->nominees()->first();

            $document = Document::query()
                ->where('documentable_type', Nominee::class)
                ->where('documentable_id', $nominee->id)
                ->where('slot', 'nid_front')
                ->first();

            self::assertNotNull($document, 'The submission was not recorded.');

            // PENDING, not live. Nothing the association holds has changed.
            self::assertSame(Document::STATUS_PENDING, $document->status);
        });
    }

    /** Every nominee slot comes back, filled or not, so the form can list them. */
    public function test_the_slots_are_listed_for_a_member_with_a_nominee(): void
    {
        $member = $this->member();
        $token = $this->memberToken($member);

        $this->inTenant(fn () => $member->nominees()->create(['name' => 'Rahima Begum']));

        $response = $this->getJson('/api/v1/me/nominee-documents', $this->headers($token))
            ->assertOk();

        $slots = collect($response->json('data'))->pluck('slot')->all();

        foreach (array_keys(DocumentService::NOMINEE_SLOTS) as $slot) {
            self::assertContains($slot, $slots, "[{$slot}] is missing from the list.");
        }
    }

    // ---- no nominee yet ---------------------------------------------------

    /**
     * NOT A 404.
     *
     * "No such thing" is what a wrong id deserves. This is a member who has
     * not named anybody yet, and the answer they need is "name them first" -
     * a different screen, not a missing page.
     */
    public function test_a_member_with_no_nominee_is_told_to_name_one(): void
    {
        $member = $this->member();

        $this->getJson('/api/v1/me/nominee-documents', $this->headers($this->memberToken($member)))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'NO_NOMINEE');
    }

    // ---- whose nominee ----------------------------------------------------

    /**
     * THE RESOLVER TAKES THE NOMINEE FROM THE TOKEN, so there is no id to get
     * wrong - which is the property that lets these reads carry no ability.
     * Asserted rather than assumed: a member sending a document must never
     * reach somebody else's nominee.
     */
    public function test_a_member_reaches_only_their_own_nominee(): void
    {
        $mine = $this->member();
        $theirs = $this->inTenant(fn () => $this->makeMember());

        $ids = $this->inTenant(function () use ($mine, $theirs) {
            return [
                'mine' => $mine->nominees()->create(['name' => 'My Nominee'])->id,
                'theirs' => $theirs->nominees()->create(['name' => 'Their Nominee'])->id,
            ];
        });

        $this->post('/api/v1/me/nominee-documents', [
            'slot' => 'image',
            'file' => $this->photo(),
        ], $this->headers($this->memberToken($mine)))->assertStatus(201);

        $this->inTenant(function () use ($ids) {
            self::assertSame(1, Document::query()->where('documentable_id', $ids['mine'])->count());
            self::assertSame(
                0,
                Document::query()->where('documentable_id', $ids['theirs'])->count(),
                "The upload reached another member's nominee.",
            );
        });
    }

    /** The first nominee, matching the form that names them. */
    public function test_it_is_the_first_nominee_when_staff_recorded_several(): void
    {
        $member = $this->member();
        $token = $this->memberToken($member);

        $first = $this->inTenant(function () use ($member) {
            $first = $member->nominees()->create(['name' => 'First Person']);
            $member->nominees()->create(['name' => 'Second Person']);

            return $first->id;
        });

        $this->post('/api/v1/me/nominee-documents', [
            'slot' => 'image',
            'file' => $this->photo(),
        ], $this->headers($token))->assertStatus(201);

        $this->inTenant(function () use ($first) {
            self::assertSame(
                $first,
                Document::query()->where('documentable_type', Nominee::class)->value('documentable_id'),
            );
        });
    }

    // ---- the office decides -----------------------------------------------

    /**
     * It lands in the SAME review queue staff already work, labelled as a
     * nominee's - which is what makes this the right queue rather than the
     * profile-update row.
     */
    public function test_it_appears_on_the_document_review_screen_as_a_nominees(): void
    {
        $member = $this->member();

        $this->inTenant(fn () => $member->nominees()->create(['name' => 'Rahima Begum']));

        $this->post('/api/v1/me/nominee-documents', [
            'slot' => 'nid_back',
            'file' => $this->photo(),
        ], $this->headers($this->memberToken($member)))->assertStatus(201);

        $row = collect(
            $this->getJson('/api/v1/staff/document-reviews', $this->headers($this->staffToken()))
                ->assertOk()
                ->json('data')
        )->firstWhere('slot', 'nid_back');

        self::assertNotNull($row, 'The submission is not in the review queue.');
        self::assertSame('nominee', $row['owner_type']);
        self::assertSame('Rahima Begum', $row['owner_name']);
        self::assertSame('NID back', $row['label']);
    }

    public function test_approval_makes_it_the_document_the_association_holds(): void
    {
        $member = $this->member();

        $this->inTenant(fn () => $member->nominees()->create(['name' => 'Rahima Begum']));

        $this->post('/api/v1/me/nominee-documents', [
            'slot' => 'image',
            'file' => $this->photo(),
        ], $this->headers($this->memberToken($member)))->assertStatus(201);

        $id = $this->inTenant(fn () => Document::query()->latest('id')->value('id'));

        /*
         * `approved`, not `approve` - the document endpoint's vocabulary, and
         * NOT the one the profile-update endpoint beside it uses, which takes
         * `approve`. Two decision endpoints, two spellings of the same word;
         * worth knowing before writing a third.
         */
        $this->postJson("/api/v1/staff/document-reviews/{$id}/decide", [
            'decision' => 'approved',
        ], $this->headers($this->staffToken()))->assertOk();

        $this->inTenant(function () use ($id) {
            self::assertSame(Document::STATUS_LIVE, Document::find($id)->status);
        });
    }

    // ---- what a member still cannot do ------------------------------------

    /**
     * SENDING IS NOT REPLACING. A member files a submission; the association's
     * own copy is staff work, and the staff route stays behind
     * `nominees.manage`.
     */
    public function test_a_member_cannot_write_to_the_staff_nominee_route(): void
    {
        $member = $this->member();

        $nominee = $this->inTenant(
            fn () => $member->nominees()->create(['name' => 'Rahima Begum'])->id
        );

        $this->post("/api/v1/staff/nominees/{$nominee}/documents", [
            'slot' => 'image',
            'file' => $this->photo(),
        ], $this->headers($this->memberToken($member)))->assertStatus(403);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Inside the tenant, because tenancy re-roots the local disk per
        // association - a fake registered out here lands where the service
        // would not look for it.
        $this->inTenant(fn () => Storage::fake('local'));
    }
}
