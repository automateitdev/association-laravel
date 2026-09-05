<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Tenant\Member;
use App\Models\Tenant\MemberProfileUpdate;
use App\Models\Tenant\MemberShareBalance;
use App\Models\User;
use App\Services\ShareService;
use App\Services\TenantSeedService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\TenantFixtures;
use Tests\TenantTestCase;

/**
 * Share transfers (FR-SHR-3) and the profile-update queue (FR-MEM-8).
 *
 * Both features move something a member is entitled to rely on - shares, or the
 * details the office identifies them by - so the tests here are mostly about
 * what must be refused.
 */
class SharesAndProfileUpdatesTest extends TenantTestCase
{
    use TenantFixtures;

    private int $sequence = 0;

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
        $n = ++$this->sequence;

        return $this->inTenant(function () use ($n) {
            app(TenantSeedService::class)->seedAll();

            $user = User::create([
                'name' => "Staff {$n}",
                'email' => "shares{$n}@assoc.test",
                'password' => 'secret-password',
            ]);
            $user->assignRole('superadmin');

            return $user->createToken('test', $user->getAllPermissions()->pluck('name')->all())
                ->plainTextToken;
        });
    }

    /** @param  list<string>  $permissions */
    private function tokenWithPermissions(array $permissions): string
    {
        $n = ++$this->sequence;

        return $this->inTenant(function () use ($permissions, $n) {
            app(TenantSeedService::class)->seedAll();

            $role = Role::findOrCreate("shares-narrow-{$n}", 'web');
            $role->syncPermissions(
                array_map(fn (string $p) => Permission::findByName($p, 'web'), $permissions)
            );

            $user = User::create([
                'name' => "Narrow {$n}",
                'email' => "sharesnarrow{$n}@assoc.test",
                'password' => 'secret-password',
            ]);
            $user->assignRole($role);

            return $user->createToken('test', $user->getAllPermissions()->pluck('name')->all())
                ->plainTextToken;
        });
    }

    private function memberToken(Member $member): string
    {
        return $this->inTenant(
            fn () => $member->createToken('member', ['member.dues.view', 'member.payments.view'])->plainTextToken
        );
    }

    /** A seller holding `$shares` of one share-bearing head, and a buyer. */
    private function twoMembersWithShares(int $shares = 10): array
    {
        return $this->inTenant(function () use ($shares) {
            $this->seedSettings();
            $setup = $this->makeFeeSetup(['is_share' => true, 'amount' => '1000.00']);

            $seller = $this->makeMember();
            $seller->associatorInfo()->create(['membership_no' => '501', 'num_or_shares' => $shares]);

            $buyer = $this->makeMember();
            $buyer->associatorInfo()->create(['membership_no' => '502', 'num_or_shares' => 0]);

            MemberShareBalance::create([
                'member_id' => $seller->id,
                'fee_setup_id' => $setup->id,
                'shares' => $shares,
            ]);

            return [$seller, $buyer, $setup];
        });
    }

    // ------------------------------------------------------ share transfers

    public function test_shares_move_between_two_members(): void
    {
        $token = $this->staffToken();
        [$seller, $buyer, $setup] = $this->twoMembersWithShares(10);

        $this->postJson('/api/v1/staff/shares/transfers', [
            'seller_id' => $seller->id,
            'buyer_id' => $buyer->id,
            'fee_setup_id' => $setup->id,
            'shares' => 4,
            'amount' => 4000,
        ], $this->headers($token))
            ->assertCreated()
            ->assertJsonPath('data.seller_balance', 6)
            ->assertJsonPath('data.buyer_balance', 4);

        // Both stores in step: the per-head balance and the running total staff
        // actually read.
        $this->inTenant(function () use ($seller, $buyer) {
            $this->assertSame(6, (int) $seller->associatorInfo()->value('num_or_shares'));
            $this->assertSame(4, (int) $buyer->associatorInfo()->value('num_or_shares'));
        });
    }

    public function test_it_refuses_to_transfer_more_shares_than_are_held(): void
    {
        $token = $this->staffToken();
        [$seller, $buyer, $setup] = $this->twoMembersWithShares(3);

        $this->postJson('/api/v1/staff/shares/transfers', [
            'seller_id' => $seller->id,
            'buyer_id' => $buyer->id,
            'fee_setup_id' => $setup->id,
            'shares' => 5,
        ], $this->headers($token))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'SHARE_TRANSFER_REFUSED');

        // And nothing moved, rather than moving what it could.
        $this->inTenant(fn () => $this->assertSame(
            3,
            (int) MemberShareBalance::where('member_id', $seller->id)->value('shares')
        ));
    }

    /** It nets to nothing while leaving a record implying something happened. */
    public function test_a_member_cannot_transfer_shares_to_themselves(): void
    {
        $token = $this->staffToken();
        [$seller, , $setup] = $this->twoMembersWithShares();

        $this->postJson('/api/v1/staff/shares/transfers', [
            'seller_id' => $seller->id,
            'buyer_id' => $seller->id,
            'fee_setup_id' => $setup->id,
            'shares' => 1,
        ], $this->headers($token))
            ->assertStatus(422);
    }

    public function test_a_transfer_of_nothing_is_refused(): void
    {
        $token = $this->staffToken();
        [$seller, $buyer, $setup] = $this->twoMembersWithShares();

        foreach ([0, -3] as $shares) {
            $this->postJson('/api/v1/staff/shares/transfers', [
                'seller_id' => $seller->id,
                'buyer_id' => $buyer->id,
                'fee_setup_id' => $setup->id,
                'shares' => $shares,
            ], $this->headers($token))->assertStatus(422);
        }
    }

    /**
     * The history balances do not keep. Without it, the share audit cannot tell
     * a legitimate transfer from the drift D-19 caused.
     */
    public function test_a_transfer_is_recorded_and_listed(): void
    {
        $token = $this->staffToken();
        [$seller, $buyer, $setup] = $this->twoMembersWithShares();

        $this->postJson('/api/v1/staff/shares/transfers', [
            'seller_id' => $seller->id,
            'buyer_id' => $buyer->id,
            'fee_setup_id' => $setup->id,
            'shares' => 2,
            'amount' => 2500,
        ], $this->headers($token))->assertCreated();

        $this->getJson('/api/v1/staff/shares/transfers', $this->headers($token))
            ->assertOk()
            ->assertJsonPath('data.0.shares', 2)
            ->assertJsonPath('data.0.amount', '2500.00')
            ->assertJsonPath('data.0.seller_name', $seller->name);

        $this->inTenant(fn () => $this->assertDatabaseHas('audit_logs', ['action' => 'shares.transferred']));
    }

    public function test_a_members_holdings_are_reported_by_head(): void
    {
        $token = $this->staffToken();
        [$seller, , $setup] = $this->twoMembersWithShares(7);

        $this->getJson("/api/v1/staff/shares/members/{$seller->id}", $this->headers($token))
            ->assertOk()
            ->assertJsonPath('data.total', 7)
            ->assertJsonPath('data.by_head.0.shares', 7)
            ->assertJsonPath('data.by_head.0.fee_setup_id', $setup->id);
    }

    public function test_transferring_needs_more_than_viewing(): void
    {
        [$seller, $buyer, $setup] = $this->twoMembersWithShares();
        $token = $this->tokenWithPermissions(['shares.view']);

        $this->getJson("/api/v1/staff/shares/members/{$seller->id}", $this->headers($token))
            ->assertOk();

        $this->postJson('/api/v1/staff/shares/transfers', [
            'seller_id' => $seller->id,
            'buyer_id' => $buyer->id,
            'fee_setup_id' => $setup->id,
            'shares' => 1,
        ], $this->headers($token))->assertForbidden();
    }

    // -------------------------------------------------- profile update queue

    private function member(): Member
    {
        return $this->inTenant(function () {
            $member = $this->makeMember(['mobile' => '01711000001']);
            $member->associatorInfo()->create(['membership_no' => '601', 'num_or_shares' => 0]);

            return $member;
        });
    }

    public function test_a_member_can_ask_for_a_change_and_nothing_changes_yet(): void
    {
        $member = $this->member();

        $this->postJson('/api/v1/me/profile-updates', [
            'present_address' => 'New address, Dhaka',
        ], $this->headers($this->memberToken($member)))
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending');

        // The whole point: a pending request has no effect on the record.
        $this->inTenant(fn () => $this->assertNotSame(
            'New address, Dhaka',
            Member::find($member->id)->present_address
        ));
    }

    /** Two open requests can be approved in either order, giving different results. */
    public function test_only_one_request_can_be_open_at_a_time(): void
    {
        $member = $this->member();
        $headers = $this->headers($this->memberToken($member));

        $this->postJson('/api/v1/me/profile-updates', ['nid' => '1234567890'], $headers)
            ->assertCreated();

        $this->postJson('/api/v1/me/profile-updates', ['nid' => '9999999999'], $headers)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'PROFILE_UPDATE_PENDING');
    }

    /** A form posting every field would otherwise file a request that changes nothing. */
    public function test_asking_for_what_is_already_on_file_is_refused(): void
    {
        $member = $this->member();

        $this->postJson('/api/v1/me/profile-updates', [
            'mobile' => $member->mobile,
        ], $this->headers($this->memberToken($member)))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'NOTHING_TO_CHANGE');
    }

    public function test_approving_applies_the_change(): void
    {
        $member = $this->member();
        $staff = $this->staffToken();

        $id = $this->postJson('/api/v1/me/profile-updates', [
            'present_address' => 'Applied address',
        ], $this->headers($this->memberToken($member)))->json('data.id');

        $this->postJson("/api/v1/staff/profile-updates/{$id}/decide", [
            'decision' => 'approve',
        ], $this->headers($staff))
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->inTenant(fn () => $this->assertSame(
            'Applied address',
            Member::find($member->id)->present_address
        ));
    }

    public function test_rejecting_applies_nothing_and_needs_a_reason(): void
    {
        $member = $this->member();
        $staff = $this->staffToken();

        $id = $this->postJson('/api/v1/me/profile-updates', [
            'present_address' => 'Refused address',
        ], $this->headers($this->memberToken($member)))->json('data.id');

        // Refusing without saying why leaves the member to guess and ask again.
        $this->postJson("/api/v1/staff/profile-updates/{$id}/decide", [
            'decision' => 'reject',
        ], $this->headers($staff))->assertStatus(422);

        $this->postJson("/api/v1/staff/profile-updates/{$id}/decide", [
            'decision' => 'reject',
            'reason' => 'The address does not match the document provided.',
        ], $this->headers($staff))
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $this->inTenant(fn () => $this->assertNotSame(
            'Refused address',
            Member::find($member->id)->present_address
        ));
    }

    public function test_a_decided_request_cannot_be_decided_again(): void
    {
        $member = $this->member();
        $staff = $this->staffToken();

        $id = $this->postJson('/api/v1/me/profile-updates', [
            'nid' => '5555555555',
        ], $this->headers($this->memberToken($member)))->json('data.id');

        $this->postJson("/api/v1/staff/profile-updates/{$id}/decide", ['decision' => 'approve'], $this->headers($staff))
            ->assertOk();

        $this->postJson("/api/v1/staff/profile-updates/{$id}/decide", ['decision' => 'approve'], $this->headers($staff))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'ALREADY_DECIDED');
    }

    /**
     * An officer approving "mobile: 017..." cannot judge it without seeing what
     * the number is now.
     */
    public function test_the_queue_shows_the_current_value_beside_the_proposed_one(): void
    {
        $member = $this->member();
        $staff = $this->staffToken();

        $this->postJson('/api/v1/me/profile-updates', [
            'mobile' => '01799887766',
        ], $this->headers($this->memberToken($member)))->assertCreated();

        $this->getJson('/api/v1/staff/profile-updates', $this->headers($staff))
            ->assertOk()
            ->assertJsonPath('data.0.fields.0.field', 'mobile')
            ->assertJsonPath('data.0.fields.0.current', '01711000001')
            ->assertJsonPath('data.0.fields.0.proposed', '01799887766')
            ->assertJsonPath('meta.pending', 1);
    }

    public function test_deciding_needs_more_than_viewing(): void
    {
        $member = $this->member();

        $id = $this->postJson('/api/v1/me/profile-updates', [
            'nid' => '7777777777',
        ], $this->headers($this->memberToken($member)))->json('data.id');

        $token = $this->tokenWithPermissions(['profile-updates.view']);

        $this->getJson('/api/v1/staff/profile-updates', $this->headers($token))->assertOk();

        $this->postJson("/api/v1/staff/profile-updates/{$id}/decide", [
            'decision' => 'approve',
        ], $this->headers($token))->assertForbidden();
    }

    /**
     * Status, membership number and money are the association's record of a
     * member, not the member's description of themselves.
     */
    public function test_a_member_cannot_ask_to_change_their_own_status(): void
    {
        $member = $this->member();

        $this->postJson('/api/v1/me/profile-updates', [
            'status' => 'active',
            'present_address' => 'A real change',
        ], $this->headers($this->memberToken($member)))->assertCreated();

        $this->inTenant(function () use ($member) {
            $update = MemberProfileUpdate::where('member_id', $member->id)->firstOrFail();

            $this->assertArrayNotHasKey('status', $update->changes);
            $this->assertArrayHasKey('present_address', $update->changes);
        });
    }
}
