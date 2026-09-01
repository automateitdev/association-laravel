<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The chart of accounts and the double-entry ledger (FR-ACC-1, FR-ACC-2).
 *
 * Per association: a default chart is seeded at provisioning and then edited by
 * the association's own staff (A-3).
 *
 * Ledger traces are IMMUTABLE (FR-ACC-9). A correction is a reversing entry,
 * never an edit or a delete - which is why there is no soft-delete column here
 * and no updated_at that would suggest editing is expected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('type', ['asset', 'liability', 'equity', 'income', 'expense'])->index();
            $table->timestamps();
        });

        Schema::create('account_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_category_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('ledgers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_group_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('code', 50)->nullable()->unique();
            $table->decimal('opening_balance', 15, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->string('voucher_no', 50)->unique();
            $table->enum('type', ['payment', 'receipt', 'journal'])->index();
            $table->date('voucher_date')->index();
            $table->text('narration')->nullable();

            $table->enum('status', ['draft', 'approved', 'rejected'])
                ->default('draft')
                ->index();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->timestamps();
        });

        Schema::create('ledger_traces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ledger_id')->constrained()->restrictOnDelete();

            // Exactly one of these carries a value; the other is zero. Storing
            // them as separate columns rather than a signed amount makes an
            // unbalanced document visible as a simple SUM comparison (FR-ACC-6).
            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('credit', 15, 2)->default(0);

            // What produced this row: a payment, a voucher, or a reversal.
            $table->nullableMorphs('source');

            $table->string('reference', 100)->nullable()->index();
            $table->date('posted_on')->index();
            $table->text('narration')->nullable();

            // A reversing entry points at the row it reverses. The original is
            // never touched (FR-ACC-9).
            $table->foreignId('reverses_id')->nullable()->constrained('ledger_traces')->nullOnDelete();

            $table->timestamp('created_at')->nullable();

            $table->index(['ledger_id', 'posted_on']);
        });

        // A trace is never updated or deleted. Enforced by the Immutable model
        // concern, NOT by a database trigger.
        //
        // CREATE TRIGGER requires SUPER while binary logging is enabled, and a
        // tenant's scoped MySQL user deliberately does not have it. Granting
        // SUPER to every tenant user to protect one table would undo ADR-0001
        // layer 2 - a far worse trade than moving the guard up a layer. Managed
        // MySQL commonly withholds SUPER outright, so a trigger would also make
        // the platform unportable.
        //
        // FR-ACC-9 is therefore enforced by App\Models\Concerns\Immutable and
        // proved by ImmutabilityTest. Recorded as DQ-5 in bcs-docs/04-data-model.md.
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_traces');
        Schema::dropIfExists('vouchers');
        Schema::dropIfExists('ledgers');
        Schema::dropIfExists('account_groups');
        Schema::dropIfExists('account_categories');
    }
};
