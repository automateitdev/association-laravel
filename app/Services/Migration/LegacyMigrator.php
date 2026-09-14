<?php

declare(strict_types=1);

namespace App\Services\Migration;

use App\Models\Tenant;
use App\Models\Tenant\PaymentInfo;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The legacy association, loaded into a tenant database (FR-MIG-1).
 *
 * A SCRIPT, NOT A SESSION OF SQL. The requirement is explicit about it and the
 * reason is the rehearsal: a migration run twice must produce the same numbers
 * twice, and the only way to know it did is for the second run to be the same
 * artefact as the first. Every table below is emptied and refilled inside one
 * transaction, so a re-run is a replacement rather than an accumulation, and a
 * half-finished run leaves nothing behind to confuse the next one.
 *
 * READS ONE DATABASE, WRITES ANOTHER, AND NEVER THE REVERSE. The legacy
 * connection is opened read-only by convention and never written; the tenant
 * connection is resolved inside `$tenant->run()`, so a bug here can corrupt the
 * association being built and cannot reach across to another one.
 *
 * WHAT IT DOES NOT CARRY
 * ----------------------
 * Passwords. MG-1 settled on a forced reset, and this is where that decision
 * becomes real: no member or staff hash is copied. It is a new system on a new
 * device, a reset is expected, and it removes every question about where a hash
 * came from. `settings.password` is not copied either - the legacy table holds
 * it in PLAIN TEXT, which is a finding in its own right and not something to
 * carry forward into a new database.
 *
 * Gateway credentials. M-10 requires them rotated, and a migration that copies
 * a live secret into a second place makes rotating it harder, not easier.
 *
 * IDS ARE PRESERVED. Invoice numbers embed the member id, payment items point
 * at assignments, and the association's own paperwork cites both. Renumbering
 * would make every historical reference a lie for the sake of tidiness.
 */
class LegacyMigrator
{
    /**
     * Legacy status words that are not the new ones.
     *
     * `suspend` is the whole list, and it matters more than its size suggests:
     * the column is an ENUM here, so the string that was merely unusual there
     * is rejected outright, and 167 payments would fail on insert.
     */
    private const PAYMENT_STATUS = [
        'completed' => 'completed',
        'suspend' => 'suspended',
        'pending' => 'pending',
        'expired' => 'expired',
    ];

    /**
     * `Choose One` is a real value in `payment_type`, eight times.
     *
     * It is the unselected option of a dropdown, saved as though it were an
     * answer - the same disease as the `Choose One` sitting in `ladger_id` that
     * D-20 traces. All eight are `suspend`: refusals where no money moved and
     * no gateway was involved, so `manual` is the honest reading rather than a
     * guess.
     */
    private const PAYMENT_TYPE = [
        'manual' => 'manual',
        'online' => 'online',
        'Choose One' => 'manual',
    ];

    /** Legacy project keys, including the misspelling the data actually uses. */
    private const PROJECTS = [
        'area_of_dhaka_city' => 'dhaka_city',
        'area_close_to_dhaka_city' => 'near_dhaka',
        'other_distict' => 'other_district',
    ];

    private const BUDGETS = [
        'Tk. 5-15 Lac' => '5-15',
        'Tk. 15-25 Lac' => '15-25',
        'Tk. 25-35 Lac' => '25-35',
        'Above Tk. 35 Lac' => '35+',
    ];

    /** Chunk size for the big tables; fine_dates alone is 92,488 rows. */
    private const CHUNK = 1000;

    private ConnectionInterface $legacy;

    private MigrationReport $report;

    /** @var callable(string):void */
    private $progress;

    /**
     * Payments left behind for naming a member who does not exist, so their
     * items can be left behind with them.
     *
     * @var array<int, true>
     */
    private array $skippedPayments = [];

    public function __construct(private readonly string $connection = 'legacy') {}

    /**
     * @param  callable(string):void  $progress
     */
    public function run(Tenant $tenant, callable $progress): MigrationReport
    {
        $this->legacy = DB::connection($this->connection);
        $this->report = new MigrationReport;
        $this->progress = $progress;

        $this->report->before = $this->measureLegacy();

        $tenant->run(function () {
            /*
             * Child first, parent second, in one transaction. A migration that
             * leaves members without their payments is worse than one that
             * fails: the first looks finished.
             */
            /*
             * Constraints off for the load, and only for the load. Every table
             * is emptied and refilled, so for the duration the database passes
             * through states where a child briefly outlives its parent - which
             * is exactly what foreign keys exist to forbid. They are restored
             * before the transaction closes, and the transaction is what makes
             * that safe: if anything throws, none of it was ever visible.
             */
            Schema::disableForeignKeyConstraints();

            DB::transaction(function () {
                $this->accountCategories();
                $this->accountGroups();
                $this->ledgers();
                $this->feeSetups();
                $this->users();
                $this->members();
                $this->associatorInfos();
                $this->nominees();
                $this->memberPreferences();
                $this->feeAssigns();
                $this->fineDates();
                $this->payments();
                $this->paymentItems();
                $this->shareBalances();
                $this->ledgerTraces();
                $this->settings();
            });

            Schema::enableForeignKeyConstraints();

            /*
             * AND THEN CHECK, because re-enabling them proves nothing.
             *
             * MySQL does not revalidate existing rows when FOREIGN_KEY_CHECKS
             * goes back on - it only starts enforcing new writes. The first
             * version of this migrator skipped the legacy's staff users and
             * loaded 4,177 payments whose `created_by` named a user that does
             * not exist. Every constraint was "enabled" the whole time the
             * database was wrong.
             *
             * So the switch is paired with a sweep. Disabling constraints is
             * only safe if something asserts afterwards what they would have.
             */
            $this->verifyReferences();

            $this->report->after = $this->measureTenant();
        });

        return $this->report;
    }

    // ---- reference data ---------------------------------------------------

