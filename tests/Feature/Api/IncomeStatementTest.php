<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Tenant\AccountCategory;
use App\Models\Tenant\AccountGroup;
use App\Models\Tenant\Ledger;
use App\Models\Tenant\LedgerTrace;
use App\Models\User;
use App\Services\TenantSeedService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\TenantFixtures;
use Tests\TenantTestCase;

/**
 * The income statement (parity P-9).
 *
 * WHAT THESE TESTS ARE ACTUALLY DEFENDING
 * ---------------------------------------
 * The legacy report sums CREDITS on income ledgers and DEBITS on expense ones
 * and never looks at the other side. That reads correctly right up until
 * something is reversed - and a reversing entry is the only correction this
 * system permits (FR-ACC-9), so it is not a rare case, it is the case.
 *
 * A reversed receipt debits the income ledger. The legacy sum ignores debits,
 * so the income it reports stays exactly as it was and the association's
 * surplus is overstated by the whole of the reversed amount. The test named
 * for that is the one worth having: it passes against this implementation and
 * would fail against the legacy's.
 */
class IncomeStatementTest extends TenantTestCase
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
                'name' => "Accountant {$sequence}",
                'email' => "accounts{$sequence}@assoc.test",
                'password' => 'secret-password',
            ]);
            $user->assignRole('superadmin');

            return $user->createToken('test', $user->getAllPermissions()->pluck('name')->all())
                ->plainTextToken;
        });
    }

    private int $narrowSequence = 0;

    /** @param  list<string>  $permissions */
    private function tokenWithPermissions(array $permissions): string
    {
        $sequence = ++$this->narrowSequence;

        return $this->inTenant(function () use ($permissions, $sequence) {
            app(TenantSeedService::class)->seedAll();

            $role = Role::findOrCreate("statement-narrow-{$sequence}", 'web');
            $role->syncPermissions(
                array_map(fn (string $p) => Permission::findByName($p, 'web'), $permissions)
            );

            $user = User::create([
                'name' => "Narrow {$sequence}",
                'email' => "statementnarrow{$sequence}@assoc.test",
                'password' => 'secret-password',
            ]);
            $user->assignRole($role);

            return $user->createToken('test', $user->getAllPermissions()->pluck('name')->all())
                ->plainTextToken;
        });
    }

    /**
     * An income ledger, an expense ledger and a cash account to balance against.
     *
     * @return array{income: Ledger, expense: Ledger, cash: Ledger}
     */
    private function chart(): array
    {
        $income = AccountCategory::create(['name' => 'Income', 'type' => 'income']);
        $expense = AccountCategory::create(['name' => 'Expenses', 'type' => 'expense']);
        $asset = AccountCategory::create(['name' => 'Assets', 'type' => 'asset']);

        return [
            'income' => Ledger::create([
                'account_group_id' => AccountGroup::create([
                    'account_category_id' => $income->id,
                    'name' => 'Subscriptions',
                ])->id,
                'name' => 'Subscription Income',

                // Set on purpose: an opening balance is a POSITION, and a
                // statement covers a PERIOD. It must not reach the report.
                'opening_balance' => '5000.00',
            ]),
            'expense' => Ledger::create([
                'account_group_id' => AccountGroup::create([
                    'account_category_id' => $expense->id,
                    'name' => 'Administrative',
                ])->id,
                'name' => 'Office Rent',
            ]),
            'cash' => Ledger::create([
                'account_group_id' => AccountGroup::create([
                    'account_category_id' => $asset->id,
                    'name' => 'Cash and Bank',
                ])->id,
                'name' => 'Cash',
            ]),
        ];
    }

    private function trace(Ledger $ledger, string $debit, string $credit, string $on): void
    {
        LedgerTrace::create([
            'ledger_id' => $ledger->id,
            'debit' => $debit,
            'credit' => $credit,
            'posted_on' => $on,
        ]);
    }

    public function test_it_reports_income_expense_and_the_surplus_between_them(): void
    {
        $token = $this->staffToken();

        $this->inTenant(function () {
            $chart = $this->chart();

            $this->trace($chart['income'], '0.00', '10000.00', '2026-03-10');
            $this->trace($chart['cash'], '10000.00', '0.00', '2026-03-10');

            $this->trace($chart['expense'], '4000.00', '0.00', '2026-03-20');
            $this->trace($chart['cash'], '0.00', '4000.00', '2026-03-20');
        });

        $response = $this->withHeaders($this->headers($token))
            ->getJson('/api/v1/staff/reports/income-statement?from=2026-03-01&to=2026-03-31')
            ->assertStatus(200)
            ->assertJsonPath('meta.total_income', '10000.00')
            ->assertJsonPath('meta.total_expense', '4000.00')
            ->assertJsonPath('meta.net_surplus', '6000.00')
            ->assertJsonPath('meta.accounts', 2);

        // Income first, then expenses - the order a statement is read in.
        $response->assertJsonPath('data.0.section', 'income');
        $response->assertJsonPath('data.0.ledger', 'Subscription Income');
        $response->assertJsonPath('data.0.amount', '10000.00');

        // Signed, so the column sums to the surplus rather than to a figure
        // that means nothing.
        $response->assertJsonPath('data.1.section', 'expense');
        $response->assertJsonPath('data.1.amount', '-4000.00');

        /*
         * The cash account is an ASSET. It carries the other half of both
         * entries and belongs on a balance sheet, not here - if it leaked in,
         * `accounts` would be 3.
         */
        $response->assertJsonCount(2, 'data');
    }

    /**
     * THE ONE THAT MATTERS: a reversal has to take the income back out.
     *
     * The legacy report sums credits only, so the debit this posts is invisible
     * to it and the reversed 10,000 stays in the association's income.
     */
    public function test_a_reversal_removes_the_income_it_reverses(): void
    {
        $token = $this->staffToken();

        $this->inTenant(function () {
            $chart = $this->chart();

            $this->trace($chart['income'], '0.00', '10000.00', '2026-03-10');
            $this->trace($chart['cash'], '10000.00', '0.00', '2026-03-10');

            // The correction: the same amount back the other way.
            $this->trace($chart['income'], '10000.00', '0.00', '2026-03-12');
            $this->trace($chart['cash'], '0.00', '10000.00', '2026-03-12');
        });

        $this->withHeaders($this->headers($token))
            ->getJson('/api/v1/staff/reports/income-statement?from=2026-03-01&to=2026-03-31')
            ->assertStatus(200)
            ->assertJsonPath('meta.total_income', '0.00')
            ->assertJsonPath('meta.net_surplus', '0.00')
            ->assertJsonPath('data.0.amount', '0.00');
    }

    public function test_it_covers_the_period_asked_for_and_no_more(): void
    {
        $token = $this->staffToken();

        $this->inTenant(function () {
            $chart = $this->chart();

            $this->trace($chart['income'], '0.00', '1000.00', '2026-02-28');
            $this->trace($chart['income'], '0.00', '2000.00', '2026-03-15');
            $this->trace($chart['income'], '0.00', '4000.00', '2026-04-01');
        });

        $this->withHeaders($this->headers($token))
            ->getJson('/api/v1/staff/reports/income-statement?from=2026-03-01&to=2026-03-31')
            ->assertStatus(200)
            // Only March, and the 5,000 opening balance is not in it either.
            ->assertJsonPath('meta.total_income', '2000.00');
    }

    public function test_both_dates_are_required_because_a_statement_needs_a_period(): void
    {
        $token = $this->staffToken();

        $this->withHeaders($this->headers($token))
            ->getJson('/api/v1/staff/reports/income-statement')
            ->assertStatus(422);

        $this->withHeaders($this->headers($token))
            ->getJson('/api/v1/staff/reports/income-statement?from=2026-03-31&to=2026-03-01')
            ->assertStatus(422);
    }

    /**
     * Reading it on screen and walking out with the file are separate rights.
     *
     * The download requires BOTH permissions - the same rule the other report
     * exports carry, and the reason those routes list two middleware entries
     * rather than one `a|b`, which would be OR.
     */
    public function test_the_export_needs_the_report_permission_and_the_export_permission(): void
    {
        $viewOnly = $this->tokenWithPermissions(['reports.income-statement']);

        $this->withHeaders($this->headers($viewOnly))
            ->getJson('/api/v1/staff/reports/income-statement?from=2026-03-01&to=2026-03-31')
            ->assertStatus(200);

        $this->withHeaders($this->headers($viewOnly))
            ->get('/api/v1/staff/reports/income-statement/export?format=csv')
            ->assertStatus(403);

        $exportOnly = $this->tokenWithPermissions(['reports.export']);

        $this->withHeaders($this->headers($exportOnly))
            ->getJson('/api/v1/staff/reports/income-statement?from=2026-03-01&to=2026-03-31')
            ->assertStatus(403);
    }

    public function test_the_csv_carries_the_servers_own_surplus_as_the_column_total(): void
    {
        $token = $this->staffToken();

        $this->inTenant(function () {
            $chart = $this->chart();

            $this->trace($chart['income'], '0.00', '10000.00', '2026-03-10');
            $this->trace($chart['expense'], '4000.00', '0.00', '2026-03-20');
        });

        $csv = $this->withHeaders($this->headers($token))
            ->get('/api/v1/staff/reports/income-statement/export?format=csv&from=2026-03-01&to=2026-03-31')
            ->assertStatus(200)
            ->streamedContent();

        $this->assertStringContainsString('Subscription Income', $csv);
        $this->assertStringContainsString('Office Rent', $csv);

        // Signed in the file too, so a reader's own SUM over the column and the
        // total the server printed are the same number (FR-REP-8).
        $this->assertStringContainsString('-4000.00', $csv);
        $this->assertStringContainsString('6000.00', $csv);
    }
}
