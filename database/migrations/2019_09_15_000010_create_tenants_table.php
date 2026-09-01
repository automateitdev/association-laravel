<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tenant registry. One row per association.
 *
 * This table lives in the CENTRAL database and holds no member, payment or ledger
 * data - that separation is the point of ADR-0001. Anything association-specific
 * beyond identity and provisioning belongs in the tenant's own `settings` table.
 *
 * The primary key is the slug (e.g. "cocsol"), so the tenant database is named
 * tenant<slug> and the slug is immutable by construction (FR-TEN-5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            // The slug. Immutable: it is baked into the database name, storage
            // paths, tokens and DNS.
            $table->string('id', 50)->primary();

            $table->string('name');
            $table->string('legal_name')->nullable();

            $table->enum('status', [
                'provisioning',
                'active',
                'suspended',
                'archived',
                'failed',
            ])->default('provisioning')->index();

            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();

            $table->string('locale', 5)->default('en');
            $table->string('timezone', 64)->default('Asia/Dhaka');
            $table->string('currency', 3)->default('BDT');

            $table->timestamp('onboarded_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('archived_at')->nullable();

            $table->timestamps();

            // stancl/tenancy's VirtualColumn store. Holds the internal
            // tenancy_db_name / tenancy_db_username / tenancy_db_password keys.
            //
            // TODO(P1-hardening): db_password is stored in plaintext JSON here.
            // NFR-SEC-2 requires it encrypted at rest with a key held outside the
            // database. Address before the first real tenant is provisioned.
            $table->json('data')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
