<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The four nominee fields the legacy holds and this schema had nowhere to put.
 *
 * WHY THESE AND NOT OTHERS. A column-by-column sweep of the legacy against this
 * schema (see bcs-docs/12-legacy-sweep.md) found exactly four nominee fields
 * with no home here, and the production copy says they are not optional in
 * practice:
 *
 *   father_name            315 of 315 nominees
 *   mother_name            315 of 315
 *   gender                 315 of 315   (female 247, male 68)
 *   professional_details   301 of 315
 *
 * Every nominee in the association would have arrived missing all four. That is
 * not a feature gap that shows up in a permission count or a route diff - the
 * nominee endpoints exist and work - which is the same way member documents
 * (P-10) hid for months.
 *
 * WHY A NOMINEE NEEDS A PARENT'S NAME AT ALL. This is who the member's savings
 * go to if they die, and the association has to be able to identify them to a
 * bank and to a court. In Bangladesh a person is identified by name plus
 * father's and mother's names; two nominees called Rahima Begum are told apart
 * by their parents, not by an address that may be a decade stale.
 *
 * `profession` rather than the legacy's `professional_details`, and TEXT rather
 * than a string: the values are a job and a workplace together - "Lecturer,
 * Noakhali Science & Technology University", "Junior Consultant (Obs & Gynae),
 * National Institute of Cancer Research & Hospital (NICRH), Dhaka." - not a
 * one-word occupation.
 *
 * The gender enum matches `members.gender` exactly rather than the two values
 * the legacy data happens to contain. A nominee is a person on the same terms a
 * member is, and a column that cannot record what the members table can would
 * be a distinction nobody chose.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nominees', function (Blueprint $table) {
            $table->string('father_name')->nullable()->after('relation');
            $table->string('mother_name')->nullable()->after('father_name');
            $table->enum('gender', ['male', 'female', 'other'])->nullable()->after('mother_name');
            $table->text('profession')->nullable()->after('address');
        });
    }

    public function down(): void
    {
        Schema::table('nominees', function (Blueprint $table) {
            $table->dropColumn(['father_name', 'mother_name', 'gender', 'profession']);
        });
    }
};
