<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Tenant\Member;
use App\Models\Tenant\MemberPreference;
use App\Models\Tenant\MemberProfileUpdate;
use App\Models\User;
use App\Services\TenantSeedService;
use Tests\Support\TenantFixtures;
use Tests\TenantTestCase;

/**
 * Member Choice - the third tab of the legacy form, reachable by the member.
 *
 * WHERE THEY COULD ANSWER IT BEFORE: nowhere. Every preference route sat
 * behind `member-preferences.manage`, a staff permission, so the tab a member
 * fills in on their own legacy dashboard had no counterpart here.
 *
 * ONE QUEUE, NOT TWO - and this is a deliberate departure from the legacy's
 * shape rather than from its behaviour. The legacy writes preferences into
 * their own pending table, `member_choice_updates`, because its
 * `member_profile_updates` is FLAT COLUMNS and could not hold three projects
 * in one row. It had no choice. This one holds JSON, and the thing being
 * decided is a single act: a member who corrects their address and their flat
 * size in one sitting filed one request, and splitting it across two queues
 * would let an officer approve half of it.
 */
class MemberChoiceFormTest extends TenantTestCase
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
                'email' => "choice-form{$n}@assoc.test",
                'password' => 'secret-password',
            ]);
            $user->assignRole('superadmin');

            return $user->createToken('test', $user->getAllPermissions()->pluck('name')->all())
                ->plainTextToken;
        });
    }

    private function decide(string $decision = 'approve', ?string $reason = null): void
    {
        $id = $this->inTenant(fn () => MemberProfileUpdate::query()->latest('id')->value('id'));

        $this->postJson(
            "/api/v1/staff/profile-updates/{$id}/decide",
            array_filter(['decision' => $decision, 'reason' => $reason]),
            $this->headers($this->staffToken()),
        )->assertOk();
    }

    // ---- answering a project ----------------------------------------------

    public function test_a_member_can_answer_a_project_and_nothing_is_stored_yet(): void
    {
        $member = $this->member();

        $this->postJson('/api/v1/me/profile-updates', [
            'preferences' => [
                'dhaka_city' => [
                    'areas' => ['Uttara', 'Mirpur'],
                    'budget' => '15-25',
                    'flat_size_sft' => 1200,
                    'loan_percentage' => 50,
                    'flats_wanted' => 1,
                ],
            ],
        ], $this->headers($this->memberToken($member)))
            ->assertStatus(201)
            ->assertJsonPath('data.changes.preference_dhaka_city:budget', '15-25')
            ->assertJsonPath('data.changes.preference_dhaka_city:flat_size_sft', 1200);

        $this->inTenant(fn () => self::assertSame(0, MemberPreference::query()->count()));
    }

    public function test_approval_writes_the_project_row(): void
    {
        $member = $this->member();

        $this->postJson('/api/v1/me/profile-updates', [
            'preferences' => [
                'dhaka_city' => [
                    'areas' => ['Uttara'],
                    'budget' => '25-35',
                    'flats_wanted' => 2,
                ],
            ],
        ], $this->headers($this->memberToken($member)))->assertStatus(201);

        $this->decide();

        $this->inTenant(function () use ($member) {
            $row = MemberPreference::where('member_id', $member->id)
                ->where('project', 'dhaka_city')
                ->first();

            self::assertNotNull($row, 'Approval did not write the preference.');
            self::assertSame(['Uttara'], $row->areas);
            self::assertSame('25-35', $row->budget);
            self::assertSame(2, $row->flats_wanted);
        });
    }

    /** Three projects in one request, as the legacy form sends them. */
    public function test_all_three_projects_travel_together(): void
    {
        $member = $this->member();

        $this->postJson('/api/v1/me/profile-updates', [
            'preferences' => [
                'dhaka_city' => ['budget' => '5-15'],
                'near_dhaka' => ['budget' => '15-25'],
                'other_district' => ['areas' => ['Rangpur'], 'budget' => '25-35'],
            ],
        ], $this->headers($this->memberToken($member)))->assertStatus(201);

        self::assertSame(1, $this->inTenant(fn () => MemberProfileUpdate::query()->count()));

        $this->decide();

        $this->inTenant(function () use ($member) {
            $rows = MemberPreference::where('member_id', $member->id)->get()->keyBy('project');

            self::assertCount(3, $rows);
            self::assertSame('5-15', $rows['dhaka_city']->budget);
            self::assertSame('15-25', $rows['near_dhaka']->budget);
            self::assertSame(['Rangpur'], $rows['other_district']->areas);
        });
    }

    // ---- the area rule differs by project ---------------------------------

    /**
     * A DISTRICT IS CHOSEN; AN AREA IS TYPED.
     *
     * For `other_district` the areas ARE districts - 64 of them, fixed - so a
     * value outside the list is a mistake worth refusing at the door. The
     * staff endpoint splits it exactly this way and the member's must agree,
     * or the same answer is legal from one screen and not the other.
     */
    public function test_a_district_outside_the_list_is_refused(): void
    {
        $member = $this->member();

        $this->postJson('/api/v1/me/profile-updates', [
            'preferences' => ['other_district' => ['areas' => ['Atlantis']]],
        ], $this->headers($this->memberToken($member)))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'UNKNOWN_AREA');
    }

    /** A Dhaka neighbourhood is free text: the next site is somewhere nobody has typed. */
    public function test_an_unlisted_dhaka_area_is_accepted(): void
    {
        $member = $this->member();

        $this->postJson('/api/v1/me/profile-updates', [
            'preferences' => ['dhaka_city' => ['areas' => ['Somewhere New']]],
        ], $this->headers($this->memberToken($member)))
            ->assertStatus(201);
    }

    public function test_an_unknown_project_is_refused(): void
    {
        $member = $this->member();

        $this->postJson('/api/v1/me/profile-updates', [
            'preferences' => ['moon_base' => ['budget' => '5-15']],
        ], $this->headers($this->memberToken($member)))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'UNKNOWN_PROJECT');
    }

    // ---- what counts as a change ------------------------------------------

    /**
     * ORDER IS NOT AN ANSWER.
     *
     * A member who reordered "Uttara, Mirpur" into "Mirpur, Uttara" has
     * changed nothing, and filing that gives an officer a request to read with
     * nothing in it. A string comparison of the two lists would call them
     * different.
     */
    public function test_reordering_the_areas_is_not_a_change(): void
    {
        $member = $this->member();

        $this->inTenant(fn () => MemberPreference::create([
            'member_id' => $member->id,
            'project' => 'dhaka_city',
            'areas' => ['Uttara', 'Mirpur'],
            'budget' => '15-25',
        ]));

        $this->postJson('/api/v1/me/profile-updates', [
            'preferences' => [
                'dhaka_city' => ['areas' => ['Mirpur', 'Uttara'], 'budget' => '15-25'],
            ],
        ], $this->headers($this->memberToken($member)))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'NOTHING_TO_CHANGE');
    }

    /**
     * Clearing ONE field of an answered project does not clear the project.
     *
     * The applier merges the incoming fields onto what is already there before
     * asking whether anything is left; judging the incoming fields alone would
     * delete a row the member was only editing.
     */
    public function test_clearing_one_field_keeps_the_rest_of_the_project(): void
    {
        $member = $this->member();

        $this->inTenant(fn () => MemberPreference::create([
            'member_id' => $member->id,
            'project' => 'dhaka_city',
            'areas' => ['Uttara'],
            'budget' => '15-25',
            'flats_wanted' => 2,
        ]));

        $this->postJson('/api/v1/me/profile-updates', [
            'preferences' => ['dhaka_city' => ['flats_wanted' => null]],
        ], $this->headers($this->memberToken($member)))->assertStatus(201);

        $this->decide();

        $this->inTenant(function () use ($member) {
            $row = MemberPreference::where('member_id', $member->id)->first();

            self::assertNotNull($row, 'The project row was deleted by clearing one field.');
            self::assertSame('15-25', $row->budget);
            self::assertNull($row->flats_wanted);
        });
    }

    /** A refusal writes nothing. */
    public function test_a_refused_request_leaves_the_preferences_alone(): void
    {
        $member = $this->member();

        $this->postJson('/api/v1/me/profile-updates', [
            'preferences' => ['dhaka_city' => ['budget' => '35+']],
        ], $this->headers($this->memberToken($member)))->assertStatus(201);

        $this->decide('reject', 'Not this round.');

        $this->inTenant(fn () => self::assertSame(0, MemberPreference::query()->count()));
    }

    // ---- one form, one request --------------------------------------------

    /**
     * The whole legacy form in one submission: the member, their nominee and
     * their housing preferences, decided once.
     */
    public function test_the_three_sections_travel_in_one_request(): void
    {
        $member = $this->member(['present_address' => 'Old Street']);

        $this->postJson('/api/v1/me/profile-updates', [
            'present_address' => 'New Street',
            'nominee' => ['name' => 'Rahima Begum', 'relation' => 'Wife'],
            'preferences' => ['near_dhaka' => ['budget' => '5-15', 'flats_wanted' => 1]],
        ], $this->headers($this->memberToken($member)))->assertStatus(201);

        self::assertSame(1, $this->inTenant(fn () => MemberProfileUpdate::query()->count()));

        $this->decide();

        $this->inTenant(function () use ($member) {
            self::assertSame('New Street', $member->fresh()->present_address);
            self::assertSame('Rahima Begum', $member->nominees()->first()->name);
            self::assertSame(
                '5-15',
                MemberPreference::where('member_id', $member->id)->first()->budget,
            );
        });
    }

    // ---- what an officer sees ---------------------------------------------

    /**
     * THE CURRENT VALUE COMES FROM THE RIGHT PLACE.
     *
     * `shape()` read `$member->{$field}` for every key, which is right for the
     * member's own columns and silently wrong for the rest: a `nominee_name`
     * key asked the MEMBER for a `nominee_name` attribute, got null, and
     * showed an officer a blank where the nominee's current name should be.
     * Approving a change you cannot see the shape of is what the pairs exist
     * to prevent.
     */
    public function test_an_officer_sees_the_current_nominee_and_preference_values(): void
    {
        $member = $this->member();

        $this->inTenant(function () use ($member) {
            $member->nominees()->create(['name' => 'Rahima Begum']);

            MemberPreference::create([
                'member_id' => $member->id,
                'project' => 'dhaka_city',
                'budget' => '5-15',
            ]);
        });

        $this->postJson('/api/v1/me/profile-updates', [
            'nominee' => ['name' => 'Rahima Khatun'],
            'preferences' => ['dhaka_city' => ['budget' => '35+']],
        ], $this->headers($this->memberToken($member)))->assertStatus(201);

        $response = $this->getJson('/api/v1/staff/profile-updates', $this->headers($this->staffToken()))
            ->assertOk();

        $fields = collect($response->json('data.0.fields'))->keyBy('field');

        self::assertSame('Rahima Begum', $fields['nominee_name']['current']);
        self::assertSame('Rahima Khatun', $fields['nominee_name']['proposed']);

        self::assertSame('5-15', $fields['preference_dhaka_city:budget']['current']);
        self::assertSame('35+', $fields['preference_dhaka_city:budget']['proposed']);
    }

    // ---- what the form renders from ---------------------------------------

    /** All three projects come back, answered or not. */
    public function test_me_carries_every_project(): void
    {
        $member = $this->member();

        $this->inTenant(fn () => MemberPreference::create([
            'member_id' => $member->id,
            'project' => 'other_district',
            'areas' => ['Rangpur'],
            'budget' => '15-25',
        ]));

        $response = $this->getJson('/api/v1/me', $this->headers($this->memberToken($member)))
            ->assertOk();

        foreach (array_keys(MemberPreference::PROJECTS) as $project) {
            self::assertArrayHasKey(
                $project,
                $response->json('data.profile.preferences'),
                "[{$project}] is missing, so the form cannot render its panel.",
            );
        }

        $response
            ->assertJsonPath('data.profile.preferences.other_district.budget', '15-25')
            ->assertJsonPath('data.profile.preferences.other_district.areas', ['Rangpur'])
            // Unanswered, but present - and `areas` is a list rather than null,
            // so the form binds to it without guarding.
            ->assertJsonPath('data.profile.preferences.dhaka_city.budget', null)
            ->assertJsonPath('data.profile.preferences.dhaka_city.areas', []);
    }

    /** The lists the form renders from, on no permission beyond being signed in. */
    public function test_a_member_can_read_the_option_lists(): void
    {
        $member = $this->member();

        $response = $this->getJson(
            '/api/v1/me/preference-options',
            $this->headers($this->memberToken($member)),
        )->assertOk();

        self::assertSame(MemberPreference::PROJECTS, $response->json('data.projects'));
        self::assertSame(MemberPreference::BUDGETS, $response->json('data.budgets'));
        self::assertNotEmpty($response->json('data.districts'));
        self::assertSame(MemberPreference::DHAKA_AREAS, $response->json('data.dhaka_areas'));
    }

    /** And the staff screen offers exactly the same lists, from the same place. */
    public function test_the_staff_and_member_option_lists_are_the_same(): void
    {
        $member = $this->member();

        $mine = $this->getJson(
            '/api/v1/me/preference-options',
            $this->headers($this->memberToken($member)),
        )->assertOk()->json('data');

        $theirs = $this->getJson(
            '/api/v1/staff/member-preferences/options',
            $this->headers($this->staffToken()),
        )->assertOk()->json('data');

        self::assertSame($theirs, $mine);
    }
}
