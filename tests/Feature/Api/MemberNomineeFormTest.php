<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Tenant\Member;
use App\Models\Tenant\MemberProfileUpdate;
use App\Models\Tenant\Nominee;
use App\Models\User;
use App\Services\TenantSeedService;
use Tests\Support\TenantFixtures;
use Tests\TenantTestCase;

/**
 * The nominee section of the legacy form, reachable by the member.
 *
 * WHERE A MEMBER COULD ADD A NOMINEE BEFORE THIS: nowhere. Every nominee route
 * was behind `nominees.manage`, a staff permission - so the second tab of the
 * form a member fills in on the legacy system had no counterpart here at all.
 *
 * ONE PENDING ROW COVERS MEMBER AND NOMINEE, which is how the legacy does it:
 * its `member_profile_updates` table carries `name` and `nominee_name`, `nid`
 * and `nominee_nid`, side by side, and the office decides them together. That
 * is the right shape for what is being decided - changing your nominee is one
 * act, and two queue entries an officer could decide separately would let a
 * nominee's name through while their NID was refused.
 */
class MemberNomineeFormTest extends TenantTestCase
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

    private function member(array $attributes = []): Member
    {
        return $this->inTenant(function () use ($attributes) {
            app(TenantSeedService::class)->seedAll();

            return $this->makeMember($attributes);
        });
    }

    private function memberToken(Member $member): string
    {
        return $this->inTenant(
            fn () => $member->createToken('member', ['member.dues.view'])->plainTextToken
        );
    }

    private function staffToken(): string
    {
        return $this->inTenant(function () {
            app(TenantSeedService::class)->seedAll();

            static $n = 0;
            $n++;

            $user = User::create([
                'name' => "Officer {$n}",
                'email' => "nominee-form{$n}@assoc.test",
                'password' => 'secret-password',
            ]);
            $user->assignRole('superadmin');

            return $user->createToken('test', $user->getAllPermissions()->pluck('name')->all())
                ->plainTextToken;
        });
    }

    private function decide(int $id, string $decision, ?string $reason = null): void
    {
        $this->postJson(
            "/api/v1/staff/profile-updates/{$id}/decide",
            array_filter(['decision' => $decision, 'reason' => $reason]),
            $this->headers($this->staffToken()),
        )->assertOk();
    }

    private function latestUpdateId(): int
    {
        return $this->inTenant(fn () => MemberProfileUpdate::query()->latest('id')->value('id'));
    }

    // ---- adding one -------------------------------------------------------

    public function test_a_member_can_ask_to_add_a_nominee_and_nothing_exists_yet(): void
    {
        $member = $this->member();

        $this->postJson('/api/v1/me/profile-updates', [
            'nominee' => [
                'name' => 'Rahima Begum',
                'relation' => 'Wife',
                'nid' => '1234567890',
                'mobile' => '01712000001',
            ],
        ], $this->headers($this->memberToken($member)))
            ->assertStatus(201)
            ->assertJsonPath('data.changes.nominee_name', 'Rahima Begum')
            ->assertJsonPath('data.changes.nominee_relation', 'Wife');

        // Proposed, not created. A pending request changes nothing.
        $this->inTenant(fn () => self::assertSame(0, Nominee::query()->count()));
    }

    public function test_approval_creates_the_nominee(): void
    {
        $member = $this->member();

        $this->postJson('/api/v1/me/profile-updates', [
            'nominee' => [
                'name' => 'Rahima Begum',
                'relation' => 'Wife',
                'father_name' => 'Abdul Karim',
                'address' => 'Ibrahimpur, Dhaka',
                'profession' => 'Teacher',
            ],
        ], $this->headers($this->memberToken($member)))->assertStatus(201);

        $this->decide($this->latestUpdateId(), 'approve');

        $this->inTenant(function () use ($member) {
            $nominee = $member->nominees()->first();

            self::assertNotNull($nominee, 'Approval did not create the nominee.');
            self::assertSame('Rahima Begum', $nominee->name);
            self::assertSame('Wife', $nominee->relation);
            self::assertSame('Abdul Karim', $nominee->father_name);
            // The legacy names these differently - `permanent_address` and
            // `professional_details` - and they land here.
            self::assertSame('Ibrahimpur, Dhaka', $nominee->address);
            self::assertSame('Teacher', $nominee->profession);
        });
    }

    /**
     * A NOMINEE CANNOT BE CREATED WITHOUT A NAME, and it is said here rather
     * than at approval.
     *
     * `nominees.name` is NOT NULL. Without this guard a member filling in a
     * relation and a mobile files a request an officer reads, believes and
     * approves - and the insert then fails with a database error on the
     * OFFICER'S screen, about the member's form. The member is told nothing
     * and the queue entry is left half-decided.
     */
    public function test_a_nominee_cannot_be_added_without_a_name(): void
    {
        $member = $this->member();

        $this->postJson('/api/v1/me/profile-updates', [
            'nominee' => ['relation' => 'Wife', 'mobile' => '01712000002'],
        ], $this->headers($this->memberToken($member)))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'NOMINEE_NAME_REQUIRED');
    }

    // ---- changing one -----------------------------------------------------

    public function test_a_member_can_correct_one_field_of_an_existing_nominee(): void
    {
        $member = $this->member();

        $this->inTenant(fn () => $member->nominees()->create([
            'name' => 'Rahima Begum',
            'relation' => 'Wife',
            'mobile' => '01712000003',
        ]));

        // No name in the request: they already have a nominee, so they are not
        // naming anybody again.
        $this->postJson('/api/v1/me/profile-updates', [
            'nominee' => ['mobile' => '01712000009'],
        ], $this->headers($this->memberToken($member)))
            ->assertStatus(201)
            ->assertJsonPath('data.changes.nominee_mobile', '01712000009');

        $this->decide($this->latestUpdateId(), 'approve');

        $this->inTenant(function () use ($member) {
            $nominee = $member->nominees()->first();

            self::assertSame('01712000009', $nominee->mobile);
            // Untouched, because it was not in the request.
            self::assertSame('Rahima Begum', $nominee->name);
        });
    }

    /** A field already holding that value is not a change. */
    public function test_resubmitting_the_same_nominee_changes_nothing(): void
    {
        $member = $this->member();

        $this->inTenant(fn () => $member->nominees()->create([
            'name' => 'Rahima Begum',
            'relation' => 'Wife',
        ]));

        $this->postJson('/api/v1/me/profile-updates', [
            'nominee' => ['name' => 'Rahima Begum', 'relation' => 'Wife'],
        ], $this->headers($this->memberToken($member)))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'NOTHING_TO_CHANGE');
    }

    /**
     * THE FIRST NOMINEE, and only the first.
     *
     * The legacy member form has exactly one; this schema lets STAFF record
     * several. The member's own form maintains theirs and leaves the others
     * alone, rather than silently editing whichever row came back first.
     */
    public function test_a_second_nominee_recorded_by_staff_is_left_alone(): void
    {
        $member = $this->member();

        $this->inTenant(function () use ($member) {
            $member->nominees()->create(['name' => 'First Person', 'relation' => 'Wife']);
            $member->nominees()->create(['name' => 'Second Person', 'relation' => 'Son']);
        });

        $this->postJson('/api/v1/me/profile-updates', [
            'nominee' => ['relation' => 'Spouse'],
        ], $this->headers($this->memberToken($member)))->assertStatus(201);

        $this->decide($this->latestUpdateId(), 'approve');

        $this->inTenant(function () use ($member) {
            $nominees = $member->nominees()->orderBy('id')->get();

            self::assertCount(2, $nominees);
            self::assertSame('Spouse', $nominees[0]->relation);
            self::assertSame('Son', $nominees[1]->relation, 'The second nominee was edited.');
        });
    }

    // ---- one decision, both halves ----------------------------------------

    /**
     * A member changing their own address and their nominee's in one go is
     * ONE request, decided once - which is the legacy's arrangement and the
     * reason nominee fields live in the same row.
     */
    public function test_member_and_nominee_changes_travel_in_one_request(): void
    {
        $member = $this->member(['present_address' => 'Old Street']);

        $this->inTenant(fn () => $member->nominees()->create(['name' => 'Rahima Begum']));

        $this->postJson('/api/v1/me/profile-updates', [
            'present_address' => 'New Street',
            'nominee' => ['name' => 'Rahima Khatun'],
        ], $this->headers($this->memberToken($member)))
            ->assertStatus(201)
            ->assertJsonPath('data.changes.present_address', 'New Street')
            ->assertJsonPath('data.changes.nominee_name', 'Rahima Khatun');

        self::assertSame(1, $this->inTenant(fn () => MemberProfileUpdate::query()->count()));

        $this->decide($this->latestUpdateId(), 'approve');

        $this->inTenant(function () use ($member) {
            self::assertSame('New Street', $member->fresh()->present_address);
            self::assertSame('Rahima Khatun', $member->nominees()->first()->name);
        });
    }

    /** A refusal applies neither half. */
    public function test_a_refused_request_leaves_the_nominee_alone(): void
    {
        $member = $this->member();

        $this->inTenant(fn () => $member->nominees()->create(['name' => 'Rahima Begum']));

        $this->postJson('/api/v1/me/profile-updates', [
            'nominee' => ['name' => 'Somebody Else'],
        ], $this->headers($this->memberToken($member)))->assertStatus(201);

        $this->decide($this->latestUpdateId(), 'reject', 'The NID does not match that name.');

        $this->inTenant(function () use ($member) {
            self::assertSame('Rahima Begum', $member->nominees()->first()->name);
        });
    }

    // ---- what the form renders from ---------------------------------------

    public function test_the_nominee_on_file_comes_back_on_me(): void
    {
        $member = $this->member();

        $this->inTenant(fn () => $member->nominees()->create([
            'name' => 'Rahima Begum',
            'relation' => 'Wife',
            'profession' => 'Teacher',
        ]));

        $response = $this->getJson('/api/v1/me', $this->headers($this->memberToken($member)))
            ->assertOk()
            ->assertJsonPath('data.profile.nominee.name', 'Rahima Begum')
            ->assertJsonPath('data.profile.nominee.relation', 'Wife')
            ->assertJsonPath('data.profile.nominee.profession', 'Teacher');

        foreach (MemberProfileUpdate::NOMINEE_ALLOWED as $field) {
            self::assertArrayHasKey(
                $field,
                $response->json('data.profile.nominee'),
                "[{$field}] is permitted but not shown, so the form cannot render it.",
            );
        }
    }

    /**
     * NULL, not an object of empty strings.
     *
     * "Add your nominee" and "correct these details" are different screens,
     * and the app can only tell them apart if the absence is explicit.
     */
    public function test_a_member_with_no_nominee_gets_null(): void
    {
        $member = $this->member();

        $response = $this->getJson('/api/v1/me', $this->headers($this->memberToken($member)))
            ->assertOk();

        self::assertNull($response->json('data.profile.nominee'));
    }

    /** Share allocation is not on the legacy form and is not proposed here. */
    public function test_a_member_cannot_propose_their_nominees_share(): void
    {
        self::assertNotContains('share_percentage', MemberProfileUpdate::NOMINEE_ALLOWED);

        $member = $this->member();

        $this->inTenant(fn () => $member->nominees()->create([
            'name' => 'Rahima Begum',
            'share_percentage' => 50,
        ]));

        $this->postJson('/api/v1/me/profile-updates', [
            'nominee' => ['name' => 'Rahima Khatun', 'share_percentage' => 100],
        ], $this->headers($this->memberToken($member)))->assertStatus(201);

        $this->decide($this->latestUpdateId(), 'approve');

        $this->inTenant(function () use ($member) {
            self::assertSame('50.00', (string) $member->nominees()->first()->share_percentage);
        });
    }
}
