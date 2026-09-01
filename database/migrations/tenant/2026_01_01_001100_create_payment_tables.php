<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payments, their lines, and the machinery that keeps a phone from paying twice.
 *
 * The single most important column here is `payable_amount`, and the single most
 * important thing about it is what it does NOT contain:
 *
 *     payable_amount = SUM(item.amount)      -- instalments only, never a fine
 *     total_amount   = payable_amount + fine_amount
 *
 * The legacy online payment path writes the gateway's total - instalments PLUS
 * fine - into payable_amount (defect D-1), and every "savings" figure on every
 * report reads that column. Keeping the gateway's own figure in spg_pay_amount
 * is what stops the overstatement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_infos', function (Blueprint $table) {
            $table->id();

            // Generated inside the creating transaction, per the association's
            // configured format (FR-PAY-15).
            $table->string('invoice_no', 50)->unique();

            $table->foreignId('member_id')->constrained()->restrictOnDelete();

            // The cash or bank ledger the money lands in. Legacy spelling is
            // `ladger_id`, and it is a `text` column used as a foreign key
            // (defect D-13). Corrected here (M-8).
            $table->foreignId('ledger_id')->nullable()->constrained('ledgers')->restrictOnDelete();

            // INSTALMENTS ONLY. See the class comment.
            $table->decimal('payable_amount', 15, 2)->default(0);
            $table->decimal('fine_amount', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2)->default(0);

            // What the gateway said it took. Recorded for reconciliation and
            // never, under any circumstance, copied into payable_amount.
            $table->decimal('spg_pay_amount', 15, 2)->nullable();

            $table->enum('status', ['pending', 'completed', 'suspended', 'expired'])
                ->default('pending')
                ->index();

            $table->enum('payment_type', ['manual', 'online'])->default('manual');

            // Stored BEFORE the member is redirected (FR-PAY-7). The legacy flow
            // keeps the intent in the PHP session, so a lost session means money
            // taken at the bank with no invoice here (defect D-9). On a phone
            // that stops being an edge case.
            $table->string('gateway_reference')->nullable()->index();

            // An intent neither completed nor cancelled by this time expires and
            // returns its assignments to Unpaid (FR-PAY-8). Nothing in the legacy
            // system ever releases a stale Requested (defect D-18).
            $table->timestamp('expires_at')->nullable();

            $table->date('payment_date')->nullable()->index();
            $table->text('reason')->nullable();
            $table->json('documents')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();

            $table->timestamps();

            $table->index(['member_id', 'status', 'payment_date']);
            $table->index(['status', 'expires_at']);
        });

        Schema::create('payment_info_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_info_id')->constrained()->cascadeOnDelete();

            // NOT NULL: every paid line settles a real assignment. The legacy
            // table has no constraint at all, which is how orphan items exist
            // and inflate the instalment counts (defect D-7).
            $table->foreignId('fee_assign_id')->constrained()->restrictOnDelete();

            $table->char('period', 7);

            // The instalment. Equals its assignment's amount (I-7).
            $table->decimal('amount', 15, 2);

            // The fine, separately. Always.
            $table->decimal('fine_amount', 15, 2)->default(0);

            // Denormalised from the parent payment so that I-1 can be enforced by
            // the database rather than by hope - see the generated column below.
            // PaymentService is the only writer of both, inside one transaction.
            $table->enum('payment_status', ['pending', 'completed', 'suspended', 'expired'])
                ->default('pending')
                ->index();

            $table->timestamps();

            // I-2: an assignment appears at most once inside one invoice.
            $table->unique(['payment_info_id', 'fee_assign_id'], 'uniq_invoice_assign');

            $table->index('fee_assign_id');
        });

        // I-8: no fine-only lines. A line with no instalment is a fine that lost
        // its instalment somewhere upstream.
        \DB::statement('ALTER TABLE payment_info_items ADD CONSTRAINT chk_item_amount_positive CHECK (amount > 0)');
        \DB::statement('ALTER TABLE payment_info_items ADD CONSTRAINT chk_item_fine_non_negative CHECK (fine_amount >= 0)');

        // I-1: an assignment is settled by AT MOST ONE completed payment, ever.
        //
        // MySQL has no partial indexes, so this is a stored generated column that
        // is the assignment id when the payment is completed and NULL otherwise.
        // Duplicate NULLs are permitted in a unique index; duplicate ids are not.
        // The result: any number of pending attempts against one assignment, but
        // only one of them can ever complete.
        //
        // This is the constraint the legacy system lacks entirely - nothing in
        // that schema stops the same assignment being paid twice, which is what
        // the payment inconsistency audit exists to find after the fact.
        \DB::statement(<<<'SQL'
            ALTER TABLE payment_info_items
            ADD COLUMN settled_fee_assign_id BIGINT UNSIGNED
                GENERATED ALWAYS AS (
                    CASE WHEN payment_status = 'completed' THEN fee_assign_id ELSE NULL END
                ) STORED,
            ADD UNIQUE KEY uniq_settled_assign (settled_fee_assign_id)
        SQL);

        // Safe payment retry (FR-PAY-6). A flaky mobile connection retrying a
        // payment is the most likely source of NEW duplicates once an app exists.
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->foreignId('member_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('endpoint');

            // Same key + same body returns the original response; same key +
            // different body is a 409 (IDEMPOTENCY_KEY_REUSED).
            $table->string('request_hash', 64);
            $table->json('response')->nullable();

            $table->foreignId('payment_info_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        // Every webhook and verification response, raw, recorded BEFORE it is
        // acted on (FR-PAY-9). Append-only: this is the reconciliation trail when
        // a payment and the gateway disagree.
        Schema::create('gateway_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 50)->default('spg');
            $table->string('event_type', 50)->nullable();
            $table->string('reference')->nullable()->index();
            $table->json('payload');
            $table->foreignId('payment_info_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->text('processing_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gateway_events');
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('payment_info_items');
        Schema::dropIfExists('payment_infos');
    }
};
