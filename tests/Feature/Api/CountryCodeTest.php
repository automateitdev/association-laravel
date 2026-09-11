<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Tenant\Member;
use App\Models\Tenant\Nominee;
use App\Models\User;
use App\Services\TenantSeedService;
use Tests\Support\TenantFixtures;
use Tests\TenantTestCase;

/**
 * Which country a mobile number belongs to (legacy `country_code`).
 *
 * THE SWEEP HAD THIS WRONG, and that is why it is here at all.
 * `12-legacy-sweep.md` §2B listed it as "one distinct value in production
 * today, so it costs nothing now and is a schema change later". True of
 * members - 313 `BD`, 2 null - and false of nominees: **8 of them are `US`**.
 * A nominee living abroad is an ordinary case in a cooperative of civil
 * servants, and dropping the column on import would have quietly turned eight
 * foreign numbers into Bangladeshi ones.
 *
 * So these tests are about the two things that would lose that again: a
 * non-default value must survive a write, and the normalisation must happen in
 * the model rather than at one entrance, because the import does not use the
 * API.
 */
class CountryCodeTest extends TenantTestCase
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
                'email' => "country{$sequence}@assoc.test",
                'password' => 'secret-password',
            ]);
            $user->assignRole('superadmin');

            return $user->createToken('test', $user->getAllPermissions()->pluck('name')->all())
                ->plainTextToken;
        });
    }

    // -------------------------------------------------------------- the column

    /** Bangladesh unless somebody says otherwise, so no reader has to decide. */
    public function test_a_member_defaults_to_bangladesh(): void
    {
        $this->inTenant(function () {
            self::assertSame('BD', $this->makeMember()->fresh()->country_code);
        });
    }

    public function test_a_nominee_keeps_a_foreign_country(): void
    {
        $this->inTenant(function () {
            $nominee = Nominee::create([
                'member_id' => $this->makeMember()->id,
                'name' => 'Abroad Nominee',
                'mobile' => '12025550123',
                'country_code' => 'US',
            ]);

            self::assertSame('US', $nominee->fresh()->country_code);
        });
    }

    /**
     * Normalised in the MODEL, which is the point of putting it there.
     *
     * The API is not the only writer - an import reads the legacy column
     * straight into the model - and `bd` alongside `BD` in a column with two
     * values in it is the kind of thing nobody notices until a query returns
     * half an answer.
     */
    public function test_a_lower_case_code_is_stored_upper_case(): void
    {
        $this->inTenant(function () {
            $member = $this->makeMember(['country_code' => 'us']);

            self::assertSame('US', $member->fresh()->country_code);
        });
    }

    public function test_an_empty_code_falls_back_to_bangladesh(): void
    {
        $this->inTenant(function () {
            self::assertSame('BD', $this->makeMember(['country_code' => ''])->fresh()->country_code);
        });
    }

    // ----------------------------------------------------------------- the API

    public function test_it_is_returned_with_the_member(): void
    {
        $token = $this->staffToken();
        $id = $this->inTenant(fn () => $this->makeMember(['country_code' => 'US'])->id);

        $this->withHeaders($this->headers($token))
            ->getJson("/api/v1/staff/members/{$id}")
            ->assertStatus(200)
            ->assertJsonPath('data.country_code', 'US');
    }

    public function test_staff_can_set_it_on_a_member(): void
    {
        $token = $this->staffToken();
        $id = $this->inTenant(fn () => $this->makeMember()->id);

        $this->withHeaders($this->headers($token))
            ->putJson("/api/v1/staff/members/{$id}", ['country_code' => 'gb'])
            ->assertStatus(200)
            ->assertJsonPath('data.country_code', 'GB');
    }

    public function test_staff_can_set_it_on_a_nominee(): void
    {
        $token = $this->staffToken();
        $member = $this->inTenant(fn () => $this->makeMember()->id);

        $this->withHeaders($this->headers($token))
            ->postJson("/api/v1/staff/members/{$member}/nominees", [
                'name' => 'Abroad Nominee',
                'mobile' => '12025550123',
                'country_code' => 'US',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.country_code', 'US');
    }

    /**
     * Shape, not a gazetteer.
     *
     * A whitelist of every country would be a second thing to keep current for
     * the sake of rejecting `XX`. Two letters is what stops `bangladesh` and
     * `+88` landing in a column that means neither.
     */
    public function test_something_that_is_not_a_two_letter_code_is_refused(): void
    {
        $token = $this->staffToken();
        $id = $this->inTenant(fn () => $this->makeMember()->id);

        foreach (['bangladesh', '+88', '8', 'B1'] as $bad) {
            $this->withHeaders($this->headers($token))
                ->putJson("/api/v1/staff/members/{$id}", ['country_code' => $bad])
                ->assertStatus(422);
        }
    }

    /**
     * A member who moves abroad can ask for both at once.
     *
     * The number and its country change together, and a queue that accepts one
     * without the other files a request that makes the record worse than it was.
     */
    public function test_a_member_can_request_it_with_their_new_number(): void
    {
        $member = $this->inTenant(function () {
            $m = $this->makeMember(['mobile' => '01710000055']);
            $m->forceFill(['password' => 'secret-password', 'status' => Member::STATUS_ACTIVE])->save();

            return $m;
        });

        $token = $this->inTenant(fn () => $member->createToken('app', ['*'])->plainTextToken);

        $this->withHeaders($this->headers($token))
            ->postJson('/api/v1/me/profile-updates', [
                'mobile' => '12025550123',
                'country_code' => 'US',
            ])
            ->assertStatus(201);

        // Proposed, NOT applied: the office decides. The member's record still
        // says what it said.
        $this->inTenant(function () use ($member) {
            self::assertSame('BD', $member->fresh()->country_code);
        });
    }
}
