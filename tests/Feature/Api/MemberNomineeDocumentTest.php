<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Tenant\Document;
use App\Models\Tenant\Member;
use App\Models\Tenant\MemberProfileUpdate;
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

    // ---- before the nominee exists ---------------------------------------

    /**
     * THE FIRST NOMINEE CAN BE DOCUMENTED IN THE SAME VISIT.
     *
     * This is what the whole pending-owner arrangement is for. A member naming
     * their first nominee has nobody to attach an NID to - the row is created
     * on approval - so an earlier version refused and told them to come back
     * afterwards. Two visits for what the legacy does in one submission, and
     * the second visit is the one nobody makes.
     */
    public function test_a_member_can_attach_documents_while_the_nominee_is_still_pending(): void
    {
        $member = $this->member();
        $token = $this->memberToken($member);

        // The name, filed and not yet decided. No Nominee row exists.
        $this->postJson('/api/v1/me/profile-updates', [
            'nominee' => ['name' => 'Rahima Begum', 'relation' => 'Wife'],
        ], $this->headers($token))->assertStatus(201);

        $this->inTenant(fn () => self::assertSame(0, Nominee::query()->count()));

        $this->post('/api/v1/me/nominee-documents', [
            'slot' => 'nid_front',
            'file' => $this->photo(),
        ], $this->headers($token))->assertStatus(201);

        // Held against the REQUEST, because there is no person yet.
        $this->inTenant(function () {
            $document = Document::query()->latest('id')->first();

            self::assertSame(MemberProfileUpdate::class, $document->documentable_type);
        });
    }

    /** And approval moves them onto the nominee it creates. */
    public function test_approval_moves_the_attachments_onto_the_new_nominee(): void
    {
        $member = $this->member();
        $token = $this->memberToken($member);

        $this->postJson('/api/v1/me/profile-updates', [
            'nominee' => ['name' => 'Rahima Begum'],
        ], $this->headers($token))->assertStatus(201);

        $this->post('/api/v1/me/nominee-documents', [
            'slot' => 'nid_front',
            'file' => $this->photo(),
        ], $this->headers($token))->assertStatus(201);

        $update = $this->inTenant(fn () => MemberProfileUpdate::query()->latest('id')->value('id'));

        $this->postJson("/api/v1/staff/profile-updates/{$update}/decide", [
            'decision' => 'approve',
        ], $this->headers($this->staffToken()))->assertOk();

        $this->inTenant(function () use ($member) {
            $nominee = $member->nominees()->first();
            $document = Document::query()->latest('id')->first();

            self::assertNotNull($nominee);
            self::assertSame(Nominee::class, $document->documentable_type);
            self::assertSame($nominee->id, $document->documentable_id);

            // The SAME row, re-pointed - not a copy. Two identical
            // photographs is two things for an officer to decide between.
            self::assertSame(1, Document::query()->count());
        });
    }

    /**
     * A REFUSED REQUEST TAKES ITS ATTACHMENTS WITH IT.
     *
     * They were sent for a nominee the office declined to record. Left behind
     * they would be an identity document against a dead request -
     * unreachable by the member, invisible to staff, and still on disk.
     */
    public function test_refusing_the_request_discards_what_was_attached_to_it(): void
    {
        $member = $this->member();
        $token = $this->memberToken($member);

        $this->postJson('/api/v1/me/profile-updates', [
            'nominee' => ['name' => 'Rahima Begum'],
        ], $this->headers($token))->assertStatus(201);

        $this->post('/api/v1/me/nominee-documents', [
            'slot' => 'image',
            'file' => $this->photo(),
        ], $this->headers($token))->assertStatus(201);

        $update = $this->inTenant(fn () => MemberProfileUpdate::query()->latest('id')->value('id'));

        $this->postJson("/api/v1/staff/profile-updates/{$update}/decide", [
            'decision' => 'reject',
            'reason' => 'The NID does not match that name.',
        ], $this->headers($this->staffToken()))->assertOk();

        $this->inTenant(function () {
            self::assertSame(0, Nominee::query()->count());
            self::assertSame(0, Document::query()->count(), 'The attachment was orphaned.');
        });
    }

    /**
     * ONLY A REQUEST THAT ACTUALLY NAMES A NOMINEE.
     *
     * A member with a pending address change has not asked for a nominee, and
     * hanging an NID off that request would put a stranger's photograph in
     * front of an officer deciding a street name.
     */
    public function test_a_pending_change_that_is_not_about_a_nominee_does_not_accept_documents(): void
    {
        $member = $this->member();
        $token = $this->memberToken($member);

        $this->postJson('/api/v1/me/profile-updates', [
            'present_address' => 'Somewhere New',
        ], $this->headers($token))->assertStatus(201);

        $this->post('/api/v1/me/nominee-documents', [
            'slot' => 'image',
            'file' => $this->photo(),
        ], $this->headers($token))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'NO_NOMINEE');
    }

    /**
     * NOT A 404.
     *
     * "No such thing" is what a wrong id deserves. This is a member who has
     * not named anybody yet, and the answer they need is "name them first" -
     * a different screen, not a missing page.
     */
    public function test_a_member_with_no_nominee_and_no_request_is_told_what_to_do_first(): void
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

    // ---- what the officer deciding it can see -----------------------------

    /**
     * AN OFFICER SEES THE FILE ON THE REQUEST THEY ARE DECIDING.
     *
     * Without this they would approve "add Firoza Khatun as sister" on the
     * strength of the name alone, with the NID proving it sitting on another
     * screen under a queue they had no reason to connect to this one.
     */
    public function test_the_approval_screen_lists_what_was_attached_to_the_request(): void
    {
        $member = $this->member();
        $token = $this->memberToken($member);

        $this->postJson('/api/v1/me/profile-updates', [
            'nominee' => ['name' => 'Firoza Khatun', 'relation' => 'Sister'],
        ], $this->headers($token))->assertStatus(201);

        $this->post('/api/v1/me/nominee-documents', [
            'slot' => 'nid_front',
            'file' => $this->photo(),
        ], $this->headers($token))->assertStatus(201);

        $response = $this->getJson('/api/v1/staff/profile-updates', $this->headers($this->staffToken()))
            ->assertOk();

        $attachments = $response->json('data.0.attachments');

        self::assertCount(1, $attachments, 'The officer cannot see what came with the request.');
        self::assertSame('nid_front', $attachments[0]['slot']);
        self::assertSame('NID front', $attachments[0]['label']);
    }

    /**
     * AND IT IS LABELLED AS A NOMINEE'S IN THE DOCUMENT QUEUE, not an unnamed
     * member's.
     *
     * The queue asked only whether the owner was a Nominee, so a file attached
     * to a pending request fell through to 'member' and showed a blank where
     * the person should be - on the one document an officer most needs to
     * identify.
     */
    public function test_a_request_attachment_is_labelled_with_the_nominee_it_names(): void
    {
        $member = $this->member();
        $token = $this->memberToken($member);

        $this->postJson('/api/v1/me/profile-updates', [
            'nominee' => ['name' => 'Firoza Khatun'],
        ], $this->headers($token))->assertStatus(201);

        $this->post('/api/v1/me/nominee-documents', [
            'slot' => 'image',
            'file' => $this->photo(),
        ], $this->headers($token))->assertStatus(201);

        $row = collect(
            $this->getJson('/api/v1/staff/document-reviews', $this->headers($this->staffToken()))
                ->assertOk()
                ->json('data')
        )->firstWhere('slot', 'image');

        self::assertSame('nominee', $row['owner_type']);
        self::assertSame('Firoza Khatun', $row['owner_name']);

        // Said plainly, because it changes what the decision means: this file
        // attaches to a nominee that only exists if the request is approved.
        self::assertTrue($row['awaiting_request']);
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
