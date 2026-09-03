<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Tenant\FeeAssign;
use App\Models\User;
use App\Services\FeeAssignService;
use App\Services\TenantSeedService;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\TenantFixtures;
use Tests\TenantTestCase;

/**
 * Report downloads (FR-REP-7, FR-REP-8).
 *
 * The tests that matter here are not "does a file come back". They are the
 * three ways an export can come back looking perfectly fine and be wrong:
 *
 *   - the numbers arrive as TEXT, so nobody can sum them (the legacy defect);
 *   - the totals in the file disagree with the totals on the screen it came
 *     from;
 *   - a fine gets folded into an instalment column somewhere along the way.
 *
 * Each of those produces a file that opens, looks like a report, and misleads.
 */
class ReportExportTest extends TenantTestCase
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

    private function staffToken(string $role = 'superadmin'): string
    {
        return $this->inTenant(function () use ($role) {
            app(TenantSeedService::class)->seedAll();

            static $sequence = 0;
            $sequence++;

            $user = User::create([
                'name' => "Staff {$sequence}",
                'email' => "reports{$sequence}@assoc.test",
                'password' => 'secret-password',
            ]);
            $user->assignRole($role);

            return $user->createToken('test', $user->getAllPermissions()->pluck('name')->all())
                ->plainTextToken;
        });
    }

    /**
     * A staff account holding exactly the permissions named - nothing else.
     *
     * @param  list<string>  $permissions
     */
    private function tokenWithPermissions(array $permissions): string
    {
        return $this->inTenant(function () use ($permissions) {
            app(TenantSeedService::class)->seedAll();

            static $sequence = 0;
            $sequence++;

            $role = Role::findOrCreate("narrow-{$sequence}", 'web');
            $role->syncPermissions(
                array_map(fn (string $p) => Permission::findByName($p, 'web'), $permissions)
            );

            $user = User::create([
                'name' => "Narrow {$sequence}",
                'email' => "narrow{$sequence}@assoc.test",
                'password' => 'secret-password',
            ]);
            $user->assignRole($role);

            return $user->createToken('test', $user->getAllPermissions()->pluck('name')->all())
                ->plainTextToken;
        });
    }

    /**
     * Two members owing money, one of them carrying a fine.
     *
     * The fine is the point: a report with no fines anywhere cannot catch a
     * fine being added into an instalment column.
     */
    private function seedDues(): void
    {
        $this->inTenant(function () {
            $this->seedSettings();
            $setup = $this->makeFeeSetup();

            $withFine = $this->makeMember(['name' => 'Aleya Khatun']);
            $assign = app(FeeAssignService::class)->assign($withFine->id, $setup, '2026-01');
            $assign->update(['fine_amount' => '250.00']);

            $plain = $this->makeMember(['name' => 'Babul Mia']);
            app(FeeAssignService::class)->assign($plain->id, $setup, '2026-01');
        });
    }

    // ---- the formats arrive ---------------------------------------------

    public function test_every_format_downloads_for_both_reports(): void
    {
        $this->seedDues();
        $token = $this->staffToken();

        $types = [
            'csv' => 'text/csv',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'pdf' => 'application/pdf',
        ];

        foreach (['due-info', 'memberwise-paid'] as $report) {
            foreach ($types as $format => $contentType) {
                $response = $this->withHeaders($this->headers($token))
                    ->get("/api/v1/staff/reports/{$report}/export?format={$format}");

                $response->assertSuccessful();

                $this->assertStringContainsString(
                    $contentType,
                    (string) $response->headers->get('content-type'),
                    "{$report} as {$format} came back with the wrong content type"
                );

                $this->assertNotEmpty(
                    $this->bodyOf($response),
                    "{$report} as {$format} came back empty"
                );
            }
        }
    }

    public function test_an_unknown_format_is_rejected(): void
    {
        $token = $this->staffToken();

        $this->withHeaders($this->headers($token))
            ->get('/api/v1/staff/reports/due-info/export?format=docx')
            ->assertStatus(422);

        // Absent, rather than merely wrong. Defaulting to some format would
        // hand back a file the caller did not ask for.
        $this->withHeaders($this->headers($token))
            ->get('/api/v1/staff/reports/due-info/export')
            ->assertStatus(422);
    }

    // ---- FR-REP-8: the file and the screen must agree --------------------

    public function test_the_csv_totals_match_the_totals_on_screen(): void
    {
        $this->seedDues();
        $token = $this->staffToken();

        $screen = $this->withHeaders($this->headers($token))
            ->getJson('/api/v1/staff/reports/due-info')
            ->assertSuccessful()
            ->json('meta');

        $rows = $this->csvRows($token, 'due-info');
        $totals = end($rows);

        $this->assertSame('Total', $totals[$this->column($rows, 'No.')]);
        $this->assertSame(
            (string) $screen['instalments_due_count'],
            $totals[$this->column($rows, 'Instalments due')]
        );
        $this->assertSame($screen['instalments_due'], $totals[$this->column($rows, 'Instalments (BDT)')]);
        $this->assertSame($screen['fines_due'], $totals[$this->column($rows, 'Fines (BDT)')]);
        $this->assertSame($screen['total_due'], $totals[$this->column($rows, 'Total due (BDT)')]);
    }

    public function test_the_totals_row_is_the_sum_of_the_rows(): void
    {
        $this->seedDues();
        $rows = $this->csvRows($this->staffToken(), 'due-info');

        $instalmentsAt = $this->column($rows, 'Instalments (BDT)');
        $finesAt = $this->column($rows, 'Fines (BDT)');
        $totalAt = $this->column($rows, 'Total due (BDT)');

        $body = $rows;
        array_shift($body);
        $totals = array_pop($body);

        $this->assertNotEmpty($body, 'no member rows to total');

        $instalments = '0.00';
        $fines = '0.00';

        foreach ($body as $row) {
            $instalments = bcadd($instalments, $row[$instalmentsAt], 2);
            $fines = bcadd($fines, $row[$finesAt], 2);
        }

        $this->assertSame($instalments, $totals[$instalmentsAt]);
        $this->assertSame($fines, $totals[$finesAt]);
        $this->assertSame(bcadd($instalments, $fines, 2), $totals[$totalAt]);
    }

    // ---- FR-REP-7: numbers a spreadsheet can actually add ----------------

    public function test_money_cells_carry_no_currency_and_the_header_names_it(): void
    {
        $this->seedDues();
        $rows = $this->csvRows($this->staffToken(), 'due-info');

        // The currency is named once, in the header, and nowhere in a cell.
        $money = [
            $this->column($rows, 'Instalments (BDT)'),
            $this->column($rows, 'Fines (BDT)'),
            $this->column($rows, 'Total due (BDT)'),
        ];

        foreach (array_slice($rows, 1) as $row) {
            foreach ($money as $column) {
                $this->assertMatchesRegularExpression(
                    '/^\d+\.\d{2}$/',
                    $row[$column],
                    "money cell [{$row[$column]}] is not a plain decimal - a spreadsheet cannot sum it"
                );
            }
        }
    }

    public function test_xlsx_money_cells_are_numeric_not_text(): void
    {
        $this->seedDues();

        $path = $this->downloadToFile($this->staffToken(), 'due-info', 'xlsx');
        $sheet = IOFactory::load($path)->getActiveSheet();

        // Row 1 is the header, so the first member is row 2.
        foreach (['D2', 'E2', 'F2'] as $reference) {
            $cell = $sheet->getCell($reference);

            $this->assertSame(
                DataType::TYPE_NUMERIC,
                $cell->getDataType(),
                "cell {$reference} is text - this is the legacy defect, where every "
                .'exported figure looked right and could not be added up'
            );

            $this->assertIsFloat($cell->getValue() + 0);
        }

        @unlink($path);
    }

    // ---- the rule the whole system exists to keep ------------------------

    public function test_a_fine_is_never_folded_into_the_instalment_column(): void
    {
        $this->seedDues();

        $rows = $this->csvRows($this->staffToken(), 'due-info');
        $totals = end($rows);

        $expectedFine = $this->inTenant(
            fn () => FeeAssign::query()->sum('fine_amount')
        );

        $this->assertSame('250.00', number_format((float) $expectedFine, 2, '.', ''));

        // The fine is in the fine column, and NOT in the instalment column.
        $this->assertSame('250.00', $totals[$this->column($rows, 'Fines (BDT)')]);
        $this->assertSame('2000.00', $totals[$this->column($rows, 'Instalments (BDT)')]);
        $this->assertSame('2250.00', $totals[$this->column($rows, 'Total due (BDT)')]);
    }

    // ---- who may download ------------------------------------------------

    public function test_reading_a_report_on_screen_does_not_grant_the_download(): void
    {
        $this->seedDues();

        // Can see the report. Cannot take the file.
        $token = $this->tokenWithPermissions(['reports.due']);

        $this->withHeaders($this->headers($token))
            ->getJson('/api/v1/staff/reports/due-info')
            ->assertSuccessful();

        $this->withHeaders($this->headers($token))
            ->get('/api/v1/staff/reports/due-info/export?format=csv')
            ->assertForbidden();
    }

    public function test_the_export_permission_alone_does_not_open_a_report(): void
    {
        $this->seedDues();

        // Holds reports.export, but not the permission for THIS report.
        $token = $this->tokenWithPermissions(['reports.export', 'reports.paid']);

        $this->withHeaders($this->headers($token))
            ->get('/api/v1/staff/reports/due-info/export?format=csv')
            ->assertForbidden();
    }

    public function test_an_unauthenticated_request_is_refused(): void
    {
        $this->withHeaders($this->headers())
            ->get('/api/v1/staff/reports/due-info/export?format=csv')
            ->assertUnauthorized();
    }

    // ---- Bengali survives the round trip ---------------------------------

    public function test_a_bengali_member_name_survives_the_csv(): void
    {
        $name = 'নাসরীন আক্তার';

        $this->inTenant(function () use ($name) {
            $this->seedSettings();
            $setup = $this->makeFeeSetup();
            $member = $this->makeMember(['name' => $name]);
            app(FeeAssignService::class)->assign($member->id, $setup, '2026-01');
        });

        $csv = $this->download($this->staffToken(), 'due-info', 'csv');

        /*
         * The BOM, checked explicitly.
         *
         * Without it Excel on Windows reads the file as the system codepage and
         * every Bengali name becomes mojibake - the data is correct and the
         * file is unreadable, which is a failure nobody reports as a bug
         * because the export "worked".
         */
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString($name, $csv);
    }

    // ---- helpers ---------------------------------------------------------
    /**
     * The index of a column, by its header.
     *
     * These assertions used to index by POSITION - totals[3] for instalments,
     * totals[4] for fines. Adding a membership-number column to the front of
     * both reports shifted every one of them by one, and four tests failed
     * together. That was the tests doing their job, but by position they were
     * one column-reorder away from asserting the WRONG figure and passing.
     *
     * By name they break only when a column genuinely goes away.
     *
     * @param  list<list<string>>  $rows
     */
    private function column(array $rows, string $header): int
    {
        $index = array_search($header, $rows[0], true);

        $this->assertNotFalse(
            $index,
            "the report has no [{$header}] column; it has: ".implode(', ', $rows[0])
        );

        return (int) $index;
    }


    private function download(string $token, string $report, string $format): string
    {
        $response = $this->withHeaders($this->headers($token))
            ->get("/api/v1/staff/reports/{$report}/export?format={$format}");

        $response->assertSuccessful();

        return $this->bodyOf($response);
    }

    /**
     * The body, whichever kind of response carried it.
     *
     * CSV and xlsx are streamed - they can be large, and a report is not
     * paginated. The PDF is not: mPDF builds the whole document in memory
     * before it can emit a byte, so there is nothing to stream. Asking a
     * plain response for its streamed content throws, which is how this
     * helper earned its existence.
     */
    private function bodyOf(\Illuminate\Testing\TestResponse $response): string
    {
        return $response->baseResponse instanceof \Symfony\Component\HttpFoundation\StreamedResponse
            ? $response->streamedContent()
            : (string) $response->getContent();
    }

    private function downloadToFile(string $token, string $report, string $format): string
    {
        $path = tempnam(sys_get_temp_dir(), 'report').'.'.$format;
        file_put_contents($path, $this->download($token, $report, $format));

        return $path;
    }

    /**
     * The CSV parsed back into rows, BOM stripped.
     *
     * @return list<list<string>>
     */
    private function csvRows(string $token, string $report): array
    {
        $csv = ltrim($this->download($token, $report, 'csv'), "\xEF\xBB\xBF");

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
