<?php

declare(strict_types=1);

namespace Tests\Feature\Schema;

use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ParallelSlugs;
use Tests\TestCase;

/**
 * The money invariants, proved at the DATABASE level.
 *
 * These deliberately use raw queries rather than models. The point is that the
 * schema refuses bad data even when the service layer is bypassed - by a console
 * command, a migration script, or a future developer in a hurry. A guard that
 * only exists in PHP is a guard that the migration script will walk straight
 * past, and the migration script is exactly where this data goes wrong.
 *
 * Invariants covered: I-1, I-2, I-3, I-8.
 * See bcs-docs/04-data-model.md section 5.
 */
class MoneyInvariantsTest extends TestCase
{
    use ParallelSlugs;
    use RefreshDatabase;

    /** Base name; the live slug carries the parallel process token. */
    private const SLUG = 'invariants-co';

    private Tenant $tenant;

    private int $memberId;

    private int $feeSetupId;

    protected function setUp(): void
    {
        parent::setUp();

        $slug = $this->slugFor(self::SLUG);
        $this->artisan('tenant:provision', ['slug' => $slug])->assertSuccessful();
        $this->tenant = Tenant::find($slug);

        [$this->memberId, $this->feeSetupId] = $this->tenant->run(function () {
            $categoryId = DB::table('account_categories')->insertGetId([
                'name' => 'Income', 'type' => 'income', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $groupId = DB::table('account_groups')->insertGetId([
                'account_category_id' => $categoryId, 'name' => 'Subscriptions',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $ledgerId = DB::table('ledgers')->insertGetId([
                'account_group_id' => $groupId, 'name' => 'Monthly Subscription',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $fineLedgerId = DB::table('ledgers')->insertGetId([
                'account_group_id' => $groupId, 'name' => 'Fine Income',
                'created_at' => now(), 'updated_at' => now(),
            ]);

            $memberId = DB::table('members')->insertGetId([
                'name' => 'Test Member', 'status' => 'active',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $feeSetupId = DB::table('fee_setups')->insertGetId([
                'fee_head' => 'Monthly Subscription', 'monthly' => true,
                'amount' => 1000.00, 'ledger_id' => $ledgerId, 'fine_ledger_id' => $fineLedgerId,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return [$memberId, $feeSetupId];
        });
    }

    protected function tearDown(): void
    {
        try {
            Tenant::find($this->slugFor(self::SLUG))?->delete();
        } catch (\Throwable) {
        }

        $this->dropTenantArtefactsFor(self::SLUG);

        parent::tearDown();
    }

    /**
     * I-3: a member owes at most one instalment per fee head per calendar month.
     *
     * This is the constraint the legacy system lacks. Its unique index is on the
     * exact assign_date, so 2026-01-01 and 2026-01-15 are two payable
     * instalments for January (defect D-2). Keying on the normalised period
     * closes it at the schema level.
     */
    public function test_one_instalment_per_member_per_head_per_month(): void
    {
        $this->tenant->run(function () {
            $this->assignFee('2026-01', '2026-01-01');

            $this->expectException(QueryException::class);

            // Same month, different day - legal in the legacy schema, refused here.
            $this->assignFee('2026-01', '2026-01-15');
        });
    }

    /** I-2: an assignment appears at most once inside one invoice. */
    public function test_an_assignment_cannot_appear_twice_in_one_invoice(): void
    {
        $this->tenant->run(function () {
            $assignId = $this->assignFee('2026-02', '2026-02-01');
            $invoiceId = $this->createPayment('INV-001');

            $this->addItem($invoiceId, $assignId);

            $this->expectException(QueryException::class);
            $this->addItem($invoiceId, $assignId);
        });
    }

    /**
     * I-1: an assignment is settled by at most one COMPLETED payment, ever.
     *
     * Enforced by a stored generated column plus a unique index, because MySQL
     * has no partial indexes. Any number of pending attempts may exist against
     * one assignment; only one of them can ever complete.
     *
     * Nothing in the legacy schema stops this - which is precisely what the
     * payment inconsistency audit exists to detect after the fact.
     */
    public function test_an_assignment_can_only_be_settled_once(): void
    {
        $this->tenant->run(function () {
            $assignId = $this->assignFee('2026-03', '2026-03-01');

            $firstInvoice = $this->createPayment('INV-002');
            $this->addItem($firstInvoice, $assignId, status: 'completed');

            $secondInvoice = $this->createPayment('INV-003');

            $this->expectException(QueryException::class);
            $this->addItem($secondInvoice, $assignId, status: 'completed');
        });
    }

    /** Two PENDING attempts against one assignment are legal - only completion is exclusive. */
    public function test_two_pending_attempts_against_one_assignment_are_allowed(): void
    {
        $this->tenant->run(function () {
            $assignId = $this->assignFee('2026-04', '2026-04-01');

            $this->addItem($this->createPayment('INV-004'), $assignId, status: 'pending');
            $this->addItem($this->createPayment('INV-005'), $assignId, status: 'expired');

            $this->assertSame(2, DB::table('payment_info_items')->where('fee_assign_id', $assignId)->count());
        });
    }

    /** I-8: no fine-only lines. A line with no instalment lost its instalment upstream. */
    public function test_a_payment_line_must_carry_a_real_instalment(): void
    {
        $this->tenant->run(function () {
            $assignId = $this->assignFee('2026-05', '2026-05-01');
            $invoiceId = $this->createPayment('INV-006');

            $this->expectException(QueryException::class);

            // A fine with no instalment behind it.
            $this->addItem($invoiceId, $assignId, amount: 0.00, fine: 100.00);
        });
    }

    /** I-8: an assignment always carries a real instalment. */
    public function test_an_assignment_amount_must_be_positive(): void
    {
        $this->tenant->run(function () {
            $this->expectException(QueryException::class);
            $this->assignFee('2026-06', '2026-06-01', amount: 0.00);
        });
    }

    /** A negative fine would be a refund wearing the wrong hat. */
    public function test_a_fine_cannot_be_negative(): void
    {
        $this->tenant->run(function () {
            $this->expectException(QueryException::class);
            $this->assignFee('2026-07', '2026-07-01', fine: -50.00);
        });
    }

    /** Money is DECIMAL, so it neither drifts nor silently truncates (FR-MON-5). */
    public function test_money_columns_are_decimal_not_float(): void
    {
        $columns = DB::connection('mysql')->select(
            "SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ?
               AND COLUMN_NAME IN ('amount','fine_amount','payable_amount','total_amount','debit','credit','gateway_amount')",
            [$this->databaseNameFor(self::SLUG)]
        );

        $this->assertNotEmpty($columns);

        foreach ($columns as $column) {
            $this->assertSame(
                'decimal',
                $column->DATA_TYPE,
                "{$column->TABLE_NAME}.{$column->COLUMN_NAME} must be DECIMAL, not {$column->DATA_TYPE}."
            );
        }
    }

    // ---- helpers -------------------------------------------------------

    private function assignFee(
        string $period,
        string $assignDate,
        float $amount = 1000.00,
        float $fine = 0.00
    ): int {
        return DB::table('fee_assigns')->insertGetId([
            'member_id' => $this->memberId,
            'fee_setup_id' => $this->feeSetupId,
            'period' => $period,
            'assign_date' => $assignDate,
            'fine_date' => $assignDate,
            'amount' => $amount,
            'fine_amount' => $fine,
            'status' => 'Unpaid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createPayment(string $invoiceNo, string $status = 'pending'): int
    {
        return DB::table('payment_infos')->insertGetId([
            'invoice_no' => $invoiceNo,
            'member_id' => $this->memberId,
            'payable_amount' => 1000.00,
            'fine_amount' => 0.00,
            'total_amount' => 1000.00,
            'status' => $status,
            'payment_type' => 'manual',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function addItem(
        int $invoiceId,
        int $assignId,
        float $amount = 1000.00,
        float $fine = 0.00,
        string $status = 'pending'
    ): int {
        return DB::table('payment_info_items')->insertGetId([
            'payment_info_id' => $invoiceId,
            'fee_assign_id' => $assignId,
            'period' => '2026-01',
            'amount' => $amount,
            'fine_amount' => $fine,
            'payment_status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