    /**
     * The five account categories, and the `type` the legacy never recorded.
     *
     * The new schema needs asset/liability/equity/income/expense because the
     * balance sheet and income statement read it; the legacy carried only the
     * NAME, and the accounting meaning lived in whoever was reading the report.
     * Deriving it from the name is safe precisely because there are five rows
     * and they are the five standard ones - anything unrecognised stops the
     * migration rather than guessing at a category that decides which side of
     * a balance sheet a number lands on.
     */
    private function accountCategories(): void
    {
        $rows = [];

        foreach ($this->legacy->table('account_categories')->get() as $row) {
            $type = match (true) {
                str_contains(strtolower($row->name), 'asset') => 'asset',
                str_contains(strtolower($row->name), 'liabilit') => 'liability',
                str_contains(strtolower($row->name), 'equity') => 'equity',
                str_contains(strtolower($row->name), 'income') => 'income',
                str_contains(strtolower($row->name), 'expense') => 'expense',
                default => throw new \DomainException(
                    "Account category {$row->id} is named '{$row->name}', which does not say "
                    .'which side of the balance sheet it belongs on. Map it explicitly.'
                ),
            };

            $rows[] = [
                'id' => $row->id,
                'name' => $row->name,
                'type' => $type,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ];
        }

        $this->load('account_categories', $rows);
    }

    private function accountGroups(): void
    {
        $rows = $this->legacy->table('account_groups')->get()->map(fn ($r) => [
            'id' => $r->id,
            'account_category_id' => $r->acc_category_id,
            'name' => $r->group_name,
            'created_at' => $r->created_at,
            'updated_at' => $r->updated_at,
        ])->all();

        $this->load('account_groups', $rows);
    }

    /**
     * `is_cash` is asked of the data, not carried by it.
     *
     * The new ledger knows whether it is a cash account because the collection
     * screen offers "the cash box or the bank" and has to know which is which.
     * The legacy stored no such flag - a clerk knew "Cash" was cash by reading
     * it - so it is derived from the name here, once, where the rule is visible
     * and can be corrected in one place.
     */
    private function ledgers(): void
    {
        $rows = $this->legacy->table('ledgers')->get()->map(fn ($r) => [
            'id' => $r->id,
            'account_group_id' => $r->acc_group_id,
            'name' => $r->ledger_name,
            'code' => null,
            'opening_balance' => '0.00',
            'is_active' => 1,
            'is_cash' => str_contains(strtolower((string) $r->ledger_name), 'cash') ? 1 : 0,
            'created_at' => $r->created_at,
            'updated_at' => $r->updated_at,
        ])->all();

        $this->load('ledgers', $rows);
    }

    private function feeSetups(): void
    {
        $rows = $this->legacy->table('fee_setups')->get()->map(fn ($r) => [
            'id' => $r->id,
            'fee_head' => $r->fee_head,
            'monthly' => (int) $r->monthly,
            'amount' => $this->money($r->amount),
            'fine_rate' => $this->money($r->fine),
            'is_share' => (int) $r->is_share,
            'ledger_id' => $r->ledger_id,
            'fine_ledger_id' => $r->fine_ledger_id,
            'is_active' => 1,
            'created_at' => $r->created_at,
            'updated_at' => $r->updated_at,
        ])->all();

        $this->load('fee_setups', $rows);
    }

    // ---- people -----------------------------------------------------------

    /**
     * The four staff accounts - without a usable password, and not optional.
     *
     * SKIPPING THEM WAS A BUG, not a decision. `members.created_by`,
     * `payment_infos.created_by` and eight other columns are foreign keys to
     * this table, and an association with no users is one nobody can
     * administer: there is no account to log in with at all.
     *
     * MG-1's forced reset still holds - no legacy hash is copied. `password` is
     * NOT NULL here, so each row gets a value that CANNOT be a bcrypt hash and
     * therefore can never verify: the account exists, owns its history, and is
     * unusable until somebody sets a password through the proper channel.
     */
    private function users(): void
    {
        $rows = $this->legacy->table('users')->orderBy('id')->get()->map(fn ($r) => [
            'id' => $r->id,
            'name' => $r->name,
            'email' => $r->email,
            'email_verified_at' => $r->email_verified_at,

            // Not a hash of anything, and not a hash at all. `Hash::check()`
            // against this is false for every input there is.
            'password' => '!migrated-no-login-'.Str::random(40),

            'remember_token' => null,
            'created_at' => $r->created_at,
            'updated_at' => $r->updated_at,
        ])->all();

        $this->load('users', $rows);

        $this->report->notes[] = sprintf(
            'users: %d staff accounts migrated with NO usable password (MG-1). Each must be '
            .'given one through a password reset before anybody can sign in, and roles are not '
            .'carried - they are assigned per association on the new platform.',
            count($rows),
        );
    }

    /**
     * 315 members, without their passwords (MG-1).
     *
     * `ref_name` / `ref_mobile` / `ref_memeber_id_no` become the introducer
     * pair. The legacy kept a free-text name and a free-text number side by
     * side with no link to the member they name; where that number matches a
     * real membership number the link is made, and where it does not the name
     * survives as text rather than being dropped for being untidy. Somebody
     * wrote that name down for a reason.
     */
    private function members(): void
    {
        $byMembershipNo = $this->legacy->table('associators_infos')
            ->pluck('member_id', 'membershp_number');

        $rows = [];

        foreach ($this->legacy->table('members')->orderBy('id')->get() as $r) {
            $ref = trim((string) $r->ref_memeber_id_no);

            $rows[] = [
                'id' => $r->id,
                'name' => $r->name,
                'father_name' => $r->father_name,
                'mother_name' => $r->mother_name,
                'spouse_name' => $r->spouse_name,
                'bcs_batch' => $r->bcs_batch,
                'cadre_id' => $r->cader_id === null ? null : (int) $r->cader_id,
                'joining_date' => $r->joining_date,
                'birth_date' => $r->birth_date,
                'gender' => $this->gender($r->gender),
                'mobile' => $r->mobile,
                'country_code' => $r->country_code ?: 'BD',
                'email' => $r->email,
                'email_verified_at' => $r->email_verified_at,

                // MG-1: forced reset. Nothing here is a hash of anything.
                'password' => null,
                'remember_token' => null,

                'nid' => $r->nid,
                'present_address' => $r->present_address,
                'permanent_address' => $r->permanent_address,
                'office_address' => $r->office_address,
                'emergency_contact' => $r->emergency_contact,
                'introduced_by_member_id' => $ref === '' ? null : ($byMembershipNo[$ref] ?? null),
                'introduced_by_name' => $r->ref_name,
                'image' => $r->image,
                'nid_front' => $r->nid_front,
                'nid_back' => $r->nid_back,
                'signature' => $r->signature,
                'proof_joining_cadre' => $r->proof_joining_cadre,
                'proof_signed_by_sup_author' => $r->proof_signed_by_sup_author,
                'status' => $this->memberStatus($r->status),
                'created_by' => $r->created_by ?: null,
                'updated_by' => $r->updated_by ?: null,
                'created_at' => $r->created_at,
                'updated_at' => $r->updated_at,
                'deleted_at' => null,
            ];
        }

        $this->load('members', $rows);
    }

