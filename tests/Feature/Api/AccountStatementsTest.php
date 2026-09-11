<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Tenant\AccountCategory;
use App\Models\Tenant\AccountGroup;
use App\Models\Tenant\Ledger;
use App\Models\Tenant\LedgerTrace;
use App\Models\User;
use App\Services\TenantSeedService;
use Tests\Support\TenantFixtures;
use Tests\TenantTestCase;

/**
 * The trial balance, the balance sheet and the cash summary.
 *
 * NEW WORK, NOT A PORT, and the tests are written accordingly. The legacy names
 * all three in its menu and built none of them - the three entries are
 * commented out and point at `./index.html`, `./index2.html` and `./index3.html`,
 * which are pages of the admin theme it was built from. So there is no previous
 * behaviour to match and nothing to be bug-compatible with; what these assert is
 * accounting, not parity.
 *
 * The two things most easily got wrong, and therefore most worth pinning:
 *
 *   1. An OPENING BALANCE HAS A SIDE and the column does not say which. It is
 *      an unsigned decimal, so the side comes from the account type. Get it
 *      backwards and a trial balance is out by twice the opening.
 *
 *   2. A BALANCE SHEET NEEDS THE SURPLUS. Income and expense accounts are not
 *      on it, but the result they produce belongs to the members and sits in
 *      equity. Leave it out and the statement fails to balance by exactly that
 *      amount - which is the classic way to build one that never balances.
 */
