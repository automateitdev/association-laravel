<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Onboarding readiness, carried on the snapshot.
 *
 * The associations list wants to say which ones are not finished being set up —
 * no administrator, no chart of accounts, no gateway. Asking each association
 * directly would mean one connection swap per row on every page load, and with
 * fifty associations that is fifty database connections to render a list.
 *
 * So it rides along with the figures that are already collected once a day and
 * read centrally (FR-PLT-5). The list is therefore as fresh as the last
 * collection, which the page already states rather than leaves to be assumed;
 * an association's own page still computes it live, because that is where
 * somebody is acting on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_snapshots', function (Blueprint $table) {
            /*
             * The count, not a boolean. "Two things are stopping this
             * association working" and "one thing is" are different amounts of
             * trouble, and a flag would flatten them.
             */
            $table->unsignedSmallInteger('blocking_issues')->default(0)->after('error');

            /** The whole checklist as it stood, so the list can name the gap. */
            $table->json('readiness')->nullable()->after('blocking_issues');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_snapshots', function (Blueprint $table) {
            $table->dropColumn(['blocking_issues', 'readiness']);
        });
    }
};