    /**
     * `membershp_number` - the misspelling is the legacy column, not a typo
     * here (M-13). `approval_date` becomes `join_date`: the date the
     * association accepted them is what both names were reaching for.
     */
    private function associatorInfos(): void
    {
        $batches = $this->legacy->table('members')->pluck('bcs_batch', 'id');

        $rows = $this->legacy->table('associators_infos')->get()->map(fn ($r) => [
            'id' => $r->id,
            'member_id' => $r->member_id,
            'membership_no' => $r->membershp_number,
            'join_date' => $r->approval_date,
            'share_no' => null,

            // `double` to `int`. Shares are countable things; a fractional one
            // has never meant anything, and M-5 has already confirmed every
            // balance divides exactly.
            'num_or_shares' => (int) round((float) $r->num_or_shares),

            'bcs_batch' => $batches[$r->member_id] ?? null,
            'company' => null,
            'designation' => null,
            'created_at' => $r->created_at,
            'updated_at' => $r->updated_at,
        ])->all();

        $this->load('associators_infos', $rows);
    }

    /**
     * The nominee's own NID scans are dropped here and that is deliberate.
     *
     * `nid_front` and `nid_back` are columns on the legacy nominee; in the new
     * schema a scan is a row in `documents` with a review status, because a
     * member can now replace one and an officer has to approve it. Carrying the
     * path into a column the new system does not read would strand the file
     * somewhere nothing displays it. They come across with the document import,
     * which is a separate pass over the filesystem, not the database.
     */
    private function nominees(): void
    {
        $rows = $this->legacy->table('nominees')->get()->map(fn ($r) => [
            'id' => $r->id,
            'member_id' => $r->member_id,
            'name' => $r->name,
            'relation' => $r->relation_with_user,
            'father_name' => $r->father_name,
            'mother_name' => $r->mother_name,
            'gender' => $this->gender($r->gender),
            'birth_date' => $r->birth_date,
            'nid' => $r->nid,
            'mobile' => $r->mobile,
            'country_code' => $r->country_code ?: 'BD',
            'address' => $r->permanent_address,
            'profession' => $r->professional_details,
            'image' => $r->image,

            // One nominee per member in this data, so the whole benefit is
            // theirs. The column exists because that will not always be true.
            'share_percentage' => '100.00',

            'created_at' => $r->created_at,
            'updated_at' => $r->updated_at,
        ])->all();

        $this->load('nominees', $rows);
    }

    /**
     * 988 legacy rows become however many are actual answers - 18-ish.
     *
     * The legacy wrote THREE ROWS PER MEMBER, one per project type, whether or
     * not the member had said anything: 272 of each, all but a handful entirely
     * null. That is scaffolding the system wrote for itself, and migrating it
     * would hand the new platform 900-odd rows asserting that every member is
     * interested in every project. A row survives only if it carries an answer.
     *
     * This is the count that was wrong in the sweep's first draft - `273
     * members` was really 18 - so it is measured here rather than assumed.
     */
    private function memberPreferences(): void
    {
        $byMembershipNo = $this->legacy->table('associators_infos')
            ->pluck('member_id', 'membershp_number');

        $rows = [];

        foreach ($this->legacy->table('member_choices')->orderBy('id')->get() as $r) {
            $project = self::PROJECTS[$r->project_type] ?? null;

            if ($project === null) {
                continue;
            }

            $areas = $this->areas($r->prefered_area);
            $size = $this->flatSize($r->flat_size);
            $budget = self::BUDGETS[trim((string) $r->capacity_range)] ?? null;
            $loan = $this->loanPercentage($r->exp_bank_loan);
            $flats = $this->flatsWanted($r->num_flat_shares);
            $introducer = trim((string) $r->p_introducer_name);

            $answered = $areas !== [] || $size !== null || $budget !== null
                || $loan !== null || $flats !== null || $introducer !== ''
                || trim((string) $r->p_introducer_member_num) !== '';

            if (! $answered) {
                continue;
            }

            $rows[] = [
                'id' => $r->id,
                'member_id' => $r->member_id,
                'project' => $project,
                'areas' => json_encode($areas),
                'flat_size_sft' => $size,
                'budget' => $budget,
                'loan_percentage' => $loan,
                'flats_wanted' => $flats,
                // The legacy stored the introducer's MEMBERSHIP NUMBER as free
                // text beside their name. Where it names a real member the
                // link is made; where it does not, the name still survives.
                'introduced_by_member_id' => $byMembershipNo[trim((string) $r->p_introducer_member_num)] ?? null,
                'introduced_by_name' => $introducer ?: null,
                'created_at' => $r->created_at,
                'updated_at' => $r->updated_at,
            ];
        }

        $this->report->notes[] = sprintf(
            'member_choices: %d legacy rows carried %d real answers; the rest were '
            .'per-project scaffolding with nothing in them.',
            $this->legacy->table('member_choices')->count(),
            count($rows),
        );

        $this->load('member_preferences', $rows);
    }

    // ---- money ------------------------------------------------------------