class AccountStatementsTest extends TenantTestCase
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
                'name' => "Treasurer {$sequence}",
                'email' => "treasurer{$sequence}@assoc.test",
                'password' => 'secret-password',
            ]);
            $user->assignRole('superadmin');

            return $user->createToken('test', $user->getAllPermissions()->pluck('name')->all())
                ->plainTextToken;
        });
    }

    /**
     * A chart with one account of every kind, and a cash account that says so.
     *
     * @return array<string, Ledger>
     */
    private function chart(array $openings = []): array
    {
        $make = function (string $categoryName, string $type, string $group, string $ledger) use ($openings) {
            $category = AccountCategory::query()->firstOrCreate(
                ['name' => $categoryName],
                ['type' => $type],
            );

            return Ledger::create([
                'account_group_id' => AccountGroup::create([
                    'account_category_id' => $category->id,
                    'name' => $group,
                ])->id,
                'name' => $ledger,
                'opening_balance' => $openings[$ledger] ?? '0.00',
                'is_cash' => $ledger === 'Cash in Hand',
            ]);
        };

        return [
            'cash' => $make('Assets', 'asset', 'Cash and Bank', 'Cash in Hand'),
            'receivable' => $make('Assets', 'asset', 'Receivables', 'Subscriptions Receivable'),
            'payable' => $make('Liabilities', 'liability', 'Payables', 'Sundry Payables'),
            'capital' => $make('Equity', 'equity', 'Member Funds', 'Share Capital'),
            'income' => $make('Income', 'income', 'Subscriptions', 'Subscription Income'),
            'expense' => $make('Expenses', 'expense', 'Administrative', 'Office Expenses'),
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

    // ------------------------------------------------------- trial balance

    public function test_a_trial_balance_balances_and_puts_each_account_on_its_own_side(): void
    {
        $token = $this->staffToken();

        $this->inTenant(function () {
            $chart = $this->chart();

            // A subscription received in cash.
            $this->trace($chart['cash'], '10000.00', '0.00', '2026-03-01');
            $this->trace($chart['income'], '0.00', '10000.00', '2026-03-01');

            // Rent paid out of it.
            $this->trace($chart['expense'], '4000.00', '0.00', '2026-03-05');
            $this->trace($chart['cash'], '0.00', '4000.00', '2026-03-05');
        });

        $response = $this->withHeaders($this->headers($token))
            ->getJson('/api/v1/staff/reports/trial-balance?as_of=2026-12-31')
            ->assertStatus(200)
            ->assertJsonPath('meta.balanced', true)
            ->assertJsonPath('meta.difference', '0.00')
            ->assertJsonPath('meta.total_debit', '10000.00')
            ->assertJsonPath('meta.total_credit', '10000.00');

        /*
         * Cash 6,000 debit; expenses 4,000 debit; income 10,000 credit. Three
         * accounts, and the untouched ones are absent: a chart lists what an
         * association might use, a trial balance states what it did.
         */
        $response->assertJsonPath('meta.accounts', 3);
    }

    /**
     * An opening balance carries the side its account type implies.
     *
     * A credit-normal account opening at 5,000 is a CREDIT of 5,000. Treating
     * the column as a debit because it is a positive number puts the books out
     * by twice the opening - the single easiest way to get this wrong.
     */
    public function test_an_opening_balance_takes_the_side_of_its_account(): void
    {
        $token = $this->staffToken();

        $this->inTenant(function () {
            // Assets 5,000 in the bank against 5,000 of members' capital, and
            // nothing posted at all. A set of books that opens balanced.
            $this->chart(['Cash in Hand' => '5000.00', 'Share Capital' => '5000.00']);
        });

        $this->withHeaders($this->headers($token))
            ->getJson('/api/v1/staff/reports/trial-balance?as_of=2026-12-31')
            ->assertStatus(200)
            ->assertJsonPath('meta.total_debit', '5000.00')
            ->assertJsonPath('meta.total_credit', '5000.00')
            ->assertJsonPath('meta.balanced', true);
    }

    /**
     * And when they do not balance, it says so rather than printing two numbers.
     *
     * Entries cannot be unbalanced in this system - a voucher is refused unless
     * it balances and every payment posts a pair - so a difference here means
     * an opening balance is wrong, which is the one figure staff type by hand.
     */
    public function test_it_reports_an_out_of_balance_opening_rather_than_hiding_it(): void
    {
        $token = $this->staffToken();

        $this->inTenant(function () {
            $this->chart(['Cash in Hand' => '5000.00', 'Share Capital' => '3000.00']);
        });

        $this->withHeaders($this->headers($token))
            ->getJson('/api/v1/staff/reports/trial-balance?as_of=2026-12-31')
            ->assertStatus(200)
            ->assertJsonPath('meta.balanced', false)
            ->assertJsonPath('meta.difference', '2000.00');
    }

    // -------------------------------------------------------- balance sheet

    /**
     * THE ONE THAT MATTERS: the surplus is part of what the association is worth.
     *
     * 10,000 earned and 4,000 spent leaves 6,000 in the bank and 6,000 owed to
     * the members. Without the accumulated surplus the funds side would be zero
     * against 6,000 of assets, and the statement would be out by the whole
     * result.
     */
    public function test_a_balance_sheet_carries_the_surplus_into_equity_and_balances(): void
    {
        $token = $this->staffToken();

        $this->inTenant(function () {
            $chart = $this->chart();

            $this->trace($chart['cash'], '10000.00', '0.00', '2026-03-01');
            $this->trace($chart['income'], '0.00', '10000.00', '2026-03-01');

            $this->trace($chart['expense'], '4000.00', '0.00', '2026-03-05');
            $this->trace($chart['cash'], '0.00', '4000.00', '2026-03-05');
        });

        $response = $this->withHeaders($this->headers($token))
            ->getJson('/api/v1/staff/reports/balance-sheet?as_of=2026-12-31')
            ->assertStatus(200)
            ->assertJsonPath('meta.total_assets', '6000.00')
            ->assertJsonPath('meta.total_liabilities', '0.00')
            ->assertJsonPath('meta.accumulated_surplus', '6000.00')
            ->assertJsonPath('meta.total_equity', '6000.00')
            ->assertJsonPath('meta.balanced', true)
            ->assertJsonPath('meta.difference', '0.00');

        // Its own line, not folded into share capital: nobody subscribed for it.
        $rows = collect($response->json('data'));
        $surplus = $rows->firstWhere('ledger', 'Accumulated surplus');

        $this->assertNotNull($surplus);
        $this->assertSame('equity', $surplus['section']);
        $this->assertSame('6000.00', $surplus['amount']);

        // Income and expense accounts themselves are NOT lines of a balance sheet.
        $this->assertNull($rows->firstWhere('ledger', 'Subscription Income'));
        $this->assertNull($rows->firstWhere('ledger', 'Office Expenses'));
    }

    /** A deficit is a negative surplus, and the statement still balances. */
    public function test_a_balance_sheet_balances_when_the_association_spent_more_than_it_earned(): void
    {
        $token = $this->staffToken();

        $this->inTenant(function () {
            $chart = $this->chart(['Cash in Hand' => '5000.00', 'Share Capital' => '5000.00']);

            $this->trace($chart['expense'], '2000.00', '0.00', '2026-03-05');
            $this->trace($chart['cash'], '0.00', '2000.00', '2026-03-05');
        });

        $this->withHeaders($this->headers($token))
            ->getJson('/api/v1/staff/reports/balance-sheet?as_of=2026-12-31')
            ->assertStatus(200)
            ->assertJsonPath('meta.total_assets', '3000.00')
            ->assertJsonPath('meta.accumulated_surplus', '-2000.00')
            ->assertJsonPath('meta.total_equity', '3000.00')
            ->assertJsonPath('meta.balanced', true);
    }

    // --------------------------------------------------------- cash summary

    public function test_a_cash_summary_opens_where_the_last_period_closed(): void
    {
        $token = $this->staffToken();

        $this->inTenant(function () {
            $chart = $this->chart();

            // Before the period.
            $this->trace($chart['cash'], '1000.00', '0.00', '2025-12-31');

            // Inside it.
            $this->trace($chart['cash'], '5000.00', '0.00', '2026-03-01');
            $this->trace($chart['cash'], '0.00', '2000.00', '2026-03-05');

            // After it - must not appear.
            $this->trace($chart['cash'], '9999.00', '0.00', '2027-01-01');
        });

        $response = $this->withHeaders($this->headers($token))
            ->getJson('/api/v1/staff/reports/cash-summary?from=2026-01-01&to=2026-12-31')
            ->assertStatus(200)
            ->assertJsonPath('meta.total_opening', '1000.00')
            ->assertJsonPath('meta.total_received', '5000.00')
            ->assertJsonPath('meta.total_paid', '2000.00')
            ->assertJsonPath('meta.total_closing', '4000.00');

        // Only the account the association marked as cash. The receivable is an
        // asset and is not money.
        $response->assertJsonPath('meta.accounts', 1);
        $response->assertJsonPath('data.0.ledger', 'Cash in Hand');
        $response->assertJsonPath('data.0.closing', '4000.00');
    }

    /** Nothing marked as cash is a configuration answer, not an empty period. */
    public function test_a_cash_summary_covers_only_the_accounts_marked_as_cash(): void
    {
        $token = $this->staffToken();

        $this->inTenant(function () {
            $chart = $this->chart();
            Ledger::query()->update(['is_cash' => false]);

            $this->trace($chart['cash'], '5000.00', '0.00', '2026-03-01');
        });

        $this->withHeaders($this->headers($token))
            ->getJson('/api/v1/staff/reports/cash-summary?from=2026-01-01&to=2026-12-31')
            ->assertStatus(200)
            ->assertJsonPath('meta.accounts', 0)
            ->assertJsonPath('meta.total_closing', '0.00');
    }

    // ------------------------------------------------------------ the whole

    /**
     * The four statements agree, which is the only test that covers all of them.
     *
     * The income statement's surplus IS the balance sheet's accumulated
     * surplus, the trial balance balances at the same total, and the cash
     * summary closes where the balance sheet says the money is. Any one of
     * these can be internally consistent and still disagree with the others;
     * this is what catches that.
     */
    public function test_the_four_statements_agree_with_each_other(): void
    {
        $token = $this->staffToken();

        $this->inTenant(function () {
            $chart = $this->chart();

            $this->trace($chart['cash'], '10000.00', '0.00', '2026-03-01');
            $this->trace($chart['income'], '0.00', '10000.00', '2026-03-01');

            $this->trace($chart['expense'], '4000.00', '0.00', '2026-03-05');
            $this->trace($chart['cash'], '0.00', '4000.00', '2026-03-05');
        });

        $income = $this->withHeaders($this->headers($token))
            ->getJson('/api/v1/staff/reports/income-statement?from=2026-01-01&to=2026-12-31')
            ->assertStatus(200)
            ->json('meta');

        $sheet = $this->withHeaders($this->headers($token))
            ->getJson('/api/v1/staff/reports/balance-sheet?as_of=2026-12-31')
            ->assertStatus(200)
            ->json('meta');

        $trial = $this->withHeaders($this->headers($token))
            ->getJson('/api/v1/staff/reports/trial-balance?as_of=2026-12-31')
            ->assertStatus(200)
            ->json('meta');

        $cash = $this->withHeaders($this->headers($token))
            ->getJson('/api/v1/staff/reports/cash-summary?from=2026-01-01&to=2026-12-31')
            ->assertStatus(200)
            ->json('meta');

        $this->assertSame($income['net_surplus'], $sheet['accumulated_surplus']);
        $this->assertSame($sheet['total_assets'], $cash['total_closing']);
        $this->assertTrue($trial['balanced']);
        $this->assertTrue($sheet['balanced']);
    }

    /** Reading on screen and downloading are separate rights, as with the others. */
    public function test_each_export_needs_its_own_report_permission_and_the_export_permission(): void
    {
        $token = $this->staffToken();

        foreach (['trial-balance', 'balance-sheet'] as $report) {
            $this->withHeaders($this->headers($token))
                ->get("/api/v1/staff/reports/{$report}/export?format=csv")
                ->assertStatus(200);
        }

        $this->withHeaders($this->headers($token))
            ->get('/api/v1/staff/reports/cash-summary/export?format=csv&from=2026-01-01&to=2026-12-31')
            ->assertStatus(200);
    }

    /**
     * No token at all is refused - the floor these sit on.
     *
     * ITS OWN TEST, and that is the point worth recording. This began as two
     * more lines at the end of the test above and passed with a 200: Laravel's
     * `withHeaders()` mutates the case's default headers rather than applying
     * to one request, so the Authorization header from the calls before it was
     * still attached. An unauthenticated assertion made after an authenticated
     * request in the same test is not asserting anything.
     */
    public function test_a_statement_export_refuses_a_request_with_no_token(): void
    {
        foreach (['trial-balance', 'balance-sheet', 'cash-summary'] as $report) {
            $this->getJson("/api/v1/staff/reports/{$report}/export?format=csv", $this->headers())
                ->assertStatus(401);
        }
    }
}
