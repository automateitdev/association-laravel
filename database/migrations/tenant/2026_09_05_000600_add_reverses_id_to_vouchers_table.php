<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which document a reversal undoes.
 *
 * The link already existed one level down: every reversing `ledger_trace`
 * points at the trace it cancels. That is enough to keep the ledger honest, and
 * it is how a second reversal is refused - but it is the wrong place to ask the
 * question from a list of vouchers. Answering "has this been reversed?" for
 * twenty-five rows meant loading each one's traces and then asking whether
 * anything pointed at them, which is a query per row to learn one boolean.
 *
 * So the same fact is recorded at the document level too. A screen can now say
 * "reversed by REV-2026-0004" without offering a Reverse button that the server
 * would only refuse, and a reader of the vouchers table can see the pair
 * without joining through the accounts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            /*
             * Nullable because almost no voucher is a reversal, and
             * `nullOnDelete` rather than cascade: an original is never deleted
             * once approved, but if one ever were, losing the reversal along
             * with it would take a real posting out of the accounts.
             */
            $table->foreignId('reverses_id')
                ->nullable()
                ->after('status')
                ->constrained('vouchers')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reverses_id');
        });
    }
};
