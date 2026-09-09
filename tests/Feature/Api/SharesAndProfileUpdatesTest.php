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

    /**
     * A member's own record carries the fields they may ask to change.
     *
     * Without these the change form starts blank, and a member cannot tell
     * whether the office already holds their father's name or simply never
     * asked - so they retype what is there and file a request that changes
     * nothing. Driven by the same ALLOWED list the request endpoint validates
     * against, so the two cannot drift into showing a field nobody may change.
     */
    public function test_the_member_profile_carries_the_editable_fields(): void
    {
        $member = $this->member();

        $response = $this->getJson('/api/v1/me', $this->headers($this->memberToken($member)))
            ->assertOk();

        foreach (\App\Models\Tenant\MemberProfileUpdate::ALLOWED as $field) {
            $response->assertJsonPath("data.profile.editable.{$field}", fn ($value) => true);
        }

        // Their own values, not somebody's defaults.
        $this->assertSame(
            $response->json('data.profile.name'),
            $response->json('data.profile.editable.name'),
        );
    }

    public function test_shares_move_between_two_members(): void
    {
        $token = $this->staffToken();
        [$seller, $buyer, $setup] = $this->twoMembersWithShares(10);

        $this->postJson('/api/v1/staff/shares/transfers', [
            'seller_id' => $seller->id,
            'transfers' => [[
                'buyer_id' => $buyer->id,
                'fee_setup_id' => $setup->id,
                'shares' => 4,
            ]],
        ], $this->headers($token))
            ->assertCreated()
            ->assertJsonPath('data.seller_balance', 6)
            ->assertJsonPath('data.transfers.0.buyer_balance', 4);

        // Both stores in step: the per-head balance and the running total staff
        // actually read.
        $this->inTenant(function () use ($seller, $buyer) {
            $this->assertSame(6, (int) $seller->associatorInfo()->value('num_or_shares'));
            $this->assertSame(4, (int) $buyer->associatorInfo()->value('num_or_shares'));
        });
    }

    /**
     * The note survives, because it is printed rather than filed.
     *
     * It appears in the Instalment Transfers Sent and Received tables on a
     * member's statement - the one line explaining why instalments they paid
     * for now belong to somebody else. The rewrite dropped the column and left
     * that question unanswerable.
     */
    public function test_a_transfer_keeps_the_note_it_was_given(): void
    {
        $token = $this->staffToken();
        [$seller, $buyer, $setup] = $this->twoMembersWithShares(10);

        $this->postJson('/api/v1/staff/shares/transfers', [
            'seller_id' => $seller->id,
            'transfers' => [[
                'buyer_id' => $buyer->id,
                'fee_setup_id' => $setup->id,
                'shares' => 2,
            ]],
            'note' => 'Inherited on the death of the holder.',
        ], $this->headers($token))
            ->assertCreated()
            ->assertJsonPath('data.note', 'Inherited on the death of the holder.');

        $this->withHeaders($this->headers($token))
            ->getJson('/api/v1/staff/shares/transfers')
            ->assertOk()
            ->assertJsonPath('data.0.note', 'Inherited on the death of the holder.');
    }

    /**
     * Sent and received are opposite readings of one row.
     *
     * A member's own history has to say which way each transfer went, and the
     * row cannot say it on its own - it depends entirely on whose page it is
     * being read on.
     */
    public function test_a_members_transfer_history_says_which_way_each_one_went(): void
    {
        $token = $this->staffToken();
        [$seller, $buyer, $setup] = $this->twoMembersWithShares(10);

        $this->postJson('/api/v1/staff/shares/transfers', [
            'seller_id' => $seller->id,
            'transfers' => [[
                'buyer_id' => $buyer->id,
                'fee_setup_id' => $setup->id,
                'shares' => 2,
            ]],
        ], $this->headers($token))->assertCreated();

        $this->withHeaders($this->headers($token))
            ->getJson("/api/v1/staff/shares/transfers?member_id={$seller->id}")
            ->assertOk()
            ->assertJsonPath('data.0.direction', 'sent');

        $this->withHeaders($this->headers($token))
            ->getJson("/api/v1/staff/shares/transfers?member_id={$buyer->id}")
            ->assertOk()
            ->assertJsonPath('data.0.direction', 'received');

        // Unfiltered, the question has no answer and the field says so rather
        // than picking a side.
        $this->withHeaders($this->headers($token))
            ->getJson('/api/v1/staff/shares/transfers')
            ->assertOk()
            ->assertJsonPath('data.0.direction', null);
    }

    /** A seller holding `$shares` of one head, and two people to split it between. */
    private function sellerAndTwoBuyers(int $shares = 10): array
    {
        return $this->inTenant(function () use ($shares) {
            $this->seedSettings();
            $setup = $this->makeFeeSetup(['is_share' => true, 'amount' => '1000.00']);

            $seller = $this->makeMember();
            $seller->associatorInfo()->create(['membership_no' => '601', 'num_or_shares' => $shares]);

            $first = $this->makeMember();
            $first->associatorInfo()->create(['membership_no' => '602', 'num_or_shares' => 0]);

            $second = $this->makeMember();
            $second->associatorInfo()->create(['membership_no' => '603', 'num_or_shares' => 0]);

            MemberShareBalance::create([
                'member_id' => $seller->id,
                'fee_setup_id' => $setup->id,
                'shares' => $shares,
            ]);

            return [$seller, $first, $second, $setup];
        });
    }

    /**
     * One seller, several buyers, one document - the shape of the legacy screen
     * and of the act it records.
     *
     * A member disposing of a holding usually splits it between several people
     * on one day for one reason. Recorded as separate transfers, that decision
     * becomes three unrelated events that only look connected because their
     * dates match.
     */
    public function test_one_document_can_split_a_holding_between_several_buyers(): void
    {
        $token = $this->staffToken();
        [$seller, $first, $second, $setup] = $this->sellerAndTwoBuyers(10);

        $this->postJson('/api/v1/staff/shares/transfers', [
            'seller_id' => $seller->id,
            'note' => 'Split between the two sons.',
            'transfers' => [
                ['buyer_id' => $first->id, 'fee_setup_id' => $setup->id, 'shares' => 3],
                ['buyer_id' => $second->id, 'fee_setup_id' => $setup->id, 'shares' => 2],
            ],
        ], $this->headers($token))
            ->assertCreated()
            ->assertJsonCount(2, 'data.transfers')
            ->assertJsonPath('data.shares', 5)
            ->assertJsonPath('data.amount', '5000.00')
            ->assertJsonPath('data.seller_balance', 5)
            ->assertJsonPath('data.transfers.0.buyer_balance', 3)
            ->assertJsonPath('data.transfers.1.buyer_balance', 2);

        // One reason, carried onto every row of the document: there was one
        // decision behind it.
        $this->withHeaders($this->headers($token))
            ->getJson('/api/v1/staff/shares/transfers')
            ->assertOk()
            ->assertJsonPath('data.0.note', 'Split between the two sons.')
            ->assertJsonPath('data.1.note', 'Split between the two sons.');
    }

    /**
     * The holding limits the DOCUMENT, not each row of it.
     *
     * Six to one buyer and six to another out of a holding of ten is the same
     * overdraft as one row of twelve. Checked row by row, both rows pass.
     */
    public function test_rows_cannot_overdraw_the_holding_between_them(): void
    {
        $token = $this->staffToken();
        [$seller, $first, $second, $setup] = $this->sellerAndTwoBuyers(10);

        $this->postJson('/api/v1/staff/shares/transfers', [
            'seller_id' => $seller->id,
            'transfers' => [
                ['buyer_id' => $first->id, 'fee_setup_id' => $setup->id, 'shares' => 6],
                ['buyer_id' => $second->id, 'fee_setup_id' => $setup->id, 'shares' => 6],
            ],
        ], $this->headers($token))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'SHARE_TRANSFER_REFUSED');

        // Nothing half-applied: the first row must not have happened either.
        $this->inTenant(function () use ($seller, $first) {
            $this->assertSame(10, (int) $seller->associatorInfo()->value('num_or_shares'));
            $this->assertSame(0, (int) $first->associatorInfo()->value('num_or_shares'));
            $this->assertSame(0, \App\Models\Tenant\ShareTransfer::count());
        });
    }

    /**
     * The same buyer twice for the same head is two rows that should be one.
     *
     * Whichever is read second looks like a correction of the first, and the
     * member's statement shows two entries for one event.
     */
    public function test_the_same_buyer_cannot_appear_twice_for_one_fee_head(): void
    {
        $token = $this->staffToken();
        [$seller, $buyer, , $setup] = $this->sellerAndTwoBuyers(10);

        $this->postJson('/api/v1/staff/shares/transfers', [
            'seller_id' => $seller->id,
            'transfers' => [
                ['buyer_id' => $buyer->id, 'fee_setup_id' => $setup->id, 'shares' => 2],
                ['buyer_id' => $buyer->id, 'fee_setup_id' => $setup->id, 'shares' => 3],
            ],
        ], $this->headers($token))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'SHARE_TRANSFER_REFUSED');
    }

    /**
     * The amount is the association's arithmetic, not the officer's.
     *
     * It is the value of the instalments that moved, and it reaches the
     * member's statement and the paid report - so a figure sent by the client
     * is ignored outright rather than trusted and recorded.
     */
    public function test_the_amount_is_computed_from_the_fee_head_not_from_the_request(): void
    {
        $token = $this->staffToken();
        [$seller, $buyer, , $setup] = $this->sellerAndTwoBuyers(10);

        $this->postJson('/api/v1/staff/shares/transfers', [
            'seller_id' => $seller->id,
            'transfers' => [
                // A price somebody made up, sent alongside the real request.
                ['buyer_id' => $buyer->id, 'fee_setup_id' => $setup->id, 'shares' => 3, 'amount' => 999999],
            ],
        ], $this->headers($token))
            ->assertCreated()
            // 3 instalments of a 1000.00 head.
            ->assertJsonPath('data.transfers.0.amount', '3000.00')
            ->assertJsonPath('data.amount', '3000.00');
    }

    /**
     * A DRIFTED TOTAL MUST NOT BREAK THE TRANSFER - it must be corrected by it.
     *
     * `associators_infos.num_or_shares` is UNSIGNED and used to be decremented
     * directly. Where it had fallen out of step with the balances - which is
     * exactly what D-19 did to the legacy data, and the reason this column is
     * watched at all - the subtraction went below zero and MySQL answered
     * `SQLSTATE[22003] value is out of range`. A raw 500, on the one path most
     * likely to meet corrupted data, refusing a transfer for a reason no
     * officer could act on.
     *
     * The seller here holds 10 by the balances and 0 by the denormalised
     * total: the worst case, where every possible subtraction underflows.
     */
    public function test_a_transfer_survives_and_repairs_a_drifted_share_total(): void
    {
        $token = $this->staffToken();
        [$seller, $buyer, $setup] = $this->twoMembersWithShares(10);

        // The drift, written straight to the column the way bad data arrives.
        $this->inTenant(fn () => $seller->associatorInfo()->update(['num_or_shares' => 0]));

        $this->postJson('/api/v1/staff/shares/transfers', [
            'seller_id' => $seller->id,
            'transfers' => [[
                'buyer_id' => $buyer->id,
                'fee_setup_id' => $setup->id,
                'shares' => 4,
            ]],
        ], $this->headers($token))->assertCreated();

        $this->inTenant(function () use ($seller, $buyer) {
            // Rebuilt from the balances rather than nudged from a wrong number:
            // 10 held less the 4 that moved.
            $this->assertSame(6, (int) $seller->associatorInfo()->value('num_or_shares'));
            $this->assertSame(4, (int) $buyer->associatorInfo()->value('num_or_shares'));
        });
    }

    /**
     * The total has to have somewhere to live, or the two stores drift apart by
     * exactly the amount transferred - silently, which is D-19 written fresh.
     *
     * Refused before anything is applied, and the refusal names the member
     * rather than reporting that a row was not found.
     */
    public function test_a_transfer_is_refused_when_a_party_has_no_society_record(): void
    {
        $token = $this->staffToken();
        [$seller, , $setup] = $this->twoMembersWithShares(10);

        $stranger = $this->inTenant(fn () => $this->makeMember(['name' => 'Nurul Absent']));

        $this->postJson('/api/v1/staff/shares/transfers', [
            'seller_id' => $seller->id,
            'transfers' => [[
                'buyer_id' => $stranger->id,
                'fee_setup_id' => $setup->id,
                'shares' => 2,
            ]],
        ], $this->headers($token))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'SHARE_TRANSFER_REFUSED')
            ->assertJsonPath('error.message', fn (string $m) => str_contains($m, 'Nurul Absent'));

        // Nothing moved: the seller still holds all ten.
        $this->inTenant(function () use ($seller) {
            $this->assertSame(10, (int) $seller->associatorInfo()->value('num_or_shares'));
            $this->assertSame(0, \App\Models\Tenant\ShareTransfer::count());
        });
    }

    public function test_it_refuses_to_transfer_more_shares_than_are_held(): void
    {
        $token = $this->staffToken();
        [$seller, $buyer, $setup] = $this->twoMembersWithShares(3);

        $this->postJson('/api/v1/staff/shares/transfers', [
            'seller_id' => $seller->id,
            'transfers' => [[
                'buyer_id' => $buyer->id,
                'fee_setup_id' => $setup->id,
                'shares' => 5,
            ]],
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
            'transfers' => [[
                'buyer_id' => $seller->id,
                'fee_setup_id' => $setup->id,
                'shares' => 1,
            ]],
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
                'transfers' => [[
                    'buyer_id' => $buyer->id,
                    'fee_setup_id' => $setup->id,
                    'shares' => $shares,
                ]],
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
            'transfers' => [[
                'buyer_id' => $buyer->id,
                'fee_setup_id' => $setup->id,
                'shares' => 2,
            ]],
        ], $this->headers($token))->assertCreated();

        $this->getJson('/api/v1/staff/shares/transfers', $this->headers($token))
            ->assertOk()
            ->assertJsonPath('data.0.shares', 2)
            // 2 instalments of a 1000.00 head. The amount is the value of what
            // moved, computed from the fee head - it is not a figure anybody
            // typed, because it reaches the member's statement and the paid
            // report and has to mean the same thing on every row.
            ->assertJsonPath('data.0.amount', '2000.00')
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
            'transfers' => [[
                'buyer_id' => $buyer->id,
                'fee_setup_id' => $setup->id,
                'shares' => 1,
            ]],
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
