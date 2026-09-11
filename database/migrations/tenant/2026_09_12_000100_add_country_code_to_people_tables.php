<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which country a mobile number belongs to (legacy `country_code`).
 *
 * THE SWEEP HAD THIS WRONG, AND THE CORRECTION IS THE REASON IT IS HERE.
 * bcs-docs/12-legacy-sweep.md §2B listed it as "one distinct value in
 * production today, so it costs nothing now and is a schema change later".
 * That is true of members - 313 `BD` and 2 null - and false of nominees:
 * **8 of them are `US`**. A member's nominee living abroad is an ordinary case
 * in a cooperative of civil servants, and dropping the column on import would
 * have silently turned eight foreign numbers into Bangladeshi ones.
 *
 * ISO 3166-1 ALPHA-2, which is what the legacy stores - `BD`, `US`. Not a
 * dialling code: `+1` is six countries, so a number stored against `+1` cannot
 * be formatted, validated or explained afterwards. The dialling code is derived
 * from this when something needs one.
 *
 * NOT NULLABLE, defaulted to BD. Every row in production has a country in
 * practice - the two nulls are rows whose mobile is also empty - and a nullable
 * column here would mean every reader has to decide what a missing country
 * means. It means Bangladesh; say so once, here.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['members', 'nominees'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->char('country_code', 2)->default('BD')->after('mobile');
            });
        }

        /*
         * Existing rows take the default, which is what `->default()` already
         * does for them. Stated rather than assumed, because the interesting
         * case is the import: the legacy's own values come across as they are,
         * and the eight US nominees must NOT be flattened to the default by an
         * importer that only writes the columns it recognises.
         */
        DB::statement("UPDATE members SET country_code = 'BD' WHERE country_code IS NULL OR country_code = ''");
        DB::statement("UPDATE nominees SET country_code = 'BD' WHERE country_code IS NULL OR country_code = ''");
    }

    public function down(): void
    {
        foreach (['members', 'nominees'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('country_code');
            });
        }
    }
};
