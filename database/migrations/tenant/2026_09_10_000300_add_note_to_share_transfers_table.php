<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why a transfer happened.
 *
 * The legacy screen collected this as an "Optional remark" and then PRINTED it,
 * in the Instalment Transfers Sent and Received tables on the member's invoice.
 * It is not an internal annotation: it is the line on the document handed to a
 * member explaining why instalments they paid for now belong to somebody else -
 * an inheritance, a family gift, a settlement. Dropping it in the rewrite left
 * that question with no answer anywhere in the system.
 *
 * Nullable, because plenty of transfers are self-evident and forcing a
 * sentence produces "transfer" typed a thousand times.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('share_transfers', function (Blueprint $table) {
            $table->string('note', 255)->nullable()->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('share_transfers', function (Blueprint $table) {
            $table->dropColumn('note');
        });
    }
};