    /**
     * M-4's `period`, computed rather than copied.
     *
     * The legacy identified an instalment by `assign_date`, a full date, so
     * "the July subscription" was whatever date somebody happened to assign it
     * on. `period` is the month itself, which is what every screen groups by
     * and what makes a duplicate detectable at all. The audit found zero
     * duplicate groups, so this creates no collisions - but it is the column
     * that would have exposed them.
     */
    private function feeAssigns(): void
    {
        $derived = $this->legacy->table('fee_assigns')->whereNull('fine_date')->count();

        if ($derived > 0) {
            $this->report->notes[] = sprintf(
                'fee_assigns: %d rows carried no fine_date; recovered as assign_date + 20 days, '
                .'which is where their earliest fine accrual falls in every one of them.',
                $derived,
            );
        }

        $this->stream('fee_assigns', 'fee_assigns', fn ($r) => [
            'id' => $r->id,
            'member_id' => $r->member_id,
            'fee_setup_id' => $r->fee_setup_id,
            'period' => $this->period($r->assign_date),
            'assign_date' => $r->assign_date,
            'fine_date' => $r->fine_date ?? $this->derivedFineDate($r->assign_date),
            'amount' => $this->money($r->amount),
            'fine_amount' => $this->money($r->fine_amount),
            'status' => $this->assignStatus($r->status),
            'created_at' => $r->created_at,
            'updated_at' => $r->updated_at,
        ]);
    }

    /**
     * `find_date` becomes `fine_date` (M-13). The legacy column is a
     * misspelling that reached a route name - `/fineDateFix` - and 92,488 rows.
     */
    private function fineDates(): void
    {
        $this->stream('fine_dates', 'fine_dates', fn ($r) => [
            'id' => $r->id,
            'fee_assign_id' => $r->fee_assign_id,
            'fine_date' => $r->find_date === null ? null : substr((string) $r->find_date, 0, 10),
            'status' => in_array($r->status, ['complete', 'incomplete'], true)
                ? $r->status
                : 'incomplete',
            'created_at' => $r->created_at,
            'updated_at' => $r->updated_at,
        ]);
    }

    /**
     * M-8: `ladger_id` was a `text` column holding whatever the form posted,
     * including the string `Choose One`. It becomes a real foreign key, and
     * anything that is not a ledger id becomes null rather than a lie - a
     * payment with no receiving ledger is a known state the approval screen
     * already handles, while a payment pointing at ledger 0 is not.
     *
     * `spg_pay_amount` becomes `gateway_amount`, which is the rename that
     * matters most in this file: it is the column D-1 is about. What the
     * gateway charged stays where the gateway put it and never becomes the
     * member's savings figure.
     */
    private function payments(): void
    {
        $ledgerIds = $this->legacy->table('ledgers')->pluck('id')->all();
        $unmapped = 0;
        $retries = $this->retriedInvoiceNumbers();

        /*
         * TWO IDENTITY SPACES IN ONE COLUMN. `created_by` holds a staff user id
         * on 1,416 payments and a MEMBER id on 2,751 more - the legacy wrote
         * whoever was logged in, and for an online payment that is the member.
         * 9 further values are neither.
         *
         * The new schema means one thing by it: the staff member who took the
         * money, null when the member paid for themselves. That is not a
         * compromise here, it is the same distinction the legacy was reaching
         * for - `PaymentController` already leaves it null so a payment's
         * origin is readable from the record. So anything that is not a real
         * user id becomes null, and the member is not lost: `member_id` names
         * them, as it always did.
         */
        $userIds = $this->legacy->table('users')->pluck('id')->all();
        $notAUser = 0;

        $migratedMembers = $this->legacy->table('members')->pluck('id')->flip();
        $skippedPayments = [];

        $this->streamFiltered('payment_infos', 'payment_infos', function ($r) use ($migratedMembers, &$skippedPayments) {
            /*
             * 13 payments name members 275 and 277, who are not in the members
             * table at all - deleted, with their payments left behind. Every
             * one is `suspend`: refusals, no money. They cannot be loaded
             * against a foreign key and should not be invented a member for.
             */
            if (! isset($migratedMembers[$r->member_id])) {
                $skippedPayments[$r->id] = true;

                return null;
            }

            return true;
        }, function ($r) use ($ledgerIds, $retries, $userIds, &$unmapped, &$notAUser) {
            $createdBy = in_array((int) $r->created_by, $userIds, true) ? (int) $r->created_by : null;

            if ($createdBy === null && $r->created_by) {
                $notAUser++;
            }

            $ledger = is_numeric($r->ladger_id) ? (int) $r->ladger_id : null;

            if ($ledger !== null && ! in_array($ledger, $ledgerIds, true)) {
                $ledger = null;
            }

            if ($ledger === null && trim((string) $r->ladger_id) !== '') {
                $unmapped++;
            }

            return [
                'id' => $r->id,
                // Trimmed: two invoice numbers carry a trailing TAB, and an
                // exact-match lookup on one of those silently finds nothing.
                // The number a person reads is unchanged.
                'invoice_no' => $retries[$r->id] ?? trim((string) $r->invoice_no),
                'member_id' => $r->member_id,
                'ledger_id' => $ledger,
                'payable_amount' => $this->money($r->payable_amount),
                'fine_amount' => $this->money($r->fine_amount),
                'total_amount' => $this->money($r->total_amount),
                'gateway_amount' => $r->spg_pay_amount === null
                    ? null
                    : $this->money($r->spg_pay_amount),
                'status' => $this->paymentStatus($r->status),
                'payment_type' => self::PAYMENT_TYPE[$r->payment_type] ?? 'manual',
                'gateway_reference' => $r->transaction_id,
                'expires_at' => null,
                'payment_date' => $r->payment_date,
                'reason' => $r->reasons,
                'documents' => $this->documents($r->document_files),
                'created_by' => $createdBy,
                'decided_by' => null,
                'decided_at' => null,
                'created_at' => $r->created_at,
                'updated_at' => $r->updated_at,
            ];
        });

        if ($skippedPayments !== []) {
            $this->report->notes[] = sprintf(
                'payment_infos: %d payments named a member who no longer exists and were NOT '
                .'migrated. All are suspended refusals, so no collected money is affected.',
                count($skippedPayments),
            );
        }

        if ($notAUser > 0) {
            $this->report->notes[] = sprintf(
                'payment_infos: %d rows had a created_by that is not a staff user - almost all '
                .'are MEMBER ids, written by the legacy for member-initiated online payments. '
                .'Set to null, which is what the new schema means by it; member_id still names '
                .'the payer.',
                $notAUser,
            );
        }

        $this->skippedPayments = $skippedPayments;

        if ($unmapped > 0) {
            $this->report->notes[] = sprintf(
                'payment_infos: %d rows had a ladger_id that is not a ledger id '
                .'(the "Choose One" placeholder D-20 traces); set to null.',
                $unmapped,
            );
        }
    }

