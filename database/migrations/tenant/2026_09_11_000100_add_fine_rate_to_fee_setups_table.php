<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A fee head that does not charge a fine.
 *
 * Until now the fine rate lived only on the association, so every fee head
 * fined at the same rate and none could opt out. An admission fee, a one-off
 * building levy or a voluntary contribution would accrue a monthly penalty
 * exactly like a subscription, and the only way to stop it was to switch fines
 * off for the whole association.
 *
 * NULL MEANS "USE THE ASSOCIATION'S RATE", which is what nearly every head
 * wants and what they all did before this column existed. Zero means this head
 * never fines. The distinction matters: a default of 0 would have silenced
 * fines everywhere on the day this shipped.
 *
 * The legacy has a `fine` column on its fee heads and it is decorative - its
 * nightly job computes `overdueCount * self::MONTHLY_FINE` from a hard-coded
 * constant and never reads the column. So this is a capability the old system
 * appeared to offer and did not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fee_setups', function (Blueprint $table) {
            $table->decimal('fine_rate', 15, 2)->nullable()->after('amount');
        });

        /*
         * The fine ledger becomes optional, because requiring it was asking
         * where fine income should post for a fee that will never produce any.
         * Still required by the API whenever the head can actually fine - the
         * rule belongs there, where the rate is known.
         */
        Schema::table('fee_setups', function (Blueprint $table) {
            $table->unsignedBigInteger('fine_ledger_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('fee_setups', function (Blueprint $table) {
            $table->dropColumn('fine_rate');
        });
    }
};
