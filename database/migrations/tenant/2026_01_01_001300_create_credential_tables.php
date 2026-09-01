<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-association integration credentials (FR-PAY-11, FR-SMS-6).
 *
 * These live in the TENANT database, encrypted, because each association holds
 * its own merchant account and its own prepaid SMS balance (A-1). The
 * platform stores the credentials; it does not resell the service.
 *
 * This is also the direct fix for defect D-10: the legacy system has live
 * gateway credentials as literals in PaymentController, committed to git
 * history - so rotating them is a code deploy, and anyone who has ever had
 * repository access still holds them.
 *
 * Encryption happens in the model cast, with a key held outside the database.
 * A key stored alongside the data it protects is theatre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gateway_credentials', function (Blueprint $table) {
            $table->id();

            $table->string('provider', 50)->default('gateway');

            // Encrypted JSON: username, password, store id, base url.
            $table->text('credentials');

            /*
             * The shared secret that authenticates the GATEWAY to us - a
             * different thing from the credentials above, which authenticate US
             * to the gateway, and with a different rotation story.
             *
             * Both legacy callback routes are entirely unauthenticated (D-16),
             * so anyone who could guess the URL could mark a payment complete.
             */
            $table->text('webhook_secret')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['provider', 'is_active'], 'uniq_active_provider');
        });

        Schema::create('sms_credentials', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 50)->default('mram');
            $table->text('credentials');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_credentials');
        Schema::dropIfExists('gateway_credentials');
    }
};