    /**
     * 54 abandoned attempts that share an invoice number with a real payment.
     *
     * `invoice_no` is UNIQUE in the new schema - an invoice number names one
     * payment, which is what an invoice number is for - and the legacy had no
     * such constraint. 51 numbers are used more than once, across 105 rows.
     *
     * THE SHAPE IS THE SAME EVERY TIME, and it is what makes this safe to
     * automate: each of the 51 groups holds EXACTLY ONE `completed` row and one
     * or two `suspend` rows, same member, same amount, seconds apart. They are
     * retries - an attempt that failed and was tried again on the same invoice.
     * Not one group has two completed rows, so no invoice number was ever used
     * to collect money twice; that would be a money problem and this is not.
     *
     * SO NOTHING IS DELETED AND NOTHING MOVES. The completed payment - the one
     * whose number is on the member's receipt and in the ledger - keeps its
     * invoice number untouched. The abandoned attempts take a `-A2` suffix, so
     * they remain in the history, remain countable, and stop colliding. The
     * 86,100 taka they name was never collected: every one of them is
     * `suspend`.
     *
     * @return array<int, string> payment id => the invoice number to store
     */
    private function retriedInvoiceNumbers(): array
    {
        $duplicated = $this->legacy->table('payment_infos')
            ->select('invoice_no')
            ->groupBy('invoice_no')
            ->havingRaw('count(*) > 1')
            ->pluck('invoice_no');

        if ($duplicated->isEmpty()) {
            return [];
        }

        $rows = $this->legacy->table('payment_infos')
            ->whereIn('invoice_no', $duplicated)
            ->orderBy('id')
            ->get(['id', 'invoice_no', 'status']);

        $map = [];
        $seen = [];

        foreach ($rows as $row) {
            // The completed one is the payment; it keeps the number as it is.
            if ($row->status === 'completed') {
                continue;
            }

            $n = ($seen[$row->invoice_no] = ($seen[$row->invoice_no] ?? 1) + 1);
            $map[$row->id] = trim((string) $row->invoice_no).'-A'.$n;
        }

        $this->report->notes[] = sprintf(
            'payment_infos: %d invoice numbers were used more than once; the %d abandoned '
            .'attempts (all suspended, no money) were suffixed -A2/-A3 so the completed '
            .'payment keeps the number on the receipt.',
            $duplicated->count(),
            count($map),
        );

        return $map;
    }

    /**
     * The item's `period` comes from its own `assign_date`, not its
     * assignment's, because that is what the legacy recorded against the money.
     * They agree in this data; taking it from the item keeps the invoice
     * self-describing if they ever stop agreeing.
     */
    private function paymentItems(): void
    {
        $status = $this->legacy->table('payment_infos')->pluck('status', 'id');
        $assignIds = $this->legacy->table('fee_assigns')->pluck('id')->flip();
        $orphans = 0;
        $noAssign = 0;
        $withSkipped = 0;

        $this->streamFiltered(
            'payment_info_items',
            'payment_info_items',
            /*
             * AN ITEM WHOSE PAYMENT DOES NOT EXIST IS NOT MIGRATED, and this is
             * the one place in this file where a row is deliberately left
             * behind.
             *
             * 42 items name a `payment_info_id` with no row behind it - debris
             * from payments deleted out from under them. The new schema has a
             * foreign key, so they cannot be loaded at all; more to the point
             * they should not be. Every one of their 42 assignments is ALSO
             * settled by a real completed payment, so the 42,000 taka and 3,400
             * in fines they name is collected and accounted for on a genuine
             * invoice. Loading them would settle those assignments twice.
             *
             * The migration plan's audit reports `orphaned items | 0`. It is 42.
             */
            function ($r) use ($status, $assignIds, &$orphans, &$noAssign, &$withSkipped) {
                // Two different populations, counted apart because only one of
                // them is the legacy's own debris. Merging them made the report
                // say 118 where the finding is 42.
                if (! isset($status[$r->payment_info_id])) {
                    $orphans++;

                    return null;
                }

                if (isset($this->skippedPayments[$r->payment_info_id])) {
                    $withSkipped++;

                    return null;
                }

                /*
                 * 76 items name an assignment that no longer exists - 27
                 * assignments, deleted with the items left pointing at them.
                 * Every one belongs to a `suspend` payment, so the 74,004 they
                 * name was never collected.
                 */
                if (! isset($assignIds[$r->fee_assign_id])) {
                    $noAssign++;

                    return null;
                }

                return true;
            },
            fn ($r) => [
                'id' => $r->id,
                'payment_info_id' => $r->payment_info_id,
                'fee_assign_id' => $r->fee_assign_id,
                'period' => $this->period($r->assign_date),
                'amount' => $this->money($r->amount),
                'fine_amount' => $this->money($r->fine_amount),

                // The legacy item carried no status of its own; it inherited the
                // invoice's, implicitly, every time anybody read it. No default:
                // an item with no payment behind it is filtered out above rather
                // than being called completed, which is what a `?? 'completed'`
                // here did on the first run - it settled an assignment from an
                // orphan and collided with the real settlement.
                'payment_status' => $this->paymentStatus($status[$r->payment_info_id]),

                /*
             * `settled_fee_assign_id` is NOT set here: it is a STORED GENERATED
             * column - `fee_assign_id` when the item is completed, null
             * otherwise - and MySQL refuses an insert that names it at all.
             *
             * It carries a UNIQUE index, which makes this load a live test of
             * the audit's claim that no assignment was ever paid on two
             * invoices. If that were wrong the insert fails here rather than
             * quietly producing an association that has collected twice.
             */
                'created_at' => $r->created_at,
                'updated_at' => $r->updated_at,
            ]);

        if ($withSkipped > 0) {
            $this->report->notes[] = sprintf(
                'payment_info_items: %d items belonged to the suspended payments skipped above, '
                .'and were left behind with them.',
                $withSkipped,
            );
        }

        if ($noAssign > 0) {
            $this->report->notes[] = sprintf(
                'payment_info_items: %d items named a fee assignment that does not exist and '
                .'were NOT migrated. All belong to suspended payments, so no collected money '
                .'is affected.',
                $noAssign,
            );
        }

        if ($orphans > 0) {
            $this->report->notes[] = sprintf(
                'payment_info_items: %d items named a payment that does not exist and were '
                .'NOT migrated. Every one of their assignments is settled by a real completed '
                .'payment, so no money is lost. (The audit in 07-migration-plan.md reports '
                .'zero orphaned items; it is %d.)',
                $orphans,
                $orphans,
            );
        }
    }

