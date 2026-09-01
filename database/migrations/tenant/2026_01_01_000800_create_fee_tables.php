<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fee heads, assignments and the fine clock.
 *
 * THE governing rule of this platform lives here (ADR-0005):
 *
 *     A fine is not an instalment.
 *
 * `amount` is the instalment. `fine_amount` is the penalty. They are separate
 * columns, they are credited to separate ledgers, and nothing may merge them.
 * The legacy system adds them together in several places, which is what
 * overstates savings, share counts and instalment counts.
 *
 * All money is DECIMAL(15,2) (FR-MON-5). The legacy schema uses DOUBLE, which
 * cannot be reconciled reliably.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_setups', function (Blueprint $table) {
            $table->id();
            $table->string('fee_head');

            // Monthly = a recurring subscription. Otherwise one-time: a share
            // purchase, an admission fee.
            $table->boolean('monthly')->default(true);

            $table->decimal('amount', 15, 2);

            // Paying this head mints shares (FR-SHR-1).
            $table->boolean('is_share')->default(false);

            // Instalments credit this ledger.
            $table->foreignId('ledger_id')->constrained()->restrictOnDelete();

            // Fines credit THIS one - a different account (FR-ACC-2). Mandatory
            // and staff-selectable (FR-FEE-2). The legacy system stamps it
            // silently from config, which cannot work across tenants with
            // different charts of accounts.
            $table->foreignId('fine_ledger_id')->constrained('ledgers')->restrictOnDelete();

            // Deactivate, never delete, once assignments exist (FR-FEE-3).
            $table->boolean('is_active')->default(true)->index();

            $table->timestamps();
        });

        Schema::create('fee_assigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->restrictOnDelete();
            $table->foreignId('fee_setup_id')->constrained()->restrictOnDelete();

            // The calendar month, normalised to YYYY-MM. This column is the fix
            // for the legacy duplicate-month bug: the legacy unique index is on
            // the exact assign_date, so 2026-01-01 and 2026-01-15 are two payable
            // instalments for one month (defect D-2).
            $table->char('period', 7)->index();

            $table->date('assign_date');

            // When the fine clock starts, derived from the association's
            // configured grace days.
            $table->date('fine_date');

            // The instalment owed. Copied from fee_setups.amount at assign time,
            // so a later price change does not rewrite history (FR-FEE-4).
            $table->decimal('amount', 15, 2);

            // The penalty. Recomputed nightly from the fine-date series, never
            // incremented - which is what makes accrual idempotent (FR-FINE-3).
            $table->decimal('fine_amount', 15, 2)->default(0);

            $table->enum('status', ['Unpaid', 'Requested', 'Paid'])
                ->default('Unpaid')
                ->index();

            $table->timestamps();

            // I-3: one instalment per member per head per calendar month.
            $table->unique(['member_id', 'fee_setup_id', 'period'], 'uniq_member_head_period');

            // Dues lookup - the app's hottest query.
            $table->index(['member_id', 'status']);

            // Nightly accrual scan.
            $table->index(['status', 'fine_date']);
        });

        // I-8: an assignment always carries a real instalment. A zero or negative
        // one means something upstream merged a fine in and lost the instalment.
        \DB::statement('ALTER TABLE fee_assigns ADD CONSTRAINT chk_assign_amount_positive CHECK (amount > 0)');
        \DB::statement('ALTER TABLE fee_assigns ADD CONSTRAINT chk_assign_fine_non_negative CHECK (fine_amount >= 0)');

        Schema::create('fine_dates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fee_assign_id')->constrained()->cascadeOnDelete();

            // Legacy spelling is `find_date`. Corrected here (M-13).
            $table->date('fine_date');

            $table->enum('status', ['incomplete', 'complete'])->default('incomplete');
            $table->timestamps();

            // Accrual recomputes from this series rather than incrementing a
            // counter, so running it twice in one day changes nothing.
            $table->unique(['fee_assign_id', 'fine_date'], 'uniq_assign_fine_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fine_dates');
        Schema::dropIfExists('fee_assigns');
        Schema::dropIfExists('fee_setups');
    }
};
