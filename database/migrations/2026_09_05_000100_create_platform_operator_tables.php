<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform operators and their audit trail (FR-PLT-4).
 *
 * CENTRAL, not tenant. These tables live in the registry database beside
 * `tenants` — an operator is not a member of any association, and giving them a
 * row inside one would make the account visible to the association's own
 * administrators.
 *
 * WHY THE AUDIT LOG MATTERS MORE THAN THE ACCOUNTS
 * -----------------------------------------------
 * An operator can create an association, suspend one, and set where an
 * association's payment gateway sends its money. Until now the only trace of
 * any of that was shell history: `tenant:provision` and `tenant:gateway` are
 * run from a console, and a console remembers nothing anybody can be shown.
 *
 * FR-PLT-4 requires every operator action to be recorded. This is the table it
 * is recorded in, and the commands write to it as well as the console does.
 *
 * The log is APPEND-ONLY by intent: there is no update path in the model and no
 * screen that edits one. A tamper-proof log needs more than intent - signing,
 * or shipping off-box - and that is noted as not done rather than implied.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operators', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');

            /*
             * An operator who has left keeps their row, because their audit
             * entries have to keep resolving to a name. Disabled, never
             * deleted.
             */
            $table->boolean('is_active')->default(true)->index();

            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('operator_audit_logs', function (Blueprint $table) {
            $table->id();

            /*
             * Nullable, and deliberately not a cascade: an operator row must
             * never be deletable in a way that erases what they did. If one is
             * somehow removed, the entry survives with a null actor and the
             * recorded email below still says who it was.
             */
            $table->foreignId('operator_id')->nullable()->constrained()->nullOnDelete();

            // Denormalised on purpose - see above.
            $table->string('operator_email')->nullable();

            $table->string('action', 100)->index();

            /** Which association it was done to, where that applies. */
            $table->string('tenant_id', 50)->nullable()->index();

            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->text('reason')->nullable();

            /**
             * `console` or `web`. An action taken at the server console and the
             * same action taken in the browser are different facts about how
             * somebody had access, and the difference matters in an incident.
             */
            $table->string('source', 20)->default('web');

            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operator_audit_logs');
        Schema::dropIfExists('operators');
    }
};
