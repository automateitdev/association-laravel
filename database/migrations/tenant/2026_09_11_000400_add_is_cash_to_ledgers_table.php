<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which ledgers hold actual money, for the cash summary.
 *
 * WHY A FLAG RATHER THAN A GUESS. A cash summary is about the accounts money
 * physically sits in - the till and the bank - and nothing in the schema said
 * which those were. The two candidates were both wrong:
 *
 *   - Match the group NAME. The seeded chart calls it "Cash and Bank", but
 *     associations edit their own chart (A-3), and a report that silently
 *     stops covering the bank because somebody renamed a group to "Cash & Bank"
 *     is worse than one that never worked.
 *   - Take every ASSET ledger. Subscriptions Receivable is an asset and is not
 *     money; a summary including it would answer a different question than the
 *     one its title asks.
 *
 * So the association says, once, per ledger, and can change its mind. Default
 * false: a flag that defaults to true would quietly enrol every new ledger an
 * association creates into a report about cash.
 *
 * The backfill marks the seeded "Cash and Bank" group, which is the chart every
 * association starts from - so an association that has not touched its chart
 * gets a working report without being asked, and one that has is asked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ledgers', function (Blueprint $table) {
            $table->boolean('is_cash')->default(false)->after('is_active')->index();
        });

        /*
         * Best effort, and only where it is unambiguous: an asset group named
         * exactly as the seeder names it. Anything else is the association's
         * own arrangement and is theirs to mark.
         */
        DB::table('ledgers')
            ->whereIn('account_group_id', function ($query) {
                $query->select('account_groups.id')
                    ->from('account_groups')
                    ->join('account_categories', 'account_categories.id', '=', 'account_groups.account_category_id')
                    ->where('account_categories.type', 'asset')
                    ->where('account_groups.name', 'Cash and Bank');
            })
            ->update(['is_cash' => true]);
    }

    public function down(): void
    {
        Schema::table('ledgers', function (Blueprint $table) {
            $table->dropColumn('is_cash');
        });
    }
};
