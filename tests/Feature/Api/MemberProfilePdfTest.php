<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Tenant\AssociatorInfo;
use App\Models\Tenant\Document;
use App\Models\Tenant\Member;
use App\Models\Tenant\Nominee;
use App\Models\User;
use App\Reports\MemberProfileRenderer;
use App\Services\TenantSeedService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Storage;
use Tests\Support\TenantFixtures;
use Tests\TenantTestCase;

/**
 * One member's record as a PDF (legacy `member-pdf/{id}`).
 *
 * TESTED AT TWO LAYERS, DELIBERATELY.
 *
 * The endpoint is asked whether it returns a PDF, names the file the way the
 * office files it, and refuses the accounts it should. The CONTENT is asserted
 * against the renderer's HTML, which is the layer where every decision worth
 * getting right is actually made.
 *
 * The first version of this file asserted content against the PDF bytes. mPDF
 * FlateDecodes its streams and breaks every word into positioned fragments, so
 * "Suspended" arrives as `(Su) -20 (spended)`: a test that has to inflate and
 * reassemble before it can search is testing mPDF, not this code, and it fails
 * in ways that say nothing about the defect.
 *
 * What these pin, being the things that would be wrong in a way nobody notices
 * until a member is at the counter holding the printout:
 *
 *   - the STATUS is on it. The legacy profile of a suspended member is
 *     indistinguishable from an active one;
 *   - EVERY nominee is on it. The legacy reads `$member->nominee`, a hasOne;
 *   - a photograph is embedded from the LIVE document only, never one still
 *     awaiting review;
 *   - taking the record away as a file needs more than permission to read the
 *     screen.
 */
