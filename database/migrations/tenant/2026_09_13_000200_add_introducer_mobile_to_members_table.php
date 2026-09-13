<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The introducer's phone number, which the legacy form asks for and this
 * schema had nowhere to put.
 *
 * The legacy applicant form collects three things about the reference -
 * `ref_name`, `ref_mobile`, `ref_memeber_id_no` - and the rewrite kept two of
 * them as `introduced_by_name` and `introduced_by_member_id`. In the
 * production data all three are filled on the same 63 of 315 members: nobody
 * recorded a name without the number, which is what you would expect of a
 * field whose purpose is to let the office RING the person vouching for an
 * applicant. Dropping it would have made the reference unusable for the one
 * thing it is for.
 *
 * Nullable, because 252 members have no reference at all and a required column
 * would make them unsaveable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->string('introduced_by_mobile', 20)
                ->nullable()
                ->after('introduced_by_name');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn('introduced_by_mobile');
        });
    }
};
