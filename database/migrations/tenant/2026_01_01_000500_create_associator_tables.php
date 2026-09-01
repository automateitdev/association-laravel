<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Membership records and nominees (FR-MEM-3, FR-MEM-5).
 *
 * `associators_infos` absorbs the legacy `assocs` table, which held overlapping
 * per-member data (membership number, share number, batch, employer). Folding the
 * two is migration transformation M-7.
 *
 * Note for anyone reading the legacy schema: `assocs` is NOT an association
 * registry. There is no association entity in the legacy system at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('associators_infos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();

            // Unique within the association. Across associations it means nothing,
            // which is exactly why members live in the tenant database.
            $table->string('membership_no', 50)->unique();

            $table->date('join_date')->nullable();
            $table->string('share_no', 50)->nullable();

            // Denormalised running total, maintained by ShareService alongside
            // member_share_balances. Recomputable from share history - see the
            // shares:recalculate command (FR-SHR-6).
            $table->unsignedInteger('num_or_shares')->default(0);

            $table->string('bcs_batch')->nullable();
            $table->string('company')->nullable();
            $table->string('designation')->nullable();

            $table->timestamps();

            $table->unique('member_id');
        });

        Schema::create('nominees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('relation')->nullable();
            $table->date('birth_date')->nullable();
            $table->string('nid', 50)->nullable();
            $table->string('mobile', 20)->nullable();
            $table->text('address')->nullable();
            $table->string('image')->nullable();

            // Several nominees may split an entitlement.
            $table->decimal('share_percentage', 5, 2)->nullable();

            $table->timestamps();
        });

        // Member-submitted profile changes, queued for staff approval (FR-MEM-4).
        // A member never writes their own profile directly.
        Schema::create('member_profile_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();

            // The requested changes as field => new value. The current values are
            // read from the member at review time, so the reviewer always compares
            // against what is true now rather than what was true at submission.
            $table->json('changes');

            $table->enum('status', ['pending', 'approved', 'rejected'])
                ->default('pending')
                ->index();

            $table->text('decision_reason')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_profile_updates');
        Schema::dropIfExists('nominees');
        Schema::dropIfExists('associators_infos');
    }
};
