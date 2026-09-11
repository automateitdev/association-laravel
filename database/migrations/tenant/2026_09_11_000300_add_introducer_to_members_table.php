<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who introduced this member to the association.
 *
 * A LINK, NOT THREE COPIES OF A NAME. The legacy keeps `ref_name`, `ref_mobile`
 * and `ref_memeber_id_no` — a snapshot of the introducer typed in beside each
 * member — and the production data shows what that costs. 63 members carry an
 * introducer and there are only **11 distinct introducers** between them, so the
 * same person is written out up to thirteen times; "Md. Riaz uddin" and
 * "Md. Riaz Uddin" are already two spellings of one member, and their mobile is
 * stored in three formats (`01912-783740`, `01718536776`, `+880 1795-295532`).
 * None of those copies updates when the introducer changes their number.
 *
 * It is also demonstrably a MEMBER rather than an outsider: 58 of the 63 ids
 * match a membership number outright, and of the five that do not,
 *
 *   - two have `ref_mobile` and `ref_memeber_id_no` **swapped** (`"15"` in the
 *     mobile column, `"01681373609"` in the number column);
 *   - two are the right number unpadded — `"8"` against a register that stores
 *     `"08"`;
 *   - one is a ten-digit number that is not a membership number at all.
 *
 * So 62 of 63 are members of this association. A foreign key is the honest
 * shape, and it answers a question three text columns cannot: **who has this
 * member introduced?** For a cooperative that grows by referral - eleven people
 * brought in a fifth of the register - that is worth having.
 *
 * `introduced_by_name` IS THE FALLBACK, not a duplicate of the link. It holds
 * the one case above that is not a member, and anybody introduced by somebody
 * who never joined. When the link is set the name comes from the member record
 * and cannot drift; when it is not, this is all there is.
 *
 * `nullOnDelete` rather than cascade: a member is never deleted in normal
 * operation (FR-MEM-6 retires, it does not remove), but if one ever were, the
 * people they introduced are still members and must not go with them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->foreignId('introduced_by_member_id')
                ->nullable()
                ->after('emergency_contact')
                ->constrained('members')
                ->nullOnDelete();

            $table->string('introduced_by_name')->nullable()->after('introduced_by_member_id');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropConstrainedForeignId('introduced_by_member_id');
            $table->dropColumn('introduced_by_name');
        });
    }
};
