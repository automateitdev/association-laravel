<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use App\Services\TenantSeedService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\TenantFixtures;
use Tests\TenantTestCase;

/**
 * The dashboard shows what the account may see, and nothing else.
 *
 * IT USED TO SHOW EVERYTHING. `GET /staff/dashboard` was gated on
 * `dashboard.view` alone and returned the whole payload, which made it a way
 * round every other permission on the platform. The seeded `operator` role
 * holds `dashboard.view`, `shares.view` and `shares.transfer` and nothing else
 * - and it was reading every member count, the association's total
 * collections, its total arrears and the approval backlog, none of which it may
 * reach by any other route.
 *
 * ONE PERMISSION PER CARD, and deliberately NOT the report permissions. The
 * first fix gated each block on the permission that owns the report behind it -
 * `members.view`, `reports.due` - which ties two different questions together:
 * whether somebody may see a COUNT, and whether they may open the register
 * behind it. A counter clerk who should see "2 members to admit" would have had
 * to be handed the whole register to get it.
 *
 * So an association composes its own landing page per role, and a card can be
 * shown to somebody who cannot open what it counts.
 */
class DashboardPermissionTest extends TenantTestCase
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

    /** @param  list<string>  $permissions */
    private function tokenWith(array $permissions): string
    {
        static $sequence = 0;
        $sequence++;

        return $this->inTenant(function () use ($permissions, $sequence) {
            app(TenantSeedService::class)->seedAll();

            $role = Role::findOrCreate("dash-{$sequence}", 'web');
            $role->syncPermissions(
                array_map(fn (string $p) => Permission::findByName($p, 'web'), $permissions)
            );

            $user = User::create([
                'name' => "Viewer {$sequence}",
                'email' => "dash{$sequence}@assoc.test",
                'password' => 'secret-password',
            ]);
            $user->assignRole($role);

            return $user->createToken('test', $user->getAllPermissions()->pluck('name')->all())
                ->plainTextToken;
        });
    }

    private function dashboard(string $token)
    {
        return $this->withHeaders($this->headers($token))->getJson('/api/v1/staff/dashboard');
    }

    // --------------------------------------------------------------- the leak

    /**
     * `dashboard.view` alone opens the page and fills none of it.
     *
     * The permission that reaches the screen carries no figures with it, which
     * is the property the whole model rests on.
     */
    public function test_an_account_with_only_dashboard_view_sees_no_figures(): void
    {
        $token = $this->tokenWith(['dashboard.view', 'shares.view', 'shares.transfer']);

        $response = $this->dashboard($token)
            ->assertStatus(200)
            ->assertJsonPath('meta.visible', []);

        // Not merely absent from `visible` - absent from the payload.
        self::assertSame([], $response->json('data'));
    }

    /** One permission per card, and each one brings exactly its own. */
    public function test_each_block_needs_its_own_permission(): void
    {
        foreach ([
            'members' => 'dashboard.members',
            'collections' => 'dashboard.collections',
            'outstanding' => 'dashboard.outstanding',
            'approvals' => 'dashboard.approvals',
        ] as $block => $permission) {
            $token = $this->tokenWith(['dashboard.view', $permission]);

            $response = $this->dashboard($token)->assertStatus(200);

            self::assertSame(
                [$block],
                $response->json('meta.visible'),
                "[{$permission}] should show exactly the [{$block}] block.",
            );
        }
    }

    /** The member counts do not carry the money with them. */
    public function test_member_counts_do_not_carry_the_money_with_them(): void
    {
        $token = $this->tokenWith(['dashboard.view', 'dashboard.members']);

        $response = $this->dashboard($token)->assertStatus(200);

        self::assertArrayHasKey('members', $response->json('data'));
        self::assertArrayNotHasKey('collections', $response->json('data'));
        self::assertArrayNotHasKey('outstanding', $response->json('data'));
        self::assertArrayNotHasKey('payments_pending_approval', $response->json('data'));
    }

    public function test_a_superadmin_sees_all_four(): void
    {
        $token = $this->inTenant(function () {
            app(TenantSeedService::class)->seedAll();

            $user = User::create([
                'name' => 'Boss',
                'email' => 'dashboss@assoc.test',
                'password' => 'secret-password',
            ]);
            $user->assignRole('superadmin');

            return $user->createToken('test', $user->getAllPermissions()->pluck('name')->all())
                ->plainTextToken;
        });

        $response = $this->dashboard($token)->assertStatus(200);

        self::assertEqualsCanonicalizing(
            ['members', 'collections', 'outstanding', 'approvals'],
            $response->json('meta.visible'),
        );
    }

    /** And the screen itself is still behind `dashboard.view`. */
    public function test_an_account_without_dashboard_view_is_refused(): void
    {
        $token = $this->tokenWith(['dashboard.members']);

        $this->dashboard($token)->assertStatus(403);
    }

    /**
     * SEEING A COUNT IS NOT BEING ABLE TO OPEN IT, which is the point of
     * splitting these off from the report permissions.
     *
     * An account with `dashboard.members` and no `members.view` gets the
     * figures and is still refused the register - exactly the arrangement a
     * cashier's role wants, and impossible while the cards were gated on the
     * report permissions.
     */
    public function test_a_card_can_be_visible_to_somebody_who_cannot_open_it(): void
    {
        $token = $this->tokenWith(['dashboard.view', 'dashboard.members', 'dashboard.approvals']);

        $this->dashboard($token)
            ->assertStatus(200)
            ->assertJsonPath('meta.visible', ['members', 'approvals']);

        $this->withHeaders($this->headers($token))
            ->getJson('/api/v1/staff/members')
            ->assertStatus(403);
    }

    /**
     * The seeded operator gets the two cards a counter clerk works from.
     *
     * This is the role the old endpoint handed the association's finances to.
     * It now sees what is waiting and who is unadmitted, and no money at all.
     */
    public function test_the_seeded_operator_sees_the_queue_and_the_register_counts(): void
    {
        $token = $this->inTenant(function () {
            app(TenantSeedService::class)->seedAll();

            $user = User::create([
                'name' => 'Counter',
                'email' => 'dashoperator@assoc.test',
                'password' => 'secret-password',
            ]);
            $user->assignRole('operator');

            return $user->createToken('test', $user->getAllPermissions()->pluck('name')->all())
                ->plainTextToken;
        });

        $this->dashboard($token)
            ->assertStatus(200)
            ->assertJsonPath('meta.visible', ['members', 'approvals']);
    }

    // ------------------------------------------------------- what it now says

    /**
     * A month, and the months before it.
     *
     * An all-time total against all-time arrears reads as a failing association
     * when it may be a young one - the two cover different spans and are not
     * comparable.
     */
    public function test_collections_carry_this_month_and_a_six_month_series(): void
    {
        $token = $this->tokenWith(['dashboard.view', 'dashboard.collections']);

        $response = $this->dashboard($token)->assertStatus(200);

        $collections = $response->json('data.collections');

        self::assertArrayHasKey('this_month', $collections);
        self::assertArrayHasKey('by_month', $collections);

        /*
         * SIX, always - a quiet month is a zero in the series and not a gap. A
         * chart drawn from only the months with rows closes up the empty ones
         * and turns a bad quarter into a straight line.
         */
        self::assertCount(6, $collections['by_month']);

        foreach ($collections['by_month'] as $month) {
            self::assertMatchesRegularExpression('/^\d{4}-\d{2}$/', $month['month']);

            // Both series, side by side. Never a third field adding them up.
            self::assertMatchesRegularExpression('/^\d+\.\d{2}$/', $month['instalments']);
            self::assertMatchesRegularExpression('/^\d+\.\d{2}$/', $month['fines']);
            self::assertSame(['month', 'label', 'instalments', 'fines'], array_keys($month));
        }

        // Oldest first, so a chart drawn left to right runs forwards in time.
        $months = array_column($collections['by_month'], 'month');
        $sorted = $months;
        sort($sorted);

        self::assertSame($sorted, $months);
    }

    /** Money leaves as a decimal string, here as everywhere. */
    public function test_every_figure_is_a_string_not_a_float(): void
    {
        $token = $this->tokenWith([
            'dashboard.view',
            'dashboard.collections',
            'dashboard.outstanding',
        ]);

        $data = $this->dashboard($token)->assertStatus(200)->json('data');

        foreach ([
            $data['collections']['instalments'],
            $data['collections']['fines'],
            $data['collections']['this_month']['instalments'],
            $data['outstanding']['instalments'],
            $data['outstanding']['fines'],
        ] as $amount) {
            self::assertIsString($amount);
            self::assertMatchesRegularExpression('/^\d+\.\d{2}$/', $amount);
        }
    }
}
