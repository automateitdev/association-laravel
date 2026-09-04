<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant figures, gathered one tenant at a time (FR-PLT-5).
 *
 * WHY A TABLE AND NOT A QUERY
 * ---------------------------
 * "How many members does the platform have" looks like one `SUM` across every
 * association's database. FR-PLT-5 forbids exactly that, and not for speed: a
 * query that spans tenant databases is one mistake away from showing one
 * association another's numbers, and the whole point of a database per tenant
 * (ADR-0001) is that such a mistake should be impossible to make by accident.
 *
 * So each tenant is visited on its own, its figures written here, and the
 * platform total is a sum of THIS central table. The isolation boundary is
 * never crossed by a query - only by a job that steps into one tenant at a time
 * and steps back out.
 *
 * The cost is that figures are as fresh as the last run, which is why
 * `collected_at` is on every row and shown wherever the totals are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 50)->index();

            $table->unsignedInteger('members')->default(0);
            $table->unsignedInteger('active_members')->default(0);
            $table->unsignedInteger('staff_accounts')->default(0);
            $table->unsignedInteger('completed_payments')->default(0);
            $table->unsignedInteger('pending_payments')->default(0);

            // DECIMAL, like every money column (FR-MON-5). A platform total
            // assembled out of floats would drift the moment it was summed.
            $table->decimal('collected_instalments', 18, 2)->default(0);
            $table->decimal('collected_fines', 18, 2)->default(0);
            $table->decimal('outstanding_instalments', 18, 2)->default(0);
            $table->decimal('outstanding_fines', 18, 2)->default(0);

            $table->decimal('database_size_mb', 12, 2)->default(0);
            $table->timestamp('last_fine_accrual')->nullable();

            /** Null when the tenant answered; the error when it did not. */
            $table->text('error')->nullable();

            $table->timestamp('collected_at')->index();
            $table->timestamps();

            // One snapshot per tenant per run; history is kept so a figure can
            // be explained by comparing it with last week's.
            $table->index(['tenant_id', 'collected_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_snapshots');
    }
};
