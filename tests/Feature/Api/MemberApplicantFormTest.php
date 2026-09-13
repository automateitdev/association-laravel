<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Tenant\Member;
use App\Models\Tenant\MemberProfileUpdate;
use App\Models\User;
use App\Services\TenantSeedService;
use Tests\Support\TenantFixtures;
use Tests\TenantTestCase;

/**
 * The applicant section of the legacy form, reachable by the member.
 *
 * WHAT THE LEGACY FORM ASKS FOR. `MemberInfos.vue` is the screen a member
 * reaches from their own dashboard, and its first tab collects the cadre
 * service record - BCS batch, cadre ID, joining date - and the reference who
 * vouched for them. None of those could be corrected through this API: a
 * member whose cadre ID was typed wrong at registration had to ring the office
 * and hope.
 *
 * AND IT IS STILL A REQUEST, WHICH IS ALSO PARITY. The legacy member form does
 * not write to `members` either - it writes `MemberProfileUpdate` and
 * `MemberChoiceUpdate`, pending tables the office decides on. The approval
 * queue here is the same shape, not a departure from it.
 */
class MemberApplicantFormTest extends TenantTestCase
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
                'email' => "applicant-form{$n}@assoc.test",
                'password' => 'secret-password',
            ]);
            $user->assignRole('superadmin');

            return $user->createToken('test', $user->getAllPermissions()->pluck('name')->all())
                ->plainTextToken;
        });
    }

    // ---- the fields the legacy form has and this API did not ---------------

    public function test_a_member_can_ask_to_correct_their_cadre_service_record(): void
    {
        $member = $this->member(['bcs_batch' => '34th', 'cadre_id' => 1001]);

        $this->postJson('/api/v1/me/profile-updates', [
            'bcs_batch' => '35th',
            'cadre_id' => 2002,
            'joining_date' => '2014-03-01',
        ], $this->headers($this->memberToken($member)))
            ->assertStatus(201)
            ->assertJsonPath('data.changes.bcs_batch', '35th')
            ->assertJsonPath('data.changes.cadre_id', 2002);

        // Proposed, not applied. The record is untouched until somebody decides.
        $this->inTenant(function () use ($member) {
            self::assertSame('34th', $member->fresh()->bcs_batch);
        });
    }

    public function test_a_member_can_name_the_reference_who_vouched_for_them(): void
    {
        $member = $this->member();
        $introducer = $this->inTenant(fn () => $this->makeMember(['name' => 'Karim Uddin']));

        $this->postJson('/api/v1/me/profile-updates', [
            'introduced_by_member_id' => $introducer->id,
            'introduced_by_name' => 'Karim Uddin',
            'introduced_by_mobile' => '01711000111',
        ], $this->headers($this->memberToken($member)))
            ->assertStatus(201)
            ->assertJsonPath('data.changes.introduced_by_name', 'Karim Uddin')
            ->assertJsonPath('data.changes.introduced_by_mobile', '01711000111');
    }

    /**
     * THE INTRODUCER'S NUMBER HAS A COLUMN NOW.
     *
     * The legacy form collects `ref_name`, `ref_mobile` and
     * `ref_memeber_id_no`, and the rewrite had kept only the first and third.
     * In the production data all three are filled on the same 63 of 315
     * members - nobody recorded a name without the number, which is what you
     * would expect of a field whose purpose is to let the office RING them.
     */
    public function test_the_introducers_mobile_survives_approval(): void
    {
        $member = $this->member();

        $this->postJson('/api/v1/me/profile-updates', [
            'introduced_by_name' => 'Someone Not A Member',
            'introduced_by_mobile' => '01822000222',
        ], $this->headers($this->memberToken($member)))->assertStatus(201);

        $id = $this->inTenant(fn () => MemberProfileUpdate::query()->latest('id')->value('id'));

        $this->postJson("/api/v1/staff/profile-updates/{$id}/decide", [
            'decision' => 'approve',
        ], $this->headers($this->staffToken()))->assertOk();

        $this->inTenant(function () use ($member) {
            self::assertSame('01822000222', $member->fresh()->introduced_by_mobile);
        });
    }

    /** A member number that is not there is refused now, not at approval. */
    public function test_an_unknown_introducer_member_is_refused(): void
    {
        $member = $this->member();

        $this->postJson('/api/v1/me/profile-updates', [
            'introduced_by_member_id' => 999_999,
        ], $this->headers($this->memberToken($member)))
            ->assertStatus(422);
    }

    /**
     * Not a hypothetical: the field is a member number typed by hand, and
     * one's own is the number most readily to hand.
     */
    public function test_a_member_cannot_introduce_themselves(): void
    {
        $member = $this->member();

        $this->postJson('/api/v1/me/profile-updates', [
            'introduced_by_member_id' => $member->id,
        ], $this->headers($this->memberToken($member)))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'SELF_INTRODUCTION');
    }

    // ---- the silent drop --------------------------------------------------

    /**
     * `country_code` USED TO BE ACCEPTED AND THEN THROWN AWAY.
     *
     * ProfileController validated it, the request carried it, an officer saw
     * it on screen and approved - and approval filters `changes` against
     * ALLOWED, which did not list it. So a member who moved abroad had their
     * new number applied against the old country, with every screen in the
     * chain reporting success.
     *
     * The two travel together by design - the controller says so in as many
     * words - so they have to be PERMITTED together, not merely validated
     * together.
     */
    public function test_a_country_code_change_is_actually_applied(): void
    {
        $member = $this->member(['mobile' => '01711000001', 'country_code' => 'BD']);

        $this->postJson('/api/v1/me/profile-updates', [
            'mobile' => '+447700900001',
            'country_code' => 'GB',
        ], $this->headers($this->memberToken($member)))->assertStatus(201);

        $id = $this->inTenant(fn () => MemberProfileUpdate::query()->latest('id')->value('id'));

        $this->postJson("/api/v1/staff/profile-updates/{$id}/decide", [
            'decision' => 'approve',
        ], $this->headers($this->staffToken()))->assertOk();

        $this->inTenant(function () use ($member) {
            $fresh = $member->fresh();

            self::assertSame('+447700900001', $fresh->mobile);
            self::assertSame('GB', $fresh->country_code, 'The country code was dropped at approval.');
        });
    }

    // ---- what the app renders the form from -------------------------------

    /**
     * Every permitted field comes back on /me, so the form opens filled in.
     *
     * Driven by ALLOWED at both ends, which is what stops the form and the
     * endpoint drifting apart - a field added to one appears in the other
     * without anybody remembering to.
     */
    public function test_the_new_fields_are_on_the_member_profile(): void
    {
        $member = $this->member([
            'bcs_batch' => '30th',
            'cadre_id' => 3003,
            'introduced_by_name' => 'A Referee',
        ]);

        $response = $this->getJson('/api/v1/me', $this->headers($this->memberToken($member)))
            ->assertOk();

        foreach (MemberProfileUpdate::ALLOWED as $field) {
            self::assertArrayHasKey(
                $field,
                $response->json('data.profile.editable'),
                "[{$field}] is permitted but not shown, so the form cannot render it.",
            );
        }

        $response
            ->assertJsonPath('data.profile.editable.bcs_batch', '30th')
            ->assertJsonPath('data.profile.editable.cadre_id', 3003)
            ->assertJsonPath('data.profile.editable.introduced_by_name', 'A Referee');
    }

    /** Money, status and membership number are still nobody's to propose. */
    public function test_the_association_own_record_is_still_out_of_reach(): void
    {
        foreach (['status', 'membership_no', 'num_or_shares', 'id'] as $field) {
            self::assertNotContains(
                $field,
                MemberProfileUpdate::ALLOWED,
                "[{$field}] is the association's record of a member, not the member's "
                    .'description of themselves.',
            );
        }
    }
}
