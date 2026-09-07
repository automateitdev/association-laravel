<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An association uses ONE gateway: SPG directly, or SPG through PayFlex.
 *
 * WHY THE DATABASE AND NOT JUST THE SERVICE
 * -----------------------------------------
 * `GatewayConfigurator::store()` already switches every other provider off when
 * it activates one, and that is where the rule is explained. But it was the
 * only thing holding the rule up: `enable()` set a row active without touching
 * the others, the console form posts to both endpoints, `tenant:gateway` is a
 * second caller, and a support fix applied with an UPDATE at a mysql prompt
 * answers to nothing at all.
 *
 * What two active rows cost is not an error message - it is
 * `GatewayRegistry::activeProvider()` returning whichever row the storage engine
 * hands back first. The association keeps taking payments, through the provider
 * it thought it had left, into whichever AR account that row carries. Nothing
 * anywhere reports a problem; the money simply arrives somewhere else.
 *
 * A rule about money that only holds while every caller remembers it is not a
 * rule, so the table enforces it: a generated column that is 1 on an active row
 * and NULL on every other, under a unique index. MySQL permits any number of
 * NULLs there, so "switched off" stays repeatable while "active" cannot happen
 * twice. The same shape as the payment-items index that enforces I-1.
 *
 * THE EXISTING `uniq_active_provider` IS NOT THIS. It is unique over
 * (provider, is_active), which forbids two identical spg rows and permits
 * exactly the thing this forbids: spg active beside payflex_spg active. It is
 * left alone - it is still the constraint that keeps one row per provider.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Any association that already has two active rows is resolved before
         * the index goes on, or the migration fails on the tenant that most
         * needs it. The most recently touched row wins, because that is the one
         * whoever configured it last meant to be using.
         *
         * Deactivated, never deleted: the credentials stay, so moving back is a
         * switch rather than eight fields typed again from a password manager.
         */
        $keep = DB::table('gateway_credentials')
            ->where('is_active', true)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->value('id');

        if ($keep !== null) {
            DB::table('gateway_credentials')
                ->where('is_active', true)
                ->where('id', '!=', $keep)
                ->update(['is_active' => false]);
        }

        // Raw SQL: a generated column is not expressible through the Blueprint
        // without doctrine/dbal, which is not installed.
        DB::statement(
            'ALTER TABLE gateway_credentials '
            .'ADD COLUMN active_lock TINYINT UNSIGNED '
            .'GENERATED ALWAYS AS (CASE WHEN is_active = 1 THEN 1 ELSE NULL END) VIRTUAL, '
            .'ADD UNIQUE KEY uniq_one_active_gateway (active_lock)'
        );
    }

    public function down(): void
    {
        if (! Schema::hasColumn('gateway_credentials', 'active_lock')) {
            return;
        }

        DB::statement(
            'ALTER TABLE gateway_credentials '
            .'DROP INDEX uniq_one_active_gateway, '
            .'DROP COLUMN active_lock'
        );
    }
};
