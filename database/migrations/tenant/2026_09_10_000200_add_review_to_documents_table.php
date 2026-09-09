<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A member may submit their own documents, and staff decide on them (FR-MEM-8).
 *
 * WHY A DOCUMENT IS NOT A PROFILE FIELD
 * -------------------------------------
 * The profile-update queue already carries a member's requested changes, and
 * this could have gone in beside them - except that its `changes` column is a
 * JSON map of field to TEXT. There is nowhere in it to put a file, and widening
 * it to hold one would mean a queue row that sometimes references storage and
 * sometimes does not.
 *
 * So the review lives on the document itself: a member's upload arrives
 * `pending`, staff approve or reject it, and only approval makes it the one the
 * association holds.
 *
 * THE INDEX IS THE INTERESTING PART
 * ---------------------------------
 * A slot needs to hold a LIVE document and a PENDING one at the same time -
 * that is the whole point: the office keeps working from the NID it has while
 * the member's replacement waits. But it must not hold two live ones, or two
 * pending, or the queue shows a member twice for the same slot.
 *
 * Rejected rows are different again: there may be any number of them over the
 * years, and none should block a new submission. MySQL has no partial index, so
 * the same generated-column trick the gateway credentials use applies here -
 * live and pending rows get a lock value, rejected rows get NULL, and MySQL
 * permits unlimited NULLs in a unique index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            /*
             * `live` is what the association holds. `pending` is a member's
             * submission waiting on a decision. `rejected` is history.
             *
             * Everything filed before this migration was filed BY staff, which
             * is what live means, so the default backfills correctly.
             */
            $table->enum('status', ['live', 'pending', 'rejected'])
                ->default('live')
                ->after('slot')
                ->index();

            /*
             * A REJECTED DOCUMENT KEEPS ITS METADATA AND LOSES ITS BYTES.
             *
             * The member has to be able to see what happened to their upload -
             * "not approved: the number is not readable" is the difference
             * between a member who photographs it again and one who thinks the
             * office is ignoring them. But an image the association has refused
             * is not something to keep storing, so the file is deleted and
             * these two go null.
             */
            $table->string('disk', 20)->nullable()->change();
            $table->string('path')->nullable()->change();

            $table->text('decision_reason')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
        });

        /*
         * The old index cannot stay: it is unique on (owner, slot) and would
         * refuse a pending document for a slot that already has a live one,
         * which is exactly the state this migration exists to allow.
         */
        DB::statement('ALTER TABLE documents DROP INDEX uniq_owner_slot');

        DB::statement(
            'ALTER TABLE documents '
            .'ADD COLUMN review_lock VARCHAR(120) '
            ."GENERATED ALWAYS AS (CASE WHEN status IN ('live','pending') "
            ."THEN CONCAT(documentable_type, ':', documentable_id, ':', slot, ':', status) "
            .'ELSE NULL END) VIRTUAL, '
            .'ADD UNIQUE KEY uniq_owner_slot_status (review_lock)'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE documents DROP INDEX uniq_owner_slot_status');
        DB::statement('ALTER TABLE documents DROP COLUMN review_lock');

        // Anything not live has to go before the old index can be restored -
        // it has no way to express a second row for the same slot.
        DB::table('documents')->where('status', '!=', 'live')->delete();

        Schema::table('documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('decided_by');
            $table->dropColumn(['status', 'decision_reason', 'decided_at']);
            $table->unique(['documentable_type', 'documentable_id', 'slot'], 'uniq_owner_slot');
        });
    }
};