    private function shareBalances(): void
    {
        $rows = $this->legacy->table('member_share_balances')->get()->map(fn ($r) => [
            'id' => $r->id,
            'member_id' => $r->member_id,
            'fee_setup_id' => $r->fee_setup_id,
            'shares' => (int) round((float) $r->shares),
            'created_at' => $r->created_at,
            'updated_at' => $r->updated_at,
        ])->all();

        $this->load('member_share_balances', $rows);
    }

    /**
     * The ledger is the table that changes shape most, because the legacy one
     * described its entries in prose and the new one points at what caused
     * them.
     *
     * `invoice_no` + `type` become `source_type` + `source_id`: a trace now
     * names the payment it came from as a relation rather than by repeating its
     * invoice number in a varchar. Where the invoice cannot be resolved to a
     * payment the trace still loads with a null source - it is real money that
     * really posted, and dropping it to keep the column tidy would unbalance
     * the ledger.
     */
    private function ledgerTraces(): void
    {
        /*
         * COMPLETED WINS WHERE AN INVOICE NUMBER WAS REUSED.
         *
         * 51 numbers name more than one payment (see `retriedInvoiceNumbers`),
         * and a trace posted against one of those numbers belongs to the
         * payment that actually took the money - never to the attempt that was
         * abandoned. A plain `pluck` keeps whichever row it saw last, which
         * silently attached 50 invoices' traces to the suspended twin and left
         * the real payment looking unposted: the audit went from 707 such
         * payments to 757, and the 50 were an artefact of this line.
         */
        $paymentByInvoice = [];

        foreach (
            $this->legacy->table('payment_infos')
                ->orderByRaw("status = 'completed'")
                ->get(['id', 'invoice_no', 'status']) as $row
        ) {
            $paymentByInvoice[trim((string) $row->invoice_no)] = $row->id;
        }

        $orphans = 0;

        $this->stream('ledger_traces', 'ledger_traces', function ($r) use ($paymentByInvoice, &$orphans) {
            $sourceId = $paymentByInvoice[trim((string) $r->invoice_no)] ?? null;

            if ($sourceId === null) {
                $orphans++;
            }

            return [
                'id' => $r->id,
                'ledger_id' => $r->ledger_id,
                'debit' => $this->money($r->debit),
                'credit' => $this->money($r->credit),
                /*
                 * The CLASS NAME, not a friendly word. Nothing here enforces a
                 * morph map, so `LedgerService` stores `PaymentInfo::class` and
                 * every reader matches on it - including the audit's
                 * NO_LEDGER_POSTING check. Writing 'payment' here linked
                 * nothing to anything and reported all 4,010 completed payments
                 * as unposted on the first run.
                 */
                'source_type' => $sourceId === null ? null : PaymentInfo::class,
                'source_id' => $sourceId,
                'reference' => trim((string) $r->invoice_no) ?: $r->reference,
                'posted_on' => $r->voucher_date === null
                    ? null
                    : substr((string) $r->voucher_date, 0, 10),
                'narration' => $r->description,
                'reverses_id' => null,
                'created_at' => $r->created_at,
            ];
        });

        if ($orphans > 0) {
            $this->report->notes[] = sprintf(
                'ledger_traces: %d rows name an invoice with no matching payment; '
                .'loaded with a null source rather than dropped.',
                $orphans,
            );
        }

        $this->report->notes[] = 'ledger_traces: 2 completed payments carried a trailing TAB in '
            .'their invoice number, so the legacy could not match their traces by string and they '
            .'read as unposted. They have 2 and 16 traces, 1,000 and 6,200 in debits. Trimming '
            .'reunites them: 707 apparently-unposted payments become 705, and that difference is '
            .'repair, not loss.';
    }

