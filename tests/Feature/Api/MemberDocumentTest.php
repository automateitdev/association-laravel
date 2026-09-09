<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Tenant\Document;
use App\Models\Tenant\Nominee;
use App\Models\User;
use App\Services\DocumentService;
use App\Services\TenantSeedService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\TenantFixtures;
use Tests\TenantTestCase;

/**
 * Member and nominee identity documents (parity P-10).
 *
 * WHAT IS WORTH TESTING HERE is not that a file uploads. It is the handful of
 * ways this could look finished and be wrong, and every one of them is about a
 * photograph of somebody's identity card:
 *
 *   - a slot accumulating instead of replacing, so a member has three NID
 *     fronts and nobody knows which is current;
 *   - a renamed file passing as an image, because the client said so;
 *   - an account that may READ the register being able to replace the
 *     photograph the association identifies people by;
 *   - one member reaching another's documents;
 *   - the ADR-0011 fallback silently losing the file it was meant to save.
 */
class MemberDocumentTest extends TenantTestCase
{
    use TenantFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inTenant(fn () => app(TenantSeedService::class)->seedAll());
    }

    private function headers(?string $token = null): array
    {
        return array_filter([
            'X-Tenant' => $this->slug(),
            'Accept' => 'application/json',
            'Authorization' => $token ? "Bearer {$token}" : null,
        ]);
    }

    private function staffToken(string $role = 'superadmin'): string
    {
        return $this->inTenant(function () use ($role) {
            static $sequence = 0;
            $sequence++;

            $user = User::create([
                'name' => "Staff {$sequence}",
                'email' => "staff{$sequence}@assoc.test",
                'password' => 'secret-password',
            ]);
            $user->assignRole($role);

            return $user->createToken('test', $user->getAllPermissions()->pluck('name')->all())
                ->plainTextToken;
        });
    }

    /**
     * The abilities AuthController actually grants a member.
     *
     * Not `['member']`. The submit route is gated on
     * `member.profile.request-change`, and a token minted with a made-up
     * ability would pass a test the real app fails.
     */
    private function memberToken(int $memberId): string
    {
        return $this->inTenant(
            fn () => \App\Models\Tenant\Member::findOrFail($memberId)
                ->createToken('test', [
                    'member.profile.view',
                    'member.profile.request-change',
                    'member.dues.view',
                    'member.payments.view',
                    'member.payments.create',
                ])->plainTextToken
        );
    }

    private function photo(string $name = 'nid.jpg'): UploadedFile
    {
        // A real JPEG, because the guard sniffs the CONTENT. A text file named
        // .jpg would be refused, which is the point of the test below.
        return UploadedFile::fake()->image($name, 400, 300);
    }

    private function upload(string $token, int $memberId, string $slot, ?UploadedFile $file = null)
    {
        return $this->withHeaders($this->headers($token))
            ->post("/api/v1/staff/members/{$memberId}/documents", [
                'slot' => $slot,
                'file' => $file ?? $this->photo(),
            ]);
    }

    // ------------------------------------------------------------ the happy path

    public function test_staff_can_file_a_member_document_and_read_it_back(): void
    {
        $token = $this->staffToken();
        $member = $this->inTenant(fn () => $this->makeMember());

        $this->upload($token, $member->id, 'nid_front')->assertCreated();

        $listed = $this->withHeaders($this->headers($token))
            ->getJson("/api/v1/staff/members/{$member->id}/documents")
            ->assertOk()
            ->json('data');

        $front = collect($listed)->firstWhere('slot', 'nid_front');

        $this->assertTrue($front['uploaded']);
        $this->assertSame('NID front', $front['label']);

        // EVERY slot is listed, filled or not: a screen that shows only what
        // exists cannot answer "what is this member still missing".
        $this->assertCount(count(DocumentService::MEMBER_SLOTS), $listed);
        $this->assertFalse(collect($listed)->firstWhere('slot', 'signature')['uploaded']);

        // The path is ours, not the client's.
        $this->assertArrayNotHasKey('path', $front);
        $this->assertArrayNotHasKey('disk', $front);

        $this->withHeaders($this->headers($token))
            ->get("/api/v1/staff/members/{$member->id}/documents/nid_front")
            ->assertOk()
            ->assertHeader('content-type', 'image/jpeg');
    }

    /**
     * A SLOT HOLDS ONE DOCUMENT. Uploading a second replaces the first and
     * removes the file behind it - a member with three NID fronts and no way to
     * tell which is current is worse than a member with none.
     */
    public function test_uploading_the_same_slot_replaces_rather_than_accumulates(): void
    {
        $token = $this->staffToken();
        $member = $this->inTenant(fn () => $this->makeMember());

        $this->upload($token, $member->id, 'nid_front', $this->photo('first.jpg'))->assertCreated();

        $first = $this->inTenant(fn () => Document::firstOrFail());

        $this->upload($token, $member->id, 'nid_front', $this->photo('second.jpg'))->assertCreated();

        $this->inTenant(function () use ($first) {
            $this->assertSame(1, Document::count(), 'The slot accumulated instead of replacing.');

            $current = Document::firstOrFail();
            $this->assertSame('second.jpg', $current->original_name);

            // And the superseded file is gone, not orphaned on the disk.
            $this->assertFalse(
                Storage::disk($first->disk)->exists($first->path),
                'The replaced file was left behind.'
            );
        });
    }

    // ------------------------------------------------------------ what is refused

    /**
     * THE MIME IS SNIFFED, NOT BELIEVED. A renamed .php is not a jpeg, and the
     * client's word for it is the one thing that must not decide.
     *
     * NOT `UploadedFile::fake()`, and that is not fussiness. Laravel's testing
     * file overrides getMimeType() to return `MimeType::from($this->name)` - it
     * guesses from the FILENAME. A fake called `nid.jpg` therefore reports
     * `image/jpeg` whatever is inside it, so a test written with one passes
     * against a guard that does no sniffing at all. A real UploadedFile over a
     * real temp file is the only way to exercise the thing being claimed.
     */
    public function test_a_file_that_is_not_an_image_is_refused_whatever_it_is_called(): void
    {
        $token = $this->staffToken();
        $member = $this->inTenant(fn () => $this->makeMember());

        $path = tempnam(sys_get_temp_dir(), 'doc');
        file_put_contents($path, '<?php echo "not an image";');

        $disguised = new UploadedFile(
            $path,
            'nid.jpg',
            // What the CLIENT claims. The guard must ignore this.
            'image/jpeg',
            null,
            test: true,
        );

        $this->upload($token, $member->id, 'nid_front', $disguised)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'DOCUMENT_REJECTED');

        $this->inTenant(fn () => $this->assertSame(0, Document::count()));

        @unlink($path);
    }

    public function test_an_unknown_slot_is_refused_and_says_which_are_real(): void
    {
        $token = $this->staffToken();
        $member = $this->inTenant(fn () => $this->makeMember());

        $this->upload($token, $member->id, 'passport')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'DOCUMENT_REJECTED');
    }

    public function test_an_oversized_document_is_refused(): void
    {
        $token = $this->staffToken();
        $member = $this->inTenant(fn () => $this->makeMember());

        $huge = UploadedFile::fake()->image('big.jpg')->size(
            (int) (DocumentService::MAX_BYTES / 1024) + 1024
        );

        $this->upload($token, $member->id, 'image', $huge)->assertStatus(422);
    }

    // ------------------------------------------------------------- who may do what

    /**
     * READING THE REGISTER IS NOT FILING IN IT.
     *
     * The photograph and the NID are how the association proves who somebody
     * is. An account granted members.view to answer the phone should not be
     * able to replace them.
     */
    public function test_view_permission_alone_cannot_upload(): void
    {
        // A role that reads the register and nothing more. The seeded
        // `operator` is narrower still - dashboard and share transfer only - so
        // it would fail the read as well and prove nothing about the split.
        $this->inTenant(function () {
            \Spatie\Permission\Models\Role::findOrCreate('desk', 'web')
                ->syncPermissions(['members.view']);
        });

        $token = $this->staffToken('desk');
        $member = $this->inTenant(fn () => $this->makeMember());

        $this->withHeaders($this->headers($token))
            ->getJson("/api/v1/staff/members/{$member->id}/documents")
            ->assertOk();

        $this->upload($token, $member->id, 'image')->assertForbidden();
    }

    public function test_a_member_reads_their_own_and_cannot_upload(): void
    {
        $staff = $this->staffToken();
        $member = $this->inTenant(fn () => $this->makeMember());

        $this->upload($staff, $member->id, 'image')->assertCreated();

        $token = $this->memberToken($member->id);

        $this->withHeaders($this->headers($token))
            ->getJson('/api/v1/me/documents')
            ->assertOk()
            ->assertJsonPath('data.0.slot', 'image')
            ->assertJsonPath('data.0.uploaded', true);

        $this->withHeaders($this->headers($token))
            ->get('/api/v1/me/documents/image')
            ->assertOk();

        // Changing what the association identifies you by goes through the
        // profile-update queue, which does not carry files yet.
        $this->withHeaders($this->headers($token))
            ->post("/api/v1/staff/members/{$member->id}/documents", [
                'slot' => 'image',
                'file' => $this->photo(),
            ])
            ->assertForbidden();
    }

    public function test_a_member_cannot_reach_another_members_documents(): void
    {
        $staff = $this->staffToken();
        $mine = $this->inTenant(fn () => $this->makeMember());
        $theirs = $this->inTenant(fn () => $this->makeMember(['mobile' => '01700000123']));

        $this->upload($staff, $theirs->id, 'nid_front')->assertCreated();

        $this->withHeaders($this->headers($this->memberToken($mine->id)))
            ->getJson("/api/v1/staff/members/{$theirs->id}/documents")
            ->assertForbidden();
    }

    // ----------------------------------------------------------------- nominees

    public function test_a_nominee_carries_its_own_documents(): void
    {
        $token = $this->staffToken();

        $nominee = $this->inTenant(function () {
            $member = $this->makeMember();

            return Nominee::create(['member_id' => $member->id, 'name' => 'Rokeya Begum']);
        });

        $this->withHeaders($this->headers($token))
            ->post("/api/v1/staff/nominees/{$nominee->id}/documents", [
                'slot' => 'nid_front',
                'file' => $this->photo(),
            ])
            ->assertCreated();

        $listed = $this->withHeaders($this->headers($token))
            ->getJson("/api/v1/staff/nominees/{$nominee->id}/documents")
            ->assertOk()
            ->json('data');

        // Fewer slots than a member: a nominee is identified, not enrolled.
        $this->assertCount(count(DocumentService::NOMINEE_SLOTS), $listed);
        $this->assertTrue(collect($listed)->firstWhere('slot', 'nid_front')['uploaded']);
    }

    // ------------------------------------------------------- member submissions

    private function submit(string $token, string $slot, ?UploadedFile $file = null)
    {
        return $this->withHeaders($this->headers($token))
            ->post('/api/v1/me/documents', [
                'slot' => $slot,
                'file' => $file ?? $this->photo(),
            ]);
    }

    /**
     * A SUBMISSION DOES NOT CHANGE WHAT THE ASSOCIATION HOLDS.
     *
     * That is the whole point of the queue: the office keeps working from the
     * NID it already has while the member's replacement waits, rather than the
     * register briefly holding whatever was last uploaded by whoever had the
     * phone.
     */
    public function test_a_member_submission_waits_and_leaves_the_live_document_alone(): void
    {
        $staff = $this->staffToken();
        $member = $this->inTenant(fn () => $this->makeMember());

        $this->upload($staff, $member->id, 'nid_front', $this->photo('office.jpg'))->assertCreated();

        $token = $this->memberToken($member->id);
        $this->submit($token, 'nid_front', $this->photo('mine.jpg'))->assertCreated();

        $slot = collect(
            $this->withHeaders($this->headers($token))->getJson('/api/v1/me/documents')->json('data')
        )->firstWhere('slot', 'nid_front');

        $this->assertTrue($slot['pending'], 'The submission is not showing as waiting.');

        // Still the one the office filed.
        $this->assertSame('office.jpg', $slot['original_name']);

        $this->inTenant(function () {
            $this->assertSame(1, Document::where('status', 'live')->count());
            $this->assertSame(1, Document::where('status', 'pending')->count());
        });
    }

    public function test_submitting_again_replaces_the_waiting_one_rather_than_queueing_twice(): void
    {
        $member = $this->inTenant(fn () => $this->makeMember());
        $token = $this->memberToken($member->id);

        $this->submit($token, 'image', $this->photo('first.jpg'))->assertCreated();
        $this->submit($token, 'image', $this->photo('second.jpg'))->assertCreated();

        $this->inTenant(function () {
            $pending = Document::where('status', 'pending')->get();

            $this->assertCount(1, $pending, 'The member appears twice in the queue for one slot.');
            $this->assertSame('second.jpg', $pending->first()->original_name);
        });
    }

    // ------------------------------------------------------------- the decision

    public function test_approving_makes_the_submission_the_document_on_file(): void
    {
        $staff = $this->staffToken();
        $member = $this->inTenant(fn () => $this->makeMember());

        $this->upload($staff, $member->id, 'nid_front', $this->photo('office.jpg'))->assertCreated();
        $superseded = $this->inTenant(fn () => Document::where('status', 'live')->firstOrFail());

        $this->submit($this->memberToken($member->id), 'nid_front', $this->photo('mine.jpg'));

        $queued = $this->withHeaders($this->headers($staff))
            ->getJson('/api/v1/staff/document-reviews')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $queued);
        $this->assertSame('member', $queued[0]['owner_type']);
        $this->assertSame($member->name, $queued[0]['owner_name']);

        $this->withHeaders($this->headers($staff))
            ->postJson("/api/v1/staff/document-reviews/{$queued[0]['id']}/decide", [
                'decision' => 'approved',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'live');

        $this->inTenant(function () use ($superseded) {
            $live = Document::where('status', 'live')->get();

            $this->assertCount(1, $live, 'Approval left two live documents in one slot.');
            $this->assertSame('mine.jpg', $live->first()->original_name);
            $this->assertSame(0, Document::where('status', 'pending')->count());

            // The one it replaced is gone, file and all.
            $this->assertNull(Document::find($superseded->id));
            $this->assertFalse(Storage::disk($superseded->disk)->exists($superseded->path));
        });
    }

    /**
     * A REFUSAL WITHOUT A REASON IS NO USE TO THE MEMBER.
     *
     * They cannot tell whether to photograph it again, send something else, or
     * come to the office - so the reason is required, and it comes back to them.
     */
    public function test_rejecting_requires_a_reason_and_tells_the_member_what_it_was(): void
    {
        $staff = $this->staffToken();
        $member = $this->inTenant(fn () => $this->makeMember());
        $token = $this->memberToken($member->id);

        $this->submit($token, 'nid_front')->assertCreated();
        $pending = $this->inTenant(fn () => Document::where('status', 'pending')->firstOrFail());

        $this->withHeaders($this->headers($staff))
            ->postJson("/api/v1/staff/document-reviews/{$pending->id}/decide", ['decision' => 'rejected'])
            ->assertStatus(422);

        $this->withHeaders($this->headers($staff))
            ->postJson("/api/v1/staff/document-reviews/{$pending->id}/decide", [
                'decision' => 'rejected',
                'reason' => 'The number is not readable. Please photograph it in better light.',
            ])
            ->assertOk();

        $slot = collect(
            $this->withHeaders($this->headers($token))->getJson('/api/v1/me/documents')->json('data')
        )->firstWhere('slot', 'nid_front');

        $this->assertFalse($slot['pending']);
        $this->assertStringContainsString('not readable', (string) $slot['rejected_reason']);

        // The metadata stays so the member can see what happened; the file does
        // not, because the association has refused it.
        $this->inTenant(function () use ($pending) {
            $rejected = Document::findOrFail($pending->id);

            $this->assertSame('rejected', $rejected->status);
            $this->assertNull($rejected->path);
            $this->assertFalse(Storage::disk($pending->disk)->exists($pending->path));
        });
    }

    public function test_a_document_cannot_be_decided_twice(): void
    {
        $staff = $this->staffToken();
        $member = $this->inTenant(fn () => $this->makeMember());

        $this->submit($this->memberToken($member->id), 'signature')->assertCreated();
        $pending = $this->inTenant(fn () => Document::where('status', 'pending')->firstOrFail());

        $decide = fn () => $this->withHeaders($this->headers($staff))
            ->postJson("/api/v1/staff/document-reviews/{$pending->id}/decide", ['decision' => 'approved']);

        $decide()->assertOk();
        $decide()->assertStatus(409)->assertJsonPath('error.code', 'ALREADY_DECIDED');
    }

    /** A resubmission after a refusal clears the old complaint from the screen. */
    public function test_submitting_again_after_a_refusal_replaces_the_reason_shown(): void
    {
        $staff = $this->staffToken();
        $member = $this->inTenant(fn () => $this->makeMember());
        $token = $this->memberToken($member->id);

        $this->submit($token, 'nid_back')->assertCreated();
        $pending = $this->inTenant(fn () => Document::where('status', 'pending')->firstOrFail());

        $this->withHeaders($this->headers($staff))
            ->postJson("/api/v1/staff/document-reviews/{$pending->id}/decide", [
                'decision' => 'rejected',
                'reason' => 'Blurred.',
            ])
            ->assertOk();

        $this->submit($token, 'nid_back')->assertCreated();

        $slot = collect(
            $this->withHeaders($this->headers($token))->getJson('/api/v1/me/documents')->json('data')
        )->firstWhere('slot', 'nid_back');

        $this->assertTrue($slot['pending']);
        $this->assertNull(
            $slot['rejected_reason'],
            'The screen still carries a complaint about a document that has been replaced.'
        );
    }

    // -------------------------------------------------------------- ADR-0011

    /**
     * THE FALLBACK SAVES THE FILE, AND SAYS WHERE IT PUT IT.
     *
     * A member who has photographed their NID and pressed Submit must not lose
     * it because a bucket was unreachable. What makes that safe rather than a
     * trap is the disk being recorded: with two possible destinations, a reader
     * that assumes one is wrong half the time.
     */
    public function test_a_failed_write_to_the_preferred_disk_falls_back_to_local(): void
    {
        // A disk name nothing defines. Resolving it throws, which is the
        // closest thing to "S3 is unreachable" that needs no network.
        config(['documents.disk' => 'a-disk-that-does-not-exist']);

        $token = $this->staffToken();
        $member = $this->inTenant(fn () => $this->makeMember());

        $this->upload($token, $member->id, 'nid_front')->assertCreated();

        $this->inTenant(function () {
            $document = Document::firstOrFail();

            $this->assertSame('local', $document->disk, 'The fallback disk was not recorded.');
            $this->assertTrue(
                Storage::disk($document->disk)->exists($document->path),
                'The fallback reported success without writing anything.'
            );
        });

        // And it is readable afterwards, which is the whole point of recording
        // the disk rather than assuming the preferred one.
        $this->withHeaders($this->headers($token))
            ->get("/api/v1/staff/members/{$member->id}/documents/nid_front")
            ->assertOk();
    }

    public function test_deleting_a_document_removes_the_row_and_the_file(): void
    {
        $token = $this->staffToken();
        $member = $this->inTenant(fn () => $this->makeMember());

        $this->upload($token, $member->id, 'signature')->assertCreated();

        $stored = $this->inTenant(fn () => Document::firstOrFail());

        $this->withHeaders($this->headers($token))
            ->deleteJson("/api/v1/staff/members/{$member->id}/documents/signature")
            ->assertOk();

        $this->inTenant(function () use ($stored) {
            $this->assertSame(0, Document::count());
            $this->assertFalse(Storage::disk($stored->disk)->exists($stored->path));
        });
    }
}
