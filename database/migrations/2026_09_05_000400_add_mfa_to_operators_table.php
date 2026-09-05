<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Second factor for platform operators (NFR-SEC-5).
 *
 * The console shipped password-only, which was stated on its login page and in
 * three documents but remained the largest hole in the security model: an
 * operator can suspend an association and, through `tenant:gateway`, set the
 * account members' payments land in.
 *
 * ENCRYPTED, NOT HASHED. A TOTP secret has to be recoverable to verify a code,
 * unlike a password - so it is encrypted at rest with the app key. That means a
 * database dump alone does not yield working second factors, but a dump plus
 * the app key does. The key living outside the database is what makes this
 * meaningful (see 06-security-and-tenancy.md); encryption at rest with the key
 * in the same place is theatre.
 *
 * `mfa_last_used_step` is the replay guard. A TOTP code is valid for a whole
 * 30-second window, so without recording the step it was used at, a code
 * observed over somebody's shoulder - or replayed from a logged request - works
 * again until the window closes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operators', function (Blueprint $table) {
            $table->text('mfa_secret')->nullable()->after('password');

            /*
             * Enrolment is only complete once a code has been verified. A
             * secret that was generated but never proved would lock somebody
             * out of an account they believe is configured.
             */
            $table->timestamp('mfa_confirmed_at')->nullable()->after('mfa_secret');

            /** Hashed, single-use. Bcrypt, like a password - these ARE passwords. */
            $table->text('mfa_recovery_codes')->nullable()->after('mfa_confirmed_at');

            $table->unsignedBigInteger('mfa_last_used_step')->nullable()->after('mfa_recovery_codes');
        });
    }

    public function down(): void
    {
        Schema::table('operators', function (Blueprint $table) {
            $table->dropColumn([
                'mfa_secret',
                'mfa_confirmed_at',
                'mfa_recovery_codes',
                'mfa_last_used_step',
            ]);
        });
    }
};
