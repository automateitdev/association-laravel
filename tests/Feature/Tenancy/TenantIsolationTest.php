<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Tenant isolation - the tests that make multi-tenancy real.
 *
 * T-11 and T-24 from bcs-docs/08-testing-strategy.md. These are merge blockers,
 * not advisory: a failure here is a data breach, not a bug.
 *
 * Both tenants deliberately use IDENTICAL primary keys for their rows. That is
 * the trick that makes a leak visible - with different ids a leak returns "not
 * found" and looks like correct behaviour.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $alpha;

    private Tenant $beta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('tenant:provision', ['slug' => 'alpha-soc'])->assertSuccessful();
        $this->artisan('tenant:provision', ['slug' => 'beta-soc'])->assertSuccessful();

        $this->alpha = Tenant::find('alpha-soc');
        $this->beta = Tenant::find('beta-soc');
    }

    protected function tearDown(): void
    {
        foreach (Tenant::all() as $tenant) {
            try {
                $tenant->delete();
            } catch (\Throwable) {
            }
        }

        foreach (DB::connection('mysql')->select(
            "SELECT SCHEMA_NAME AS name FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE 'tenant%'"
        ) as $row) {
            DB::connection('mysql')->statement("DROP DATABASE IF EXISTS `{$row->name}`");
        }

        parent::tearDown();
    }

    /**
     * T-11: identical ids in both tenants; each context sees only its own row.
     */
    public function test_identical_ids_in_two_tenants_never_cross(): void
    {
        $this->alpha->run(function () {
            DB::table('users')->insert([
                'id' => 1,
                'name' => 'Alpha Member',
                'email' => 'same@example.test',
                'password' => 'x',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->beta->run(function () {
            DB::table('users')->insert([
                'id' => 1,                       // the SAME id, deliberately
                'name' => 'Beta Member',
                'email' => 'same@example.test',  // and the same unique email
                'password' => 'x',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $fromAlpha = $this->alpha->run(fn () => DB::table('users')->find(1)->name);
        $fromBeta = $this->beta->run(fn () => DB::table('users')->find(1)->name);

        $this->assertSame('Alpha Member', $fromAlpha);
        $this->assertSame('Beta Member', $fromBeta);

        $this->assertSame(1, $this->alpha->run(fn () => DB::table('users')->count()));
        $this->assertSame(1, $this->beta->run(fn () => DB::table('users')->count()));
    }

    public function test_each_tenant_has_its_own_physical_database(): void
    {
        $this->assertNotSame(
            $this->alpha->database()->getName(),
            $this->beta->database()->getName()
        );

        $this->assertSame('tenantalpha-soc', $this->alpha->database()->getName());
        $this->assertSame('tenantbeta-soc', $this->beta->database()->getName());
    }

    /**
     * T-24: the second, independent barrier from ADR-0001.
     *
     * Connection switching is application code, and application code has bugs.
     * A database user that physically cannot read another association's tables
     * turns a potential breach into a failed query.
     */
    public function test_a_tenant_database_user_cannot_read_another_tenants_database(): void
    {
        $alphaUser = $this->alpha->database()->getUsername();
        $alphaPassword = $this->alpha->database()->getPassword();
        $betaDatabase = $this->beta->database()->getName();

        config()->set('database.connections.alpha_scoped', array_merge(
            config('database.connections.mysql'),
            [
                'database' => $this->alpha->database()->getName(),
                'username' => $alphaUser,
                'password' => $alphaPassword,
            ]
        ));

        // Its own database: fine.
        $this->assertIsArray(
            DB::connection('alpha_scoped')->select('SELECT 1 AS ok')
        );

        // The other association's database: refused by the grant, not by us.
        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::connection('alpha_scoped')->select("SELECT * FROM `{$betaDatabase}`.users LIMIT 1");
    }

    public function test_the_grant_is_scoped_to_one_database(): void
    {
        $user = $this->alpha->database()->getUsername();
        $grants = DB::connection('mysql')->select("SHOW GRANTS FOR `{$user}`@`%`");

        $text = implode("\n", array_map(fn ($row) => reset($row), array_map('get_object_vars', $grants)));

        $this->assertStringContainsString('`tenantalpha-soc`.*', $text);
        $this->assertStringNotContainsString('ON *.* TO', str_replace('GRANT USAGE ON *.* TO', '', $text));
    }

    public function test_central_database_holds_no_member_data(): void
    {
        // ADR-0001: the central database is a registry. If a members or payments
        // table ever appears here, the isolation boundary has been breached by
        // convenience.
        foreach (['members', 'payment_infos', 'ledger_traces', 'fee_assigns'] as $forbidden) {
            $this->assertFalse(
                Schema::connection('mysql')->hasTable($forbidden),
                "The central database must not contain [{$forbidden}]."
            );
        }
    }
}
