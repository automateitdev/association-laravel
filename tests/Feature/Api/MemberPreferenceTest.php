<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Tenant\MemberPreference;
use App\Models\User;
use App\Services\TenantSeedService;
use App\Support\Districts;
use Tests\Support\TenantFixtures;
use Tests\TenantTestCase;

/**
 * What a member wants from the association's housing (legacy `member_choices`).
 *
 * THE COUNT IS THE WHOLE POINT OF THIS FEATURE'S HISTORY. The first reading of
 * the legacy table called it the biggest gap in the sweep, on "988 rows, 273 of
 * 315 members have answered something". The real figure is **18 members**:
 * `member_choices` writes three rows per member at registration carrying
 * nothing but a project type, and counting rows "where any column is non-null"
 * counted the scaffolding.
 *
 * So `answered` is asserted hard here, in both directions. A row that exists is
 * not an answer, and an answer emptied out must not leave a row behind - that
 * is how the scaffolding got built in the first place.
 */
class MemberPreferenceTest extends TenantTestCase
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
                'email' => "prefs{$sequence}@assoc.test",
                'password' => 'secret-password',
            ]);
            $user->assignRole('superadmin');

            return $user->createToken('test', $user->getAllPermissions()->pluck('name')->all())
                ->plainTextToken;
        });
    }

    // ------------------------------------------------------------ the question

    /**
     * All three projects come back, answered or not.
     *
     * An empty one is an unanswered question. A screen that only listed the
     * rows that exist could not show a member what they have not been asked -
     * the same reasoning as listing every document slot, filled or not.
     */
    public function test_every_project_is_returned_even_when_nothing_is_recorded(): void
    {
        $token = $this->staffToken();
        $id = $this->inTenant(fn () => $this->makeMember()->id);

        $this->withHeaders($this->headers($token))
            ->getJson("/api/v1/staff/members/{$id}/preferences")
            ->assertStatus(200)
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.project', 'dhaka_city')
            ->assertJsonPath('data.0.answered', false)
            ->assertJsonPath('data.0.areas', [])
            ->assertJsonPath('meta.answered', 0);
    }

    public function test_it_records_an_answer_for_one_project(): void
    {
        $token = $this->staffToken();
        $id = $this->inTenant(fn () => $this->makeMember()->id);

        $this->withHeaders($this->headers($token))
            ->putJson("/api/v1/staff/members/{$id}/preferences/dhaka_city", [
                'areas' => ['Mohammadpur', 'Uttara'],
                'flat_size_sft' => 1800,
                'budget' => '15-25',
                'loan_percentage' => 50,
                'flats_wanted' => 1,
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.answered', true)
            ->assertJsonPath('data.areas', ['Mohammadpur', 'Uttara'])
            ->assertJsonPath('data.flat_size_sft', 1800)
            // The label comes with the key, so a screen never has to hold its
            // own copy of what `15-25` means.
            ->assertJsonPath('data.budget_label', 'Tk. 15–25 lakh');

        $this->withHeaders($this->headers($token))
            ->getJson("/api/v1/staff/members/{$id}/preferences")
            ->assertStatus(200)
            ->assertJsonPath('meta.answered', 1);
    }

    /**
     * A member answers differently per project, which is why there is a row for
     * each. Member 112 in the production data wants Basundhara inside Dhaka and
     * Chattogram outside it.
     */
    public function test_a_member_can_answer_each_project_differently(): void
    {
        $token = $this->staffToken();
        $id = $this->inTenant(fn () => $this->makeMember()->id);

        $this->withHeaders($this->headers($token))
            ->putJson("/api/v1/staff/members/{$id}/preferences/dhaka_city", [
                'areas' => ['Basundhara/Purbachal'],
            ])->assertStatus(200);

        $this->withHeaders($this->headers($token))
            ->putJson("/api/v1/staff/members/{$id}/preferences/other_district", [
                'areas' => ['Chattogram'],
            ])->assertStatus(200);

        $response = $this->withHeaders($this->headers($token))
            ->getJson("/api/v1/staff/members/{$id}/preferences")
            ->assertStatus(200)
            ->assertJsonPath('meta.answered', 2);

        $byProject = collect($response->json('data'))->keyBy('project');

        self::assertSame(['Basundhara/Purbachal'], $byProject['dhaka_city']['areas']);
        self::assertSame(['Chattogram'], $byProject['other_district']['areas']);
        self::assertFalse($byProject['near_dhaka']['answered']);
    }

    /**
     * Emptying an answer removes the row.
     *
     * Leaving an all-null row behind would rebuild exactly the scaffolding that
     * made the legacy table impossible to count.
     */
    public function test_clearing_an_answer_leaves_no_row_behind(): void
    {
        $token = $this->staffToken();
        $id = $this->inTenant(fn () => $this->makeMember()->id);

        $this->withHeaders($this->headers($token))
            ->putJson("/api/v1/staff/members/{$id}/preferences/near_dhaka", ['flat_size_sft' => 1200])
            ->assertStatus(200)
            ->assertJsonPath('data.answered', true);

        $this->withHeaders($this->headers($token))
            ->putJson("/api/v1/staff/members/{$id}/preferences/near_dhaka", [
                'areas' => [],
                'flat_size_sft' => null,
                'budget' => null,
                'loan_percentage' => null,
                'flats_wanted' => null,
                'introduced_by_member_id' => null,
                'introduced_by_name' => null,
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.answered', false);

        $this->inTenant(function () use ($id) {
            self::assertSame(
                0,
                MemberPreference::where('member_id', $id)->where('project', 'near_dhaka')->count(),
                'An emptied answer must not leave a row behind.',
            );
        });
    }

    // -------------------------------------------------------- districts, typed

    /**
     * A DISTRICT IS CHOSEN, AN AREA IS TYPED - the sweep's own request.
     *
     * Free text is right for an address line and wrong for a field meant to be
     * grouped by. For `other_district` the areas ARE districts, of which there
     * are 64 that do not change.
     */
    public function test_a_district_outside_the_list_is_refused(): void
    {
        $token = $this->staffToken();
        $id = $this->inTenant(fn () => $this->makeMember()->id);

        $this->withHeaders($this->headers($token))
            ->putJson("/api/v1/staff/members/{$id}/preferences/other_district", [
                'areas' => ['Nowhereabad'],
            ])
            ->assertStatus(422);
    }

    /** ...while an area of Dhaka is whatever the member says. */
    public function test_an_area_of_dhaka_is_accepted_as_typed(): void
    {
        $token = $this->staffToken();
        $id = $this->inTenant(fn () => $this->makeMember()->id);

        $this->withHeaders($this->headers($token))
            ->putJson("/api/v1/staff/members/{$id}/preferences/dhaka_city", [
                'areas' => ['A site nobody has typed before'],
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.answered', true);
    }

    /**
     * The list itself: 64 districts, Rangpur among them.
     *
     * The legacy `disticts` table holds 64 rows and 63 distinct names -
     * **Panchagarh twice and no Rangpur at all**. Rangpur is a divisional
     * capital of some three million people, so for the life of that system a
     * member from Rangpur could not choose their own district. That is the
     * argument for a list in code that a test can count.
     */
    public function test_the_district_list_is_complete(): void
    {
        self::assertCount(64, Districts::all());
        self::assertSame(64, count(array_unique(Districts::all())));
        self::assertTrue(Districts::exists('Rangpur'));
        self::assertTrue(Districts::exists('Panchagarh'));
    }

    /** The one legacy spelling the production data actually contains. */
    public function test_a_legacy_district_spelling_maps_across(): void
    {
        self::assertSame("Cox's Bazar", Districts::fromLegacy('Coxsbazar'));
        self::assertSame('Dhaka', Districts::fromLegacy('Dhaka'));
        self::assertNull(Districts::fromLegacy('Nowhereabad'));
    }

    public function test_the_options_endpoint_offers_the_districts_by_division(): void
    {
        $token = $this->staffToken();

        $this->withHeaders($this->headers($token))
            ->getJson('/api/v1/staff/member-preferences/options')
            ->assertStatus(200)
            ->assertJsonPath('data.projects.dhaka_city', 'Inside Dhaka city')
            ->assertJsonPath('data.budgets.35+', 'Above Tk. 35 lakh')
            ->assertJsonPath('data.loan_percentages', [0, 25, 50, 75])
            ->assertJsonPath('data.districts.Rangpur.6', 'Rangpur');
    }

    // ------------------------------------------------------------ the introducer

    /**
     * The introducer is a LINK, resolved from that member's own record.
     *
     * The same problem as the member's referee, in the same data, and it gets
     * the same answer - which is what stops "Md. Riaz uddin" and
     * "Md. Riaz Uddin" being two people.
     */
    public function test_the_introducer_is_named_from_their_own_record(): void
    {
        $token = $this->staffToken();

        [$member, $introducer] = $this->inTenant(fn () => [
            $this->makeMember()->id,
            $this->makeMember(['name' => 'Md. Riaz Uddin'])->id,
        ]);

        $this->withHeaders($this->headers($token))
            ->putJson("/api/v1/staff/members/{$member}/preferences/dhaka_city", [
                'introduced_by_member_id' => $introducer,
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.introduced_by_name', 'Md. Riaz Uddin');
    }

    /** And a name alone for the one who never joined. */
    public function test_an_introducer_who_is_not_a_member_is_kept_as_a_name(): void
    {
        $token = $this->staffToken();
        $id = $this->inTenant(fn () => $this->makeMember()->id);

        $this->withHeaders($this->headers($token))
            ->putJson("/api/v1/staff/members/{$id}/preferences/dhaka_city", [
                'introduced_by_name' => 'A. Non-Member',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.introduced_by_name', 'A. Non-Member');
    }

    // ------------------------------------------------------------------ refusals

    public function test_an_unknown_project_is_a_404(): void
    {
        $token = $this->staffToken();
        $id = $this->inTenant(fn () => $this->makeMember()->id);

        // The legacy spelling, which is exactly the mistake worth refusing.
        $this->withHeaders($this->headers($token))
            ->putJson("/api/v1/staff/members/{$id}/preferences/other_distict", ['flat_size_sft' => 1200])
            ->assertStatus(404);
    }

    public function test_a_budget_outside_the_offered_ranges_is_refused(): void
    {
        $token = $this->staffToken();
        $id = $this->inTenant(fn () => $this->makeMember()->id);

        $this->withHeaders($this->headers($token))
            ->putJson("/api/v1/staff/members/{$id}/preferences/dhaka_city", ['budget' => 'Tk. 15-25 Lac'])
            ->assertStatus(422);
    }

    /** A loan is a percentage, and `No` is 0 - never an amount. */
    public function test_a_loan_that_is_an_amount_rather_than_a_percentage_is_refused(): void
    {
        $token = $this->staffToken();
        $id = $this->inTenant(fn () => $this->makeMember()->id);

        // The one row in the production data that holds `5000000`.
        $this->withHeaders($this->headers($token))
            ->putJson("/api/v1/staff/members/{$id}/preferences/dhaka_city", ['loan_percentage' => 5000000])
            ->assertStatus(422);

        $this->withHeaders($this->headers($token))
            ->putJson("/api/v1/staff/members/{$id}/preferences/dhaka_city", ['loan_percentage' => 0])
            ->assertStatus(200)
            ->assertJsonPath('data.answered', true);
    }

    public function test_reading_is_not_enough_to_write(): void
    {
        $this->getJson(
            "/api/v1/staff/members/1/preferences",
            $this->headers(),
        )->assertStatus(401);
    }
}
