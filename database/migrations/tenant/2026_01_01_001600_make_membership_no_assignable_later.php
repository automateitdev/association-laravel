<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A membership number is assigned by the office, after the member exists.
 *
 * WHY THIS CHANGES
 * ----------------
 * `membership_no` was created NOT NULL, which assumes the number is known when
 * the member record is made. The legacy system - and therefore the association's
 * actual working practice - does the opposite, and the code is unambiguous
 * about it:
 *
 *   - the admin member-registration form has NO membership number field at all;
 *   - `MemberAuthController::forAdminRegister()` creates the associator row with
 *     nothing but `member_id` and a timestamp;
 *   - the number is typed later on a separate screen whose own label reads
 *     "COCSOL Membership Number (Office use only)", next to "Date of approval".
 *
 * So a member legitimately exists, with a record, before anyone knows their
 * number. Requiring it at creation would force staff to invent one at the
 * counter - which is how duplicates and placeholder numbers get into a register
 * that is supposed to be authoritative.
 *
 * THE UNIQUE INDEX STAYS.
 * FR-MEM-3 requires uniqueness within the association, and MySQL permits any
 * number of NULLs in a unique index - so "not yet assigned" stays repeatable
 * while assigned numbers stay unique. That is exactly the constraint wanted, and
 * it is more than the legacy schema had: there, `membershp_number` was a plain
 * nullable string with no index, and uniqueness held only because staff were
 * careful. In 315 production rows they were - 315 distinct values - but nothing
 * enforced it.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Raw SQL rather than ->change(): doctrine/dbal is not installed, and a
        // MODIFY preserves the unique index without dropping and recreating it.
        DB::statement('ALTER TABLE associators_infos MODIFY membership_no VARCHAR(50) NULL');
    }

    public function down(): void
    {
        // Anything unassigned has to go before the column can be NOT NULL again,
        // and deleting member records to satisfy a rollback would be worse than
        // failing loudly here.
        $unassigned = DB::table('associators_infos')->whereNull('membership_no')->count();

        if ($unassigned > 0) {
            throw new RuntimeException(
                "Cannot restore NOT NULL: {$unassigned} member(s) have no membership number yet. "
                .'Assign them first, or remove those records deliberately.'
            );
        }

        DB::statement('ALTER TABLE associators_infos MODIFY membership_no VARCHAR(50) NOT NULL');
    }
};
