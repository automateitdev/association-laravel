<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provisioning and migration history, per tenant (FR-TEN-6, FR-TEN-7, FR-PLT-2).
 *
 * Every provision, migrate, suspend and archive writes a row here. When a run
 * fails halfway, this table is where the operator looks first - see the
 * "Provisioning failed halfway" runbook in bcs-docs/09-environments-and-devops.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_provisioning_runs', function (Blueprint $table) {
            $table->id();

            // Not a foreign key: a failed provision rolls the tenant row back,
            // and we still want the record of why it failed.
            $table->string('tenant_id', 50)->index();

            $table->string('command');
            $table->enum('status', ['running', 'succeeded', 'failed', 'rolled_back'])
                ->default('running')
                ->index();

            $table->longText('output')->nullable();
            $table->text('error')->nullable();

            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_provisioning_runs');
    }
};
