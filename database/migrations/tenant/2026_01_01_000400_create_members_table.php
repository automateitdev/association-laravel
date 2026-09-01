<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Association members (FR-MEM-1, FR-AUTH-8).
 *
 * Members are NOT the `users` table - that holds staff. The legacy system makes
 * the same split (App\Models\Member behind the `web` guard, App\Models\User behind
 * `admin`), and it trips up everyone at least once.
 *
 * Field set carried from the legacy members table, with these corrections:
 *  - email is unique per tenant but nullable; mobile is the primary login for
 *    most members and not everyone has an email
 *  - document paths are nullable, because staff-created members are completed
 *    over time rather than in one sitting
 *  - status is a real enum rather than a free-text string
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('members', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->string('father_name')->nullable();
            $table->string('mother_name')->nullable();
            $table->string('spouse_name')->nullable();

            $table->string('bcs_batch')->nullable();
            $table->unsignedInteger('cadre_id')->nullable();
            $table->date('joining_date')->nullable();
            $table->date('birth_date')->nullable();
            $table->enum('gender', ['male', 'female', 'other'])->nullable();

            // Login credentials. Either may be used (FR-AUTH-2).
            $table->string('mobile', 20)->nullable()->unique();
            $table->string('email')->nullable()->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->rememberToken();

            $table->string('nid', 50)->nullable();

            $table->text('present_address')->nullable();
            $table->text('permanent_address')->nullable();
            $table->text('office_address')->nullable();
            $table->string('emergency_contact', 20)->nullable();

            // Uploaded documents. Stored under a tenant-prefixed non-public path
            // and served only by short-lived signed URL (NFR-SEC-4).
            $table->string('image')->nullable();
            $table->string('nid_front')->nullable();
            $table->string('nid_back')->nullable();
            $table->string('signature')->nullable();
            $table->string('proof_joining_cadre')->nullable();
            $table->string('proof_signed_by_sup_author')->nullable();

            // inactive = awaiting approval; suspended = overdue (FR-MEM-6).
            // Login is refused for both, with DISTINCT error codes (FR-AUTH-3).
            $table->enum('status', ['inactive', 'active', 'suspended'])
                ->default('inactive')
                ->index();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('members');
    }
};
