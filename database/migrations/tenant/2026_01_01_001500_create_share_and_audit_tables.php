<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shares, transfers, and the audit trail.
 *
 * Share count derives from the ASSIGNED instalment, never the charged amount
 * (FR-SHR-2). The legacy ShareService divides item.amount by the share price, so
 * a fine folded into the item mints shares nobody bought - permanently, and those
 * shares can then be transferred away (defect D-8).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_share_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fee_setup_id')->nullable()->constrained()->nullOnDelete();

            $table->integer('shares')->default(0);

            $table->timestamps();

            $table->unique(['member_id', 'fee_setup_id'], 'uniq_member_share_head');
        });

        Schema::create('share_transfers', function (Blueprint $table) {
            $table->id();

            // The legacy admin UI calls this "Instalment Transfer", which is a
            // misnomer: it moves shares, not instalments.
            $table->foreignId('seller_id')->constrained('members')->restrictOnDelete();
            $table->foreignId('buyer_id')->constrained('members')->restrictOnDelete();
            $table->foreignId('fee_setup_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedInteger('shares');
            $table->decimal('amount', 15, 2)->default(0);
            $table->date('transferred_on')->index();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // A seller cannot transfer shares to themselves, and a transfer of zero
        // shares is a no-op that should never have been recorded (FR-SHR-4).
        \DB::statement('ALTER TABLE share_transfers ADD CONSTRAINT chk_transfer_shares_positive CHECK (shares > 0)');
        \DB::statement('ALTER TABLE share_transfers ADD CONSTRAINT chk_transfer_distinct_parties CHECK (seller_id <> buyer_id)');

        /**
         * Append-only audit of every money, membership and permission change
         * (NFR-SEC-7). Written inside the same transaction as the change it
         * records, so an audit gap means a rollback rather than a silent hole.
         */
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            $table->nullableMorphs('actor');    // staff, member, or null for system
            $table->morphs('subject');

            $table->string('action', 100)->index();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->text('reason')->nullable();

            $table->string('ip', 45)->nullable();
            $table->string('request_id', 64)->nullable();

            $table->timestamp('created_at')->nullable();
        });

        // Append-only, enforced by the Immutable model concern rather than by a
        // database trigger - same reasoning as ledger_traces: CREATE TRIGGER
        // needs SUPER, and the tenant's scoped user must not have it.
        // NFR-SEC-7 is proved by ImmutabilityTest.
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('share_transfers');
        Schema::dropIfExists('member_share_balances');
    }
};
