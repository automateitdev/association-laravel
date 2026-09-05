<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The lines of a voucher — the double entry itself (FR-ACC-4).
 *
 * `vouchers` shipped without them, which meant a voucher could name a date, a
 * type and a narration but could not say what it actually posts. A voucher
 * without lines is a note, not an accounting document.
 *
 * ONE SIDE PER LINE. A line carries a debit or a credit, never both: an entry
 * that is 500 debit and 200 credit is two entries somebody has added up in
 * their head, and it makes the balance check meaningless. Enforced in the
 * service rather than by a constraint, because the message a person needs -
 * which line, and what to do - is not something a CHECK can give them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voucher_lines', function (Blueprint $table) {
            $table->id();

            // Cascade: lines have no meaning apart from their voucher, and a
            // voucher can only be deleted while it is still a draft.
            $table->foreignId('voucher_id')->constrained()->cascadeOnDelete();

            $table->foreignId('ledger_id')->constrained()->restrictOnDelete();

            // DECIMAL, like every money column (FR-MON-5).
            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('credit', 15, 2)->default(0);

            /**
             * Per line, and optional. The voucher's own narration says why the
             * document exists; a line's says why this particular account is in
             * it, which is what somebody reading the ledger a year later needs.
             */
            $table->text('narration')->nullable();

            $table->timestamps();

            $table->index('voucher_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_lines');
    }
};
