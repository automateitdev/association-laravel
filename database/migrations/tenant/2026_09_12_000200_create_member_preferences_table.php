<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a member says they want from the association's housing (legacy
 * `member_choices`).
 *
 * COCSOL is a housing cooperative and this is the answer to the question it
 * exists to ask. The legacy table holds 988 rows and **24 real answers from 18
 * members** - see bcs-docs/12-legacy-sweep.md §2B, where the first count of it
 * was wrong by a factor of fifteen because `project_type` is written by the
 * system on every row and was counted as an answer.
 *
 * ONE ROW PER MEMBER PER PROJECT, which the data supports: members genuinely
 * answer differently per project. Member 112 wants Basundhara in Dhaka and
 * Chattogram outside it, and a single row per member could not hold both.
 *
 * WHAT IS DIFFERENT FROM THE LEGACY COLUMN FOR COLUMN
 * ---------------------------------------------------
 *
 * `project` IS AN ENUM AND THE TYPO IS GONE. The legacy writes
 * `other_distict`, and a value misspelled in the data is a value every query
 * has to misspell too, forever.
 *
 * `areas` IS A LIST OF STRINGS. The legacy stores a JSON array of
 * `{"label":"Uttara","value":"Uttara"}` - the same string twice, per entry,
 * because a front-end select control was serialised straight into the database.
 * Nothing reads the label.
 *
 * `flat_size_sft` IS A NUMBER. The legacy holds seven distinct strings for five
 * distinct sizes: `1,500 sft`, `1500 Sft`, `2,000 sft`, `2000 Sft`. A column
 * that cannot be sorted or averaged is not recording a size, it is recording
 * what somebody typed.
 *
 * `loan_percentage` IS A NUMBER, and `No` is 0. The legacy mixes `25%`, `50%`,
 * `75%`, `No` and - on one row - `5000000`, which is an AMOUNT and not a
 * percentage at all. That row cannot be mapped and must be imported as null
 * and listed for the association to re-ask; see 07-migration-plan.md.
 *
 * `flats_wanted` IS A NUMBER. `01` and `1` are the same request.
 *
 * THE INTRODUCER IS A LINK, exactly as the member's own referee became one
 * (§2B). `p_introducer_name` plus `p_introducer_member_num` is the same problem
 * in the same data, and it gets the same answer: a foreign key when the person
 * is a member, a name when they are not.
 *
 * DISTRICTS ARE NOT A TABLE. See App\Support\Districts for why - the legacy's
 * own `disticts` table has Panchagarh twice and no Rangpur at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_preferences', function (Blueprint $table) {
            $table->id();

            // Cascade: a preference has no meaning apart from the member who
            // expressed it.
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();

            $table->enum('project', ['dhaka_city', 'near_dhaka', 'other_district']);

            /**
             * Where, within that project.
             *
             * Areas of Dhaka for the first two - `Uttara`, `Mohammadpur`,
             * `Basundhara/Purbachal` - and DISTRICTS for the third, validated
             * against App\Support\Districts. One column, two vocabularies,
             * which is what the legacy does too; the difference is that here
             * the rule is written down and enforced.
             */
            $table->json('areas')->nullable();

            // 1,200 to 3,000 in the production data. Small enough for SMALLINT
            // and large enough for anything a cooperative builds.
            $table->unsignedSmallInteger('flat_size_sft')->nullable();

            // The four ranges the form actually offers, in lakh taka.
            $table->enum('budget', ['5-15', '15-25', '25-35', '35+'])->nullable();

            // 0, 25, 50, 75 in the data. `No` is 0 - a member who wants no loan
            // has answered the question, and null means they did not.
            $table->unsignedTinyInteger('loan_percentage')->nullable();

            $table->unsignedTinyInteger('flats_wanted')->nullable();

            /*
             * Who told them about the project. The same pair as the member's
             * referee: the link when they are a member, the name when they are
             * not, and never a copy of a name that can drift from the record.
             */
            $table->foreignId('introduced_by_member_id')
                ->nullable()
                ->constrained('members')
                ->nullOnDelete();
            $table->string('introduced_by_name')->nullable();

            $table->timestamps();

            // One answer per member per project. The legacy has no such
            // constraint, which is why member 215 has three rows with no
            // project on them at all.
            $table->unique(['member_id', 'project']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_preferences');
    }
};
