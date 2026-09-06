<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Tenant\Member;
use App\Models\User;
use App\Services\TenantSeedService;
use Illuminate\Support\Facades\Hash;
use Tests\Support\TenantFixtures;
use Tests\TenantTestCase;

/**
 * One person, two accounts (SRS OD-4).
 *
 * A treasurer is usually also a member. Staff are found by email; members by
 * email or mobile - so a shared EMAIL matches two rows, and the login endpoint
 * has to decide what that means.
 *
 * WHAT IT USED TO MEAN was `findStaff() ?? findMember()`: the member row was
 * never consulted once a staff row existed. Nothing covered this, and it failed
 * in two ways nobody would have reported as the same bug.
 *
 *   - One password: signed in as staff, always, with no way to reach their own
 *     dues. No error - just the wrong half of the app, forever.
 *   - Two passwords: the member password checked against the STAFF hash,
 *     failed, and returned "invalid credentials". A permanent lockout of a real
 *     account, reported to its owner as a typo.
 *
 * Neither was a security hole - no token ever crossed accounts - which is
 * exactly why it could sit there unnoticed.
 */
class DualAccountLoginTest extends TenantTestCase
{
    use TenantFixtures;

    private const SHARED = 'treasurer@association.test';

    protected function setUp(): void
    {
        parent::setUp();

        $this->inTenant(fn () => app(TenantSeedService::class)->seedAll());
    }

    private function staff(string $password): User
    {
        return $this->inTenant(function () use ($password) {
            $user = User::create([
                'name' => 'Rokeya Begum',
                'email' => self::SHARED,
                'password' => Hash::make($password),
            ]);

            $user->assignRole('admin');

            return $user;
        });
    }

    private function member(string $password, string $status = 'active'): Member
    {
        return $this->inTenant(fn () => Member::create([
            'name' => 'Rokeya Begum',
            'email' => self::SHARED,
            'mobile' => '01700000009',
            'status' => $status,
            'password' => Hash::make($password),
        ]));
    }

    /** Login resolves no tenant from a token, so the header carries it. */
    private function headers(): array
    {
        return ['X-Tenant' => $this->slug(), 'Accept' => 'application/json'];
    }

    private function attempt(string $password, ?string $as = null)
    {
        return $this->postJson('/api/v1/auth/login', array_filter([
            'login' => self::SHARED,
            'password' => $password,
            'as' => $as,
        ]), $this->headers());
    }

    // ------------------------------------------------- different passwords

    /**
     * THE PASSWORD SETTLES IT, and nobody is asked anything.
     *
     * This is the common case and the one that used to be a lockout: two
     * accounts, two passwords, each opening exactly one of them.
     */
    public function test_different_passwords_each_reach_their_own_account(): void
    {
        $this->staff('staff-password-here');
        $this->member('member-password-here');

        $this->attempt('staff-password-here')
            ->assertOk()
            ->assertJsonPath('data.role', 'admin');

        $this->attempt('member-password-here')
            ->assertOk()
            ->assertJsonPath('data.role', 'member');
    }

    /** The member account is reachable at all, which it previously was not. */
    public function test_the_member_password_is_no_longer_reported_as_invalid(): void
    {
        $this->staff('staff-password-here');
        $this->member('member-password-here');

        $this->attempt('member-password-here')
            ->assertOk()
            ->assertJsonPath('data.role', 'member');
    }

    // ------------------------------------------------------ one password

    /**
     * One password opening both is the only real question, so it is asked.
     *
     * Signing them in as staff - the old behaviour - is a guess, and it is
     * wrong half the time for somebody who came to check their own dues.
     */
    public function test_one_password_for_both_asks_which(): void
    {
        $this->staff('the-same-password');
        $this->member('the-same-password');

        $this->attempt('the-same-password')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ACCOUNT_AMBIGUOUS')
            ->assertJsonPath('error.details.roles', ['staff', 'member']);
    }

    public function test_answering_which_signs_them_in_as_that_one(): void
    {
        $this->staff('the-same-password');
        $this->member('the-same-password');

        $this->attempt('the-same-password', 'staff')
            ->assertOk()
            ->assertJsonPath('data.role', 'admin');

        $this->attempt('the-same-password', 'member')
            ->assertOk()
            ->assertJsonPath('data.role', 'member');
    }

    /**
     * The answer does not become a way past the password.
     *
     * `as=staff` with the member's password must not sign anybody in - it names
     * which account to try, it does not vouch for anything.
     */
    public function test_naming_an_account_still_requires_its_own_password(): void
    {
        $this->staff('staff-password-here');
        $this->member('member-password-here');

        $this->attempt('member-password-here', 'staff')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');

        $this->attempt('staff-password-here', 'member')
            ->assertStatus(401);
    }

    // --------------------------------------------------------- disclosure

    /**
     * THE ORDERING IS THE SECURITY OF IT.
     *
     * Answering "this address has a staff account" to anyone who types an email
     * would make the login form a directory of who works for the association.
     * A wrong password gets the same refusal it always did, and learns nothing.
     */
    public function test_a_wrong_password_is_never_told_that_two_accounts_exist(): void
    {
        $this->staff('the-same-password');
        $this->member('the-same-password');

        $response = $this->attempt('not-the-password')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');

        $this->assertStringNotContainsString('staff', strtolower((string) $response->json('error.message')));
    }

    // ----------------------------------------------------- member status

    /**
     * A suspended member still gets the choice, and still gets the refusal.
     *
     * Quietly signing them in as staff instead would hide the thing they most
     * need to know: that their membership is suspended.
     */
    public function test_a_suspended_member_side_still_reports_its_own_status(): void
    {
        $this->staff('the-same-password');
        $this->member('the-same-password', 'suspended');

        $this->attempt('the-same-password')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ACCOUNT_AMBIGUOUS');

        $this->attempt('the-same-password', 'member')
            ->assertJsonPath('error.code', 'MEMBER_SUSPENDED');

        $this->attempt('the-same-password', 'staff')
            ->assertOk()
            ->assertJsonPath('data.role', 'admin');
    }

    // ------------------------------------------------------ the ordinary case

    /** One account only: nothing about this changed. */
    public function test_a_person_with_one_account_is_never_asked(): void
    {
        $this->member('member-password-here');

        $this->attempt('member-password-here')
            ->assertOk()
            ->assertJsonPath('data.role', 'member');
    }

    /**
     * A shared MOBILE is not a collision: staff sign in by email only.
     */
    public function test_a_mobile_number_only_ever_finds_the_member(): void
    {
        $this->staff('staff-password-here');
        $this->member('member-password-here');

        $this->postJson('/api/v1/auth/login', [
            'login' => '01700000009',
            'password' => 'member-password-here',
        ], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.role', 'member');
    }
}
