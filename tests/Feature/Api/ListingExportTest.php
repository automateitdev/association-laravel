<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Tenant\Member;
use App\Models\User;
use App\Services\FeeAssignService;
use App\Services\TenantSeedService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\TenantFixtures;
use Tests\TenantTestCase;

/**
 * Downloads for the LISTINGS - members, the approvals queue, fee heads.
 *
 * The reports have their own suite. What is different here, and what these
 * tests are actually for:
 *
 *   - a listing export must obey the SCREEN'S filters and ignore its paging.
 *     A download of "page 2 of the members list" is not a thing anyone wants,
 *     and a download that quietly ignored the filter would hand over the whole
 *     membership to someone who asked for four people.
 *
 *   - each one needs the report's own permission AND `reports.export`. Being
 *     allowed to read a list on screen is not the same as being allowed to walk
 *     out of the building with it.
 */
class ListingExportTest extends TenantTestCase
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
                'name' => "Listing staff {$sequence}",
                'email' => "listing{$sequence}@assoc.test",
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
        return $this->inTenant(function () use ($permissions) {
            app(TenantSeedService::class)->seedAll();

            static $sequence = 0;
            $sequence++;

            $role = Role::findOrCreate("listing-narrow-{$sequence}", 'web');
            $role->syncPermissions(
                array_map(fn (string $p) => Permission::findByName($p, 'web'), $permissions)
            );

            $user = User::create([
                'name' => "Listing narrow {$sequence}",
                'email' => "listingnarrow{$sequence}@assoc.test",
                'password' => 'secret-password',
            ]);
            $user->assignRole($role);

            return $user->createToken('test', $user->getAllPermissions()->pluck('name')->all())
                ->plainTextToken;
        });
    }

    private function seedMembers(): void
    {
        $this->inTenant(function () {
            $this->seedSettings();
            $setup = $this->makeFeeSetup();

            $this->makeMember(['name' => 'Aleya Khatun', 'status' => Member::STATUS_ACTIVE]);
            $this->makeMember(['name' => 'Babul Mia', 'status' => Member::STATUS_SUSPENDED]);

            $third = $this->makeMember(['name' => 'Chameli Begum', 'status' => Member::STATUS_ACTIVE]);
            app(FeeAssignService::class)->assign($third->id, $setup, '2026-01');
        });
    }

    // ---- every listing downloads in every format -------------------------

    public function test_each_listing_downloads_in_all_three_formats(): void
    {
        $this->seedMembers();
        $token = $this->staffToken();

        $endpoints = [
            '/api/v1/staff/members/export',
            '/api/v1/staff/payments/pending/export',
            '/api/v1/staff/fee-setups/export',
        ];

        foreach ($endpoints as $endpoint) {
            foreach (['csv', 'xlsx', 'pdf'] as $format) {
                $response = $this->withHeaders($this->headers($token))
                    ->get("{$endpoint}?format={$format}");

                $response->assertSuccessful();
                $this->assertNotEmpty(
                    $this->bodyOf($response),
                    "{$endpoint} as {$format} came back empty"
                );
            }
        }
    }

    // ---- the filters travel with the download ----------------------------

    public function test_the_members_export_obeys_the_screen_filters(): void
    {
        $this->seedMembers();
        $token = $this->staffToken();

        $all = $this->csvRows($token, '/api/v1/staff/members/export');
        $suspended = $this->csvRows($token, '/api/v1/staff/members/export', ['status' => 'suspended']);
        $searched = $this->csvRows($token, '/api/v1/staff/members/export', ['q' => 'Aleya']);

        // Header row plus three members.
        $this->assertCount(4, $all);

        // Header plus the one suspended member.
        $this->assertCount(2, $suspended);
        $this->assertSame('Babul Mia', $suspended[1][1]);

        $this->assertCount(2, $searched);
        $this->assertSame('Aleya Khatun', $searched[1][1]);
    }

    public function test_the_export_ignores_paging_and_returns_every_match(): void
    {
        $this->seedMembers();
        $token = $this->staffToken();

        /*
         * per_page=1 would give the SCREEN one row. The download must not
         * honour it - someone who filtered to three members wants three, not
         * whichever one happened to be on the page they were looking at.
         */
        $rows = $this->csvRows($token, '/api/v1/staff/members/export', ['per_page' => 1, 'page' => 1]);

        $this->assertCount(4, $rows, 'the export followed the screen’s pagination');
    }

    public function test_a_date_range_narrows_the_members_export(): void
    {
        $this->seedMembers();
        $token = $this->staffToken();

        // Everything was created today, so a window that ends yesterday must
        // be empty - header row only.
        $rows = $this->csvRows($token, '/api/v1/staff/members/export', [
            'from' => now()->subYear()->toDateString(),
            'to' => now()->subDay()->toDateString(),
        ]);

        $this->assertCount(1, $rows);

        $today = $this->csvRows($token, '/api/v1/staff/members/export', [
            'from' => now()->toDateString(),
            'to' => now()->toDateString(),
        ]);

        $this->assertCount(4, $today);
    }

    // ---- sorting is the server's, and is not a free-text column ----------

    public function test_the_listing_sorts_on_the_server(): void
    {
        $this->seedMembers();
        $token = $this->staffToken();

        $descending = $this->withHeaders($this->headers($token))
            ->getJson('/api/v1/staff/members?sort=name&direction=desc')
            ->assertSuccessful()
            ->json('data');

        $this->assertSame('Chameli Begum', $descending[0]['name']);
    }

    public function test_an_unknown_sort_column_is_refused(): void
    {
        $token = $this->staffToken();

        // Not a 500, and emphatically not passed through to orderBy.
        $this->withHeaders($this->headers($token))
            ->getJson('/api/v1/staff/members?sort=password')
            ->assertStatus(422);
    }

    // ---- who may download ------------------------------------------------

    public function test_reading_a_listing_does_not_grant_the_download(): void
    {
        $this->seedMembers();

        $token = $this->tokenWithPermissions(['members.view']);

        $this->withHeaders($this->headers($token))
            ->getJson('/api/v1/staff/members')
            ->assertSuccessful();

        $this->withHeaders($this->headers($token))
            ->get('/api/v1/staff/members/export?format=csv')
            ->assertForbidden();
    }

    public function test_the_export_permission_alone_does_not_open_a_listing(): void
    {
        $this->seedMembers();

        // Holds reports.export, but nothing granting sight of members.
        $token = $this->tokenWithPermissions(['reports.export', 'fee-setups.view']);

        $this->withHeaders($this->headers($token))
            ->get('/api/v1/staff/members/export?format=csv')
            ->assertForbidden();
    }

    // ---- helpers ---------------------------------------------------------

    private function bodyOf(\Illuminate\Testing\TestResponse $response): string
    {
        return $response->baseResponse instanceof \Symfony\Component\HttpFoundation\StreamedResponse
            ? $response->streamedContent()
            : (string) $response->getContent();
    }

    /**
     * @param  array<string, string|int>  $query
     * @return list<list<string>>
     */
    private function csvRows(string $token, string $endpoint, array $query = []): array
    {
        $url = $endpoint.'?'.http_build_query($query + ['format' => 'csv']);

        $response = $this->withHeaders($this->headers($token))->get($url);
        $response->assertSuccessful();

        $csv = ltrim($this->bodyOf($response), "\xEF\xBB\xBF");

        $rows = [];
        $handle = fopen('php://memory', 'r+b');
        fwrite($handle, $csv);
        rewind($handle);

        while (($row = fgetcsv($handle)) !== false) {
            if ($row !== [null]) {
                $rows[] = $row;
            }
        }

        fclose($handle);

        return $rows;
    }
}
