<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-association configuration (FR-SET-1).
 *
 * This table is why a second association can be onboarded without a code change.
 * The legacy system holds the fine rate in a class constant
 * (FeeFineCheck::MONTHLY_FINE = 100) and the fine ledger in config/accounting.php,
 * both of which are single-tenant assumptions.
 *
 * Seeded to the legacy values so behaviour is preserved at migration (M-9).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->string('group')->default('general')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
