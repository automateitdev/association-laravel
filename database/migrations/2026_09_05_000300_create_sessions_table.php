<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sessions, in the CENTRAL database.
 *
 * WHY THIS DID NOT EXIST UNTIL NOW
 * -------------------------------
 * The API is stateless - Sanctum tokens, no session - so nothing in this
 * application had ever needed one. Laravel's stock `sessions` table shipped
 * inside the tenant migrations (alongside `users`), which is where the app's
 * own auth lives, and the central database simply never got one.
 *
 * The platform console changed that. It is server-rendered and session-based,
 * on the central connection, so it needs somewhere to keep a session that
 * outlives a request.
 *
 * The symptom of it missing was not an error. `SESSION_DRIVER` was `array`,
 * which keeps a session in memory for exactly one request and then discards it,
 * so signing in succeeded, the redirect fired, and the next request arrived
 * with nothing - bouncing the operator back to the login page having genuinely
 * authenticated. The audit log recorded two `operator.signed_in` entries nine
 * seconds apart, which is what a person does when a login silently does
 * nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sessions')) {
            return;
        }

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();

            /*
             * No foreign key to `users`.
             *
             * The central database has no `users` table - association staff
             * live in tenant databases and operators are in `operators`. The
             * stock migration constrains this column; here it is a plain index,
             * because the id it holds belongs to whichever guard wrote it.
             */
            $table->foreignId('user_id')->nullable()->index();

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};
