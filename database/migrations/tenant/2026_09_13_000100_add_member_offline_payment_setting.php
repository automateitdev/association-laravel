<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Members pay online unless the association opens the offline route.
 *
 * WHY THIS MIGRATION EXISTS AT ALL, when `Setting::defaults()` would seed the
 * key on its own: because the default is `false` and seeding only fills in what
 * is MISSING. An association already running would have had the key appear as
 * `false` at its next reseed and its members would have stopped being able to
 * pay - with nothing on screen saying which switch had done it.
 *
 * So an association that predates this switch keeps what it had, and a new one
 * starts closed, which is the policy asked for. The same principle as the fine
 * rate (M-9): a migration must not change what an association DOES on the day
 * it runs.
 *
 * HOW "PREDATES THIS SWITCH" IS DECIDED: whether the settings table already
 * holds anything. Tenant migrations run on database creation, BEFORE
 * TenantProvisioner seeds - so an empty settings table means a database being
 * built right now, and a full one means an association that has been running.
 * That is a property of the provisioning order rather than a guess about dates,
 * and it needs no column of its own.
 */
return new class extends Migration
{
    private const KEY = 'payment.member_offline_enabled';

    public function up(): void
    {
        // Already answered - a reseed got here first. Leave the association's
        // own choice alone; this migration exists to set a starting point, not
        // to overrule one.
        if (DB::table('settings')->where('key', self::KEY)->exists()) {
            return;
        }

        $existingAssociation = DB::table('settings')->exists();

        if (! $existingAssociation) {
            // A database being provisioned. Seeding runs next and writes the
            // `false` from Setting::defaults(); writing it here too would just
            // be the same answer twice.
            return;
        }

        DB::table('settings')->insert([
            'key' => self::KEY,
            // Cast on the way out by the model, which stores settings as JSON.
            'value' => json_encode(true),
            'group' => 'payment',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', self::KEY)->delete();
    }
};
