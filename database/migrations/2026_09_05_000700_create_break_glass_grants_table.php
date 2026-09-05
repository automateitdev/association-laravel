<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Time-boxed operator access into an association's data (FR-SEC-6, NFR-SEC-6).
 *
 * An operator runs the platform. They are not association staff, they hold no
 * role inside an association, and until now the console could tell them an
 * association has 315 members and nothing about who any of them are. That was
 * the correct behaviour in the absence of this table.
 *
 * Support work sometimes genuinely needs a row. The answer is not to widen the
 * console; it is to make each look a DISCRETE, DEFENSIBLE EVENT:
 *
 *   requested with a reason -> approved by a DIFFERENT operator -> the
 *   association's superadmins are told -> access, every page logged -> expires
 *   on the clock, whether or not anybody remembers.
 *
 * Four properties this schema is shaped to guarantee, each of which is a thing
 * that goes wrong when it is left to procedure:
 *
 * ONE ROW IS THE WHOLE GRANT. Request, approval and expiry are columns here,
 * not three tables to join and not a status somebody sets by hand. "Who let
 * this happen and when does it stop" is answerable by looking at one row.
 *
 * EXPIRY IS DATA, NOT A JOB. `expires_at` is checked on every single read. No
 * scheduled sweep decides whether access is still open, because a sweep that
 * fails to run is a grant that never closes, and the failure looks like nothing
 * happening.
 *
 * NOTIFICATION IS A PRECONDITION. `notified_at` is null until the association's
 * superadmins have actually been told, and a grant with a null there grants
 * nothing. Notifying the data owner is the entire control - the residual risk
 * on operator exfiltration is "detectable, not preventable" - so a notification
 * that quietly failed would leave the ceremony and remove the substance.
 *
 * THE READ COUNT IS ON THE GRANT. Not only in the audit log: an approver
 * looking at a finished grant should be able to see that it was used twice, or
 * four hundred times, without going and reading a second table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('break_glass_grants', function (Blueprint $table) {
            $table->id();

            $table->string('tenant_id', 50)->index();

            /*
             * WHAT MAY BE READ, named at request time.
             *
             * A grant is for a question - "did this member's payment
             * complete?" - and naming the area is what stops it becoming
             * standing access to everything. Two areas, because two is what the
             * console can actually show; a longer list here than the code
             * honours would be a promise of precision that is not kept.
             */
            $table->enum('scope', ['members', 'payments'])->index();

            $table->text('reason');

            $table->foreignId('requested_by')->nullable()->constrained('operators')->nullOnDelete();

            // Denormalised alongside the key, as in `operator_audit_logs`: an
            // entry must still say who, even if the account row is later gone.
            $table->string('requested_by_email')->nullable();

            $table->unsignedSmallInteger('duration_minutes');

            $table->enum('status', ['pending', 'approved', 'denied', 'revoked'])
                ->default('pending')
                ->index();

            $table->foreignId('decided_by')->nullable()->constrained('operators')->nullOnDelete();
            $table->string('decided_by_email')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();

            /*
             * The clock starts at APPROVAL, not at request. A grant asked for
             * on Monday and approved on Friday runs from Friday; the alternative
             * hands somebody an hour that has already quietly elapsed, or one
             * that started while nobody was watching.
             */
            $table->timestamp('expires_at')->nullable()->index();

            $table->timestamp('notified_at')->nullable();

            /** Who was told. Kept so "we notified them" can be checked, not asserted. */
            $table->json('notified_to')->nullable();

            /** Why the notification did not go out, when it did not. */
            $table->text('notify_error')->nullable();

            $table->unsignedInteger('reads')->default(0);
            $table->timestamp('last_read_at')->nullable();

            $table->timestamps();

            /*
             * One open request per operator, per association, per area.
             *
             * Not a safety property - it is a legibility one. An approver
             * looking at a queue of nine identical requests from one person
             * cannot tell which is the real one, and approving the wrong
             * duplicate is how a grant ends up outliving the question it was
             * asked for.
             */
            $table->index(['tenant_id', 'scope', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('break_glass_grants');
    }
};