class MemberProfilePdfTest extends TenantTestCase
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

    private int $sequence = 0;

    private function staffToken(): string
    {
        $sequence = ++$this->sequence;

        return $this->inTenant(function () use ($sequence) {
            app(TenantSeedService::class)->seedAll();

            $user = User::create([
                'name' => "Clerk {$sequence}",
                'email' => "clerk{$sequence}@assoc.test",
                'password' => 'secret-password',
            ]);
            $user->assignRole('superadmin');

            return $user->createToken('test', $user->getAllPermissions()->pluck('name')->all())
                ->plainTextToken;
        });
    }

    /**
     * An account holding exactly these permissions, via a role of its own.
     *
     * A NARROW ROLE, NOT A NARROW TOKEN, and the first version of this file got
     * that wrong: it minted a superadmin's token with fewer abilities and both
     * refusal tests passed with a 200. Spatie's `permission:` middleware asks
     * the USER what they may do; Sanctum's abilities are a separate gate that
     * nothing on these routes consults. A token cannot stand in for a role.
     *
     * @param  list<string>  $permissions
     */
    private function tokenWithPermissions(array $permissions): string
    {
        $sequence = ++$this->sequence;

        return $this->inTenant(function () use ($permissions, $sequence) {
            app(TenantSeedService::class)->seedAll();

            $role = Role::findOrCreate("profile-narrow-{$sequence}", 'web');
            $role->syncPermissions(
                array_map(fn (string $p) => Permission::findByName($p, 'web'), $permissions)
            );

            $user = User::create([
                'name' => "Narrow {$sequence}",
                'email' => "profilenarrow{$sequence}@assoc.test",
                'password' => 'secret-password',
            ]);
            $user->assignRole($role);

            return $user->createToken('test', $user->getAllPermissions()->pluck('name')->all())
                ->plainTextToken;
        });
    }

    private function member(array $attributes = []): Member
    {
        $member = $this->makeMember($attributes + [
            'name' => 'Rahim Uddin',
            'father_name' => 'Karim Uddin',
            'nid' => '1234567890',
            'present_address' => 'Ibrahimpur, Dhaka',
        ]);

        AssociatorInfo::create([
            'member_id' => $member->id,
            'membership_no' => 'COCSOL-'.$member->id,
            'join_date' => '2024-01-15',
            'num_or_shares' => 12,
        ]);

        return $member->fresh();
    }

    /** The document as it stands before mPDF encodes it. */
    private function html(Member $member): string
    {
        return app(MemberProfileRenderer::class)->html($member, 'Test Association');
    }

    private function fetch(string $token, int $member)
    {
        return $this->withHeaders($this->headers($token))
            ->get("/api/v1/staff/members/{$member}/profile");
    }

    // ------------------------------------------------------------- the endpoint

    public function test_it_returns_a_pdf_as_an_attachment_named_for_the_membership_number(): void
    {
        $token = $this->staffToken();
        $id = $this->inTenant(fn () => $this->member()->id);

        $response = $this->fetch($token, $id)->assertStatus(200);

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());

        /*
         * `attachment`, unlike the receipt, which is `inline`. A profile
         * carries the member's NID and both addresses and is produced to be
         * filed; it should land in a folder somebody chose rather than open in
         * a tab on a shared office machine.
         */
        $this->assertSame(
            'attachment; filename="MemberProfile_COCSOL-'.$id.'.pdf"',
            $response->headers->get('Content-Disposition'),
        );

        // Identity data never belongs in a shared browser cache.
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    /** A member legitimately exists before the office numbers them. */
    public function test_an_unnumbered_member_is_named_by_a_padded_id(): void
    {
        $token = $this->staffToken();
        $id = $this->inTenant(fn () => $this->makeMember()->id);

        $this->fetch($token, $id)
            ->assertStatus(200)
            ->assertHeader(
                'Content-Disposition',
                'attachment; filename="MemberProfile_'.str_pad((string) $id, 8, '0', STR_PAD_LEFT).'.pdf"',
            );
    }

    /** A membership number is association-chosen free text; a filename is not. */
    public function test_a_membership_number_with_awkward_characters_is_made_safe(): void
    {
        $token = $this->staffToken();

        $id = $this->inTenant(function () {
            $member = $this->makeMember();

            AssociatorInfo::create([
                'member_id' => $member->id,
                'membership_no' => 'COC/2024 "01"',
            ]);

            return $member->id;
        });

        $this->fetch($token, $id)
            ->assertStatus(200)
            ->assertHeader('Content-Disposition', 'attachment; filename="MemberProfile_COC-2024-01-.pdf"');
    }

    // -------------------------------------------------------------- the content

    /**
     * THE STATUS IS ON THE PAGE.
     *
     * The legacy profile of a suspended member is indistinguishable from an
     * active one, and a suspended membership treated as current is the cost.
     */
    public function test_a_suspended_membership_says_so_on_the_page(): void
    {
        $this->inTenant(function () {
            $member = $this->member(['status' => Member::STATUS_SUSPENDED]);

            $this->assertStringContainsString('Suspended', $this->html($member));
        });
    }

    public function test_it_carries_the_member_and_the_society_record(): void
    {
        $this->inTenant(function () {
            $html = $this->html($this->member());

            $this->assertStringContainsString('Rahim Uddin', $html);
            $this->assertStringContainsString('Karim Uddin', $html);
            $this->assertStringContainsString('1234567890', $html);
            $this->assertStringContainsString('Ibrahimpur, Dhaka', $html);
            $this->assertStringContainsString('15 Jan 2024', $html);

            /*
             * "Shares held", not the legacy's "Number of installments". That
             * label sits over `num_or_shares` in the legacy template, which is
             * a different thing entirely and reads on the page as a count of
             * instalments somebody has paid.
             */
            $this->assertStringContainsString('Shares held', $html);
            $this->assertStringNotContainsString('Number of installments', $html);
        });
    }

    /**
     * It does not call itself an application.
     *
     * The legacy template is headed "Membership Application" and then fills the
     * form in from the record of an already-approved member, office-use box
     * included. An association that files both will not be able to tell them
     * apart a year later.
     */
    public function test_it_is_a_profile_rather_than_an_application(): void
    {
        $this->inTenant(function () {
            $html = $this->html($this->member());

            $this->assertStringContainsString('Member profile', $html);
            $this->assertStringNotContainsString('Membership Application', $html);
            $this->assertStringNotContainsString('Office use only', $html);
        });
    }

    /** A fact the association does not hold prints a dash, never a blank cell. */
    public function test_an_empty_field_prints_a_dash(): void
    {
        $this->inTenant(function () {
            // No mother's name, no spouse, no email, no permanent address.
            $html = $this->html($this->member());

            $this->assertStringContainsString('—', $html);
        });
    }

    /**
     * EVERY nominee, not the first.
     *
     * `$member->nominee` in the legacy is a hasOne. BCS records several with
     * the share each is to receive, and a profile that silently prints one of
     * two is worse than one that prints none: it looks complete.
     */
    public function test_it_prints_every_nominee_with_the_share_each_receives(): void
    {
        $this->inTenant(function () {
            $member = $this->member();

            Nominee::create([
                'member_id' => $member->id,
                'name' => 'Rahima Begum',
                'relation' => 'Wife',
                'father_name' => 'Abdul Karim',
                'share_percentage' => '60.00',
            ]);

            Nominee::create([
                'member_id' => $member->id,
                'name' => 'Sabina Yasmin',
                'relation' => 'Daughter',
                'share_percentage' => '40.00',
            ]);

            $html = $this->html($member->fresh());

            $this->assertStringContainsString('Rahima Begum', $html);
            $this->assertStringContainsString('Sabina Yasmin', $html);
            $this->assertStringContainsString('Abdul Karim', $html);

            /*
             * The share each is to receive, and the reason the document exists:
             * an association reading a profile after a death needs to know who
             * gets what, and that figure lives nowhere else a person would look.
             */
            $this->assertStringContainsString('60%', $html);
            $this->assertStringContainsString('40%', $html);

            // Counted in the heading, so a reader can tell at a glance that
            // there is more than one and that none has scrolled off.
            $this->assertStringContainsString('(2)', $html);
        });
    }

    /** A gap the association has to close is said, not omitted. */
    public function test_a_member_with_no_nominee_says_so(): void
    {
        $this->inTenant(function () {
            $this->assertStringContainsString(
                'No nominee has been recorded',
                $this->html($this->member()),
            );
        });
    }

    /**
     * The introducer, resolved through the link rather than copied text.
     *
     * Which is what stops "Md. Riaz uddin" and "Md. Riaz Uddin" appearing as
     * two different people on two printed profiles.
     */
    public function test_the_introducer_is_named_from_their_own_record(): void
    {
        $this->inTenant(function () {
            $introducer = $this->makeMember(['name' => 'Md. Riaz Uddin']);

            AssociatorInfo::create([
                'member_id' => $introducer->id,
                'membership_no' => 'COCSOL-0001',
            ]);

            $member = $this->member();
            $member->update(['introduced_by_member_id' => $introducer->id]);

            $html = $this->html($member->fresh());

            $this->assertStringContainsString('Md. Riaz Uddin', $html);
            $this->assertStringContainsString('COCSOL-0001', $html);
        });
    }

    /** And the one introducer who never joined is marked as such. */
    public function test_an_introducer_who_is_not_a_member_is_marked(): void
    {
        $this->inTenant(function () {
            $member = $this->member();
            $member->update(['introduced_by_name' => 'A. Non-Member']);

            $html = $this->html($member->fresh());

            $this->assertStringContainsString('A. Non-Member', $html);
            $this->assertStringContainsString('not a member', $html);
        });
    }

    /** Which documents are held and which are still missing. New here. */
    public function test_it_lists_every_document_slot_filled_or_not(): void
    {
        $this->inTenant(function () {
            $html = $this->html($this->member());

            $this->assertStringContainsString('Documents on file', $html);
            $this->assertStringContainsString('NID front', $html);
            $this->assertStringContainsString('Proof of joining cadre', $html);
        });
    }

    // ----------------------------------------------------------- photographs

    /**
     * A LIVE photograph is embedded; one awaiting review is not.
     *
     * Printing an unreviewed photograph onto an official profile would settle a
     * decision somebody else is supposed to make.
     */
    public function test_it_embeds_only_a_reviewed_photograph(): void
    {
        Storage::fake('local');

        $this->inTenant(function () {
            /*
             * Written INSIDE the tenant context. Tenancy re-roots the local
             * disk per association, so a file put outside it lands somewhere
             * the renderer will not look - and the profile then reports the
             * photograph as lost, which is a true statement about the wrong
             * thing and makes this test pass for no reason.
             */
            Storage::disk('local')->put('photos/face.png', $this->png());

            $waiting = $this->member(['mobile' => '01710000091']);
            $this->document($waiting, Document::STATUS_PENDING);

            $shown = $this->member(['mobile' => '01710000092']);
            $this->document($shown, Document::STATUS_LIVE);

            $this->assertStringContainsString(
                'No photograph on file',
                $this->html($waiting),
            );

            $embedded = $this->html($shown);
            $this->assertStringContainsString('data:image/png;base64,', $embedded);
            $this->assertStringNotContainsString('No photograph on file', $embedded);
        });
    }

    /**
     * A format mPDF cannot draw is explained, not emitted.
     *
     * DocumentService accepts HEIC because that is what a phone produces, and
     * turning members away at the counter over a file format would be absurd.
     * mPDF cannot draw it. A profile that fails to render is a worse outcome
     * than a profile with a line of explanation where the photograph goes.
     */
    public function test_an_unprintable_photograph_is_explained(): void
    {
        Storage::fake('local');

        $this->inTenant(function () {
            Storage::disk('local')->put('photos/face.heic', 'not really heic');

            $member = $this->member();
            $this->document($member, Document::STATUS_LIVE, 'photos/face.heic', 'image/heic');

            $this->assertStringContainsString(
                'format this document cannot show',
                $this->html($member),
            );
        });
    }

    /**
     * A row that outlived its file says so, and the document still renders.
     *
     * "No photograph" and "we lost the photograph" are different findings, and
     * only one of them is the member's to fix.
     */
    public function test_a_photograph_whose_file_has_gone_is_reported(): void
    {
        Storage::fake('local');

        $this->inTenant(function () {
            $member = $this->member();
            $this->document($member, Document::STATUS_LIVE, 'photos/never-written.png');

            $this->assertStringContainsString(
                'no longer stored',
                $this->html($member),
            );
        });
    }

    // ------------------------------------------------------ who may have it

    /**
     * Reading a member and taking their record away are different rights.
     *
     * `members.view` alone opens the detail screen and is refused here: the
     * file carries their NID, both addresses and their nominee's NID, and it
     * outlives the session that produced it.
     */
    public function test_reading_the_screen_is_not_enough_to_download_the_file(): void
    {
        $token = $this->tokenWithPermissions(['members.view']);
        $id = $this->inTenant(fn () => $this->member()->id);

        $this->fetch($token, $id)->assertStatus(403);
    }

    /** And `reports.export` alone must not reach a member either. */
    public function test_the_export_right_alone_is_not_enough(): void
    {
        $token = $this->tokenWithPermissions(['reports.export']);
        $id = $this->inTenant(fn () => $this->member()->id);

        $this->fetch($token, $id)->assertStatus(403);
    }

    /** Its own test - see the note in AccountStatementsTest on withHeaders(). */
    public function test_it_refuses_a_request_with_no_token(): void
    {
        $id = $this->inTenant(fn () => $this->member()->id);

        $this->getJson("/api/v1/staff/members/{$id}/profile", $this->headers())
            ->assertStatus(401);
    }

    public function test_a_member_who_does_not_exist_is_a_404(): void
    {
        $this->fetch($this->staffToken(), 999999)->assertStatus(404);
    }

    // ------------------------------------------------------------------ helpers

    private function document(
        Member $member,
        string $status,
        string $path = 'photos/face.png',
        string $mime = 'image/png',
    ): void {
        Document::create([
            'documentable_type' => Member::class,
            'documentable_id' => $member->id,
            'slot' => 'image',
            'status' => $status,
            'disk' => 'local',
            'path' => $path,
            'original_name' => basename($path),
            'mime' => $mime,
            'size' => 100,
        ]);
    }

    /** The smallest valid PNG - one opaque pixel. */
    private function png(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        );
    }
}
