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
 * A dashboard figure is the report behind it, smaller. So each block asks for
 * the permission that grants that information in full elsewhere, and this is
 * where that holds.
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
     * The seeded operator: `dashboard.view` and share transfer, nothing more.
     *
     * This is the account the old endpoint handed the association's finances
     * to. It reaches the screen - that is what `dashboard.view` is for - and
     * finds none of the figures on it.
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

    /** Each block needs the permission that owns the report behind it. */
    public function test_each_block_needs_its_own_permission(): void
    {
        foreach ([
            'members' => 'members.view',
            'collections' => 'reports.paid',
            'outstanding' => 'reports.due',
            'approvals' => 'payments.view',
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

    /** Reading the register does not reveal what the association is owed. */
    public function test_member_counts_do_not_carry_the_money_with_them(): void
    {
        $token = $this->tokenWith(['dashboard.view', 'members.view']);

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
        $token = $this->tokenWith(['members.view']);

        $this->dashboard($token)->assertStatus(403);
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
        $token = $this->tokenWith(['dashboard.view', 'reports.paid']);

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
            self::assertMatchesRegularExpression('/^\d+\.\d{2}$/', $month['instalments']);
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
        $token = $this->tokenWith(['dashboard.view', 'reports.paid', 'reports.due']);

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