    /**
     * M-9. The association's own details carry over; its behaviour is stated
     * explicitly for the first time.
     *
     * The legacy had no fine rate or suspension threshold in `settings` - both
     * were constants in the code, so the association could not see them and
     * could not change them. They are written here at the values the legacy
     * actually behaved at, which preserves behaviour rather than choosing it.
     *
     * `settings.password` is NOT carried. It sits in that table in plain text.
     */
    private function settings(): void
    {
        $legacy = $this->legacy->table('settings')->pluck('setting_value', 'setting_key');

        $values = [
            'society.name' => $legacy['name'] ?? null,
            'society.address' => $legacy['address'] ?? null,
            'society.mobile' => $legacy['mobile'] ?? null,
            'society.email' => $legacy['email'] ?? null,
            'society.tax_no' => $legacy['tax'] ?? null,
            'fine.monthly_rate' => '100',
            'fine.suspend_after_instalments' => '3',
            'payment.online_enabled' => '1',
            'payment.member_offline_enabled' => '',
        ];

        $rows = [];
        $now = now();

        foreach ($values as $key => $value) {
            if ($value === null) {
                continue;
            }

            $rows[] = [
                'key' => $key,
                'value' => json_encode($value),
                'group' => explode('.', $key)[0],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $this->load('settings', $rows);

        $this->report->notes[] = 'settings: society details carried; fine rate 100 and '
            .'suspension threshold 3 written explicitly (M-9), which the legacy held in code. '
            .'The legacy `password` setting was NOT carried - it is stored there in plain text.';
    }

    // ---- loading ----------------------------------------------------------

    /**
     * Clear, then insert. Repeatable by construction (FR-MIG-1).
     *
     * `delete` and not `truncate`, for a reason worth keeping: TRUNCATE is DDL,
     * and DDL COMMITS IMPLICITLY in MySQL. Using it would have quietly ended
     * the transaction this whole migration depends on - the first table would
     * commit itself and a failure at the tenth would leave nine loaded. It also
     * cannot run against a table a foreign key points at, which is how the
     * problem announced itself rather than hiding until a failure.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function load(string $table, array $rows): void
    {
        DB::table($table)->delete();

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table($table)->insert($chunk);
        }

        ($this->progress)(sprintf('  %-22s %6d', $table, count($rows)));
        $this->report->loaded[$table] = count($rows);
    }

    /**
     * The same, for tables too large to hold in memory at once.
     *
     * @param  callable(object):array<string, mixed>  $map
     */
    private function stream(string $from, string $to, callable $map): void
    {
        DB::table($to)->delete();

        $count = 0;

        $this->legacy->table($from)->orderBy('id')->chunk(self::CHUNK, function ($rows) use ($to, $map, &$count) {
            $mapped = [];

            foreach ($rows as $row) {
                $mapped[] = $map($row);
            }

            DB::table($to)->insert($mapped);
            $count += count($mapped);
        });

        ($this->progress)(sprintf('  %-22s %6d', $to, $count));
        $this->report->loaded[$to] = $count;
    }

    /**
     * `stream`, for a table where some rows must not come across.
     *
     * Separate from `stream` rather than an optional argument on it, because
     * skipping rows is not a variation on copying them - it is a decision that
     * needs justifying at every call site, and there is exactly one.
     *
     * @param  callable(object):?bool  $keep
     * @param  callable(object):array<string, mixed>  $map
     */
    private function streamFiltered(string $from, string $to, callable $keep, callable $map): void
    {
        DB::table($to)->delete();

        $count = 0;

        $this->legacy->table($from)->orderBy('id')->chunk(self::CHUNK, function ($rows) use ($to, $keep, $map, &$count) {
            $mapped = [];

            foreach ($rows as $row) {
                if ($keep($row) === null) {
                    continue;
                }

                $mapped[] = $map($row);
            }

            if ($mapped !== []) {
                DB::table($to)->insert($mapped);
                $count += count($mapped);
            }
        });

        ($this->progress)(sprintf('  %-22s %6d', $to, $count));
        $this->report->loaded[$to] = $count;
    }

    /**
     * Every foreign key in the tenant, checked against the rows actually there.
     *
     * READ FROM THE SCHEMA, NOT FROM A LIST HERE. A hand-maintained list of
     * relationships to check is a list that goes stale the first time somebody
     * adds a column, and it would have been written by the same person who just
     * forgot a table. `information_schema` already knows every constraint; this
     * asks it, then asks the data whether each one holds.
     *
     * Throws rather than warns. It runs inside the transaction, so a dangling
     * reference rolls the whole migration back and the tenant is left as it
     * was - which is the only safe outcome: a database that looks migrated and
     * has 4,177 payments attributed to a user who does not exist is harder to
     * find and fix later than a migration that refused to finish.
     */
    private function verifyReferences(): void
    {
        $database = DB::connection()->getDatabaseName();

        $constraints = DB::select('
            select
                kcu.table_name            as child_table,
                kcu.column_name           as child_column,
                kcu.referenced_table_name as parent_table,
                kcu.referenced_column_name as parent_column
            from information_schema.key_column_usage kcu
            where kcu.table_schema = ?
              and kcu.referenced_table_name is not null
        ', [$database]);

        $broken = [];

        foreach ($constraints as $fk) {
            /*
             * The parent is ALIASED, and it has to be. `members
             * .introduced_by_member_id` points at `members.id` - a
             * self-reference - and an un-aliased subquery binds both sides to
             * the INNER table, quietly asking which members introduced
             * themselves. It reported 58 imaginary broken rows before this
             * alias existed. The same trap waits on `ledger_traces.reverses_id`.
             */
            $count = DB::table($fk->child_table)
                ->whereNotNull($fk->child_column)
                ->whereNotExists(function ($q) use ($fk) {
                    $q->selectRaw('1')
                        ->from($fk->parent_table.' as parent_ref')
                        ->whereColumn(
                            'parent_ref.'.$fk->parent_column,
                            $fk->child_table.'.'.$fk->child_column,
                        );
                })
                ->count();

            if ($count > 0) {
                $broken[] = sprintf(
                    '%s.%s -> %s.%s (%d rows)',
                    $fk->child_table,
                    $fk->child_column,
                    $fk->parent_table,
                    $fk->parent_column,
                    $count,
                );
            }
        }

        if ($broken !== []) {
            throw new \DomainException(
                'Foreign keys were disabled for the load and these do not hold afterwards: '
                .implode('; ', $broken)
            );
        }

        $this->report->notes[] = sprintf(
            'referential integrity: all %d foreign keys in the tenant verified against the '
            .'loaded rows, after the constraints were re-enabled.',
            count($constraints),
        );
    }

    // ---- reconciliation (FR-MIG-2) ----------------------------------------

    /**
     * @return array<string, string>
     */
    private function measureLegacy(): array
    {
        $money = fn (string $col, string $where = '1=1') => (string) $this->legacy
            ->table('payment_infos')
            ->whereRaw($where)
            ->sum($col);

        return [
            'Members' => (string) $this->legacy->table('members')->count(),
            'Fee assignments' => (string) $this->legacy->table('fee_assigns')->count(),
            'Completed payments' => (string) $this->legacy->table('payment_infos')
                ->where('status', 'completed')->count(),
            'Collected - instalments' => $money('payable_amount', "status='completed'"),
            'Collected - fines' => $money('fine_amount', "status='completed'"),
            'Collected - grand' => $money('total_amount', "status='completed'"),
            'Ledger debits' => (string) $this->legacy->table('ledger_traces')->sum('debit'),
            'Ledger credits' => (string) $this->legacy->table('ledger_traces')->sum('credit'),
            'Share balance total' => (string) $this->legacy->table('member_share_balances')->sum('shares'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function measureTenant(): array
    {
        return [
            'Members' => (string) DB::table('members')->count(),
            'Fee assignments' => (string) DB::table('fee_assigns')->count(),
            'Completed payments' => (string) DB::table('payment_infos')
                ->where('status', 'completed')->count(),
            'Collected - instalments' => (string) DB::table('payment_infos')
                ->where('status', 'completed')->sum('payable_amount'),
            'Collected - fines' => (string) DB::table('payment_infos')
                ->where('status', 'completed')->sum('fine_amount'),
            'Collected - grand' => (string) DB::table('payment_infos')
                ->where('status', 'completed')->sum('total_amount'),
            'Ledger debits' => (string) DB::table('ledger_traces')->sum('debit'),
            'Ledger credits' => (string) DB::table('ledger_traces')->sum('credit'),
            'Share balance total' => (string) DB::table('member_share_balances')->sum('shares'),
        ];
    }

    // ---- value conversion -------------------------------------------------

    /**
     * `DOUBLE` to `DECIMAL(15,2)` (M-6), as a string.
     *
     * Through a string rather than a float because the destination is a decimal
     * column and the whole point of FR-MON-5 is that money stops being a float
     * at the earliest possible moment. This is that moment.
     */
    private function money(mixed $value): string
    {
        return number_format((float) ($value ?? 0), 2, '.', '');
    }

    /**
     * The fine date 225 assignments do not carry, recovered rather than invented.
     *
     * The new column is NOT NULL - every assignment has a date from which a
     * fine applies - and 225 legacy rows leave it empty while still carrying
     * accrued fines: 12 of them are Unpaid and hold 26,200 taka between them.
     * The date clearly existed; only the column was blank.
     *
     * IT IS RECOVERED FROM THE ACCRUALS, NOT GUESSED. `fine_dates` holds the
     * monthly accrual rows, and for ALL 225 of these assignments the earliest
     * accrual falls exactly 20 days after `assign_date` - no exceptions, no
     * spread. The same offset holds for 16,656 of the 16,692 rows that do carry
     * the column (the other 36 are a day short, at 19). So +20 is the
     * association's own rule, read back out of its own records.
     *
     * This creates no fine. `fine_amount` is already on the row and is copied
     * untouched; this only records when fining began, which the child rows
     * already knew.
     */
    private function derivedFineDate(mixed $assignDate): ?string
    {
        if ($assignDate === null) {
            return null;
        }

        return date('Y-m-d', strtotime((string) $assignDate.' +20 days'));
    }

    private function period(mixed $date): ?string
    {
        return $date === null ? null : substr((string) $date, 0, 7);
    }

    private function gender(mixed $value): string
    {
        return match (strtolower(trim((string) $value))) {
            'male', 'm' => 'male',
            'female', 'f' => 'female',
            default => 'other',
        };
    }

    private function memberStatus(mixed $value): string
    {
        return match (strtolower(trim((string) $value))) {
            'active' => 'active',
            'suspended', 'suspend' => 'suspended',
            default => 'inactive',
        };
    }

    private function assignStatus(mixed $value): string
    {
        return match (strtolower(trim((string) $value))) {
            'paid' => 'Paid',
            'requested' => 'Requested',
            default => 'Unpaid',
        };
    }

    private function paymentStatus(mixed $value): string
    {
        return self::PAYMENT_STATUS[trim((string) $value)] ?? 'suspended';
    }

    /**
     * `document_files` is one varchar holding a filename, or nothing. The new
     * column is a JSON list because a payment can carry several slips.
     */
    private function documents(mixed $value): ?string
    {
        $name = trim((string) $value);

        if ($name === '' || $name === 'null') {
            return null;
        }

        return json_encode([[
            'disk' => 'local',
            'path' => $name,
            'original_name' => basename($name),
            'mime' => 'application/octet-stream',
            'size' => 0,
            'uploaded_at' => null,
        ]]);
    }

    /**
     * The area field was taggable, so this is free text holding anything from
     * one name to a comma-separated list.
     *
     * @return array<int, string>
     */
    private function areas(mixed $value): array
    {
        $raw = trim((string) $value);

        if ($raw === '' || $raw === 'null') {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (is_array($decoded)) {
            /*
             * `[{"label":"Uttara","value":"Uttara"}]` - the serialisation of the
             * tag input the legacy form used, not a list of names. Both halves
             * of each pair are the same string today; `value` is taken because
             * that is the half a form submits, and a label is what somebody
             * chose to display.
             */
            $out = [];

            foreach ($decoded as $entry) {
                $name = is_array($entry) ? ($entry['value'] ?? $entry['label'] ?? null) : $entry;

                if (is_scalar($name) && trim((string) $name) !== '') {
                    $out[] = trim((string) $name);
                }
            }

            return array_values(array_unique($out));
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    /** `1,800 sft`, `1800 Sft`, `1500` - all the same number wearing hats. */
    private function flatSize(mixed $value): ?int
    {
        $digits = preg_replace('/[^0-9]/', '', (string) $value);

        return $digits === '' || $digits === null ? null : (int) $digits;
    }

    /**
     * `50%` is a percentage. `5000000` is not - it is a taka amount somebody
     * typed into a field asking for one, and there is no honest way to read it
     * as a proportion, so it becomes null and is counted.
     */
    private function loanPercentage(mixed $value): ?int
    {
        $raw = trim((string) $value);

        if ($raw === '' || strcasecmp($raw, 'no') === 0) {
            return null;
        }

        $digits = (int) preg_replace('/[^0-9]/', '', $raw);

        return $digits >= 1 && $digits <= 100 ? $digits : null;
    }

    private function flatsWanted(mixed $value): ?int
    {
        $digits = (int) preg_replace('/[^0-9]/', '', (string) $value);

        return $digits >= 1 ? $digits : null;
    }
}
