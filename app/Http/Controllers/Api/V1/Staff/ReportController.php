<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Controllers\Controller;
use App\Models\Tenant\FeeAssign;
use App\Models\Tenant\LedgerTrace;
use App\Models\Tenant\Member;
use App\Models\Tenant\PaymentInfo;
use App\Models\Tenant\PaymentInfoItem;
use App\Models\Tenant\Voucher;
use App\Reports\Column;
use App\Reports\ExportsListings;
use App\Reports\Report;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reports (FR-REP-1 … FR-REP-5, FR-REP-7, FR-REP-8).
 *
 * Two rules govern every figure here, and they are the reason the legacy
 * reports are wrong:
 *
 *   FR-REP-3  instalment, fine and total are SEPARATE columns. No column
 *             labelled "savings" or "paid" may silently include a fine.
 *   FR-REP-4  an instalment count is a count of DISTINCT assignments, never a
 *             row count. A duplicated row, a fine-only row and an orphan row
 *             each add one to the legacy count (defect D-7).
 *
 * ONE QUERY PER REPORT, FOUR OUTPUTS.
 * The JSON the screen reads and the CSV, xlsx and PDF a person downloads are
 * all built from the same `…Rows()` method. FR-REP-8 asks for totals on screen
 * AND in every export, which is only worth anything if they agree - and the way
 * to guarantee that is to leave no second implementation that could drift.
 */
class ReportController extends Controller
{
    use ExportsListings;

    // ---------------------------------------------------------------- screens

    /**
     * Memberwise paid: one row per member, cumulative.
     *
     * The legacy version of this report is the one that shows inflated
     * "savings", because it sums payable_amount - which on online payments
     * includes the fine (defect D-1).
     */
    public function memberwisePaid(Request $request): JsonResponse
    {
        $filters = $this->memberwiseFilters($request);
        $result = $this->memberwisePaidRows($filters);

        return response()->json([
            'data' => $result['rows'],
            'meta' => ['members' => count($result['rows'])] + $result['totals'],
        ]);
    }

    /**
     * Outstanding dues (FR-REP-5), for active or suspended members.
     */
    public function dueInfo(Request $request): JsonResponse
    {
        $filters = $this->dueFilters($request);
        $result = $this->dueInfoRows($filters);

        return response()->json([
            'data' => $result['rows'],
            'meta' => [
                'from' => $filters['from'],
                'as_of' => $filters['as_of'],
                'members' => count($result['rows']),
            ] + $result['totals'],
        ]);
    }

    /**
     * Income statement (P-9): what the association earned and spent in a period.
     *
     * SIGNED, AND THAT IS THE WHOLE DESIGN DECISION HERE.
     *
     * The legacy report sums CREDITS for income ledgers and DEBITS for expense
     * ones and ignores the other side of each. That is fine until something is
     * reversed - and reversal is the only correction this system allows
     * (FR-ACC-9). A reversed receipt posts a debit to the income ledger, the
     * legacy sum never looks at debits, and the income it reports stays as
     * though the money were still there.
     *
     * So each ledger contributes `credit - debit` if it is income and
     * `debit - credit` if it is expense, which is what those balances mean, and
     * a reversal cancels itself out the way it should. A refund on an expense
     * account behaves the same way.
     *
     * WHAT IS DELIBERATELY NOT IN IT:
     *
     *   - `opening_balance`. A statement covers a PERIOD; an opening balance is
     *     a position at a moment. Adding it would restate last year's result
     *     into this year's every time the report is run.
     *   - Draft vouchers. `ledger_traces` only exist once a voucher is approved
     *     (see VoucherService::approve), so this needs no status filter - the
     *     ledger is the definition of what has happened.
     *
     * ONE ROW PER LEDGER, grouped in the database rather than in PHP. An
     * association's chart runs to dozens of accounts and its traces to
     * hundreds of thousands; the aggregate belongs where the index is.
     */
    public function incomeStatement(Request $request): JsonResponse
    {
        $filters = $this->statementFilters($request);
        $result = $this->incomeStatementRows($filters);

        return response()->json([
            'data' => $result['rows'],
            'meta' => [
                'from' => $filters['from'],
                'to' => $filters['to'],
                'accounts' => count($result['rows']),
            ] + $result['totals'],
        ]);
    }

    /**
     * Trial balance: every account's balance, and whether the books balance.
     *
     * WHAT IT IS FOR, stated because it is the least self-explanatory of the
     * three: a trial balance proves that every entry was made on both sides. If
     * the debit column and the credit column do not agree, something is wrong
     * with the books themselves rather than with any one account, and nothing
     * computed from them can be trusted until it is found.
     *
     * SO IT SAYS SO. `balanced` is the answer this report exists to give, and
     * `difference` is what to go looking for. A trial balance that quietly
     * printed two unequal totals and left the reader to subtract them would be
     * a worksheet, not a check.
     *
     * In this system the entries cannot be unbalanced - a voucher is refused
     * unless it balances, and every payment posts a pair (FR-ACC-6) - so a
     * difference here means an OPENING BALANCE is wrong, which is the one
     * figure staff type in by hand.
     *
     * Accounts with nothing in them are left out: a chart of accounts is a
     * list of what an association might use, and a trial balance is a statement
     * of what it did.
     */
    public function trialBalance(Request $request): JsonResponse
    {
        $asOf = $this->asOfFilter($request);
        $result = $this->trialBalanceRows($asOf);

        return response()->json([
            'data' => $result['rows'],
            'meta' => ['as_of' => $asOf, 'accounts' => count($result['rows'])] + $result['totals'],
        ]);
    }

    /**
     * Balance sheet: what the association owns, owes, and is worth.
     *
     * THE SURPLUS IS PART OF IT, and that is the thing most easily got wrong.
     * Income and expense accounts are not on a balance sheet, but the result
     * they produce is: everything the association has earned less everything it
     * has spent, since the beginning, belongs to the members and sits in equity.
     * Leave it out and the statement does not balance, by exactly that amount.
     *
     * It is shown as its own line - "Accumulated surplus" - rather than folded
     * into Share Capital, because it is not share capital: nobody subscribed
     * for it. An association reading its own balance sheet should be able to
     * see how much of what it is worth was paid in and how much was earned.
     *
     * AS AT A DATE, not over a period. Everything up to `as_of`, opening
     * balances included - which is the opposite of the income statement, and
     * the reason that report refuses them.
     */
    public function balanceSheet(Request $request): JsonResponse
    {
        $asOf = $this->asOfFilter($request);
        $result = $this->balanceSheetRows($asOf);

        return response()->json([
            'data' => $result['rows'],
            'meta' => ['as_of' => $asOf] + $result['totals'],
        ]);
    }

    /**
     * Cash summary: what was in the till and the bank, what moved, what is left.
     *
     * ONE ROW PER CASH ACCOUNT, with opening, in, out and closing. The question
     * it answers is the one an association asks before a committee meeting -
     * "what have we actually got?" - and the columns are in the order somebody
     * says that out loud.
     *
     * WHICH ACCOUNTS ARE CASH is the association's own answer, from `is_cash`
     * on the ledger, not a guess from a group name that anybody may rename. See
     * the migration that added it. When nothing is marked the report says so
     * and says where to fix it, rather than returning an empty table that looks
     * like a quiet period.
     *
     * Closing is computed from opening and movement rather than read back, so
     * the four columns on a row are arithmetic the reader can check by eye.
     */
    public function cashSummary(Request $request): JsonResponse
    {
        $filters = $this->statementFilters($request);
        $result = $this->cashSummaryRows($filters);

        return response()->json([
            'data' => $result['rows'],
            'meta' => [
                'from' => $filters['from'],
                'to' => $filters['to'],
                'accounts' => count($result['rows']),
            ] + $result['totals'],
        ]);
    }

    /**
     * Voucher-wise: the ledger read document by document.
     *
     * THE OTHER FOUR STATEMENTS AGGREGATE; this one lists. A trial balance says
     * an account holds 4,300 and a cash summary says 4,300 came in, but neither
     * can say WHICH documents made it up - and "which" is the question somebody
     * asks the moment a figure looks wrong.
     *
     * A DOCUMENT, NOT A TRACE. Every trace records what produced it, so that
     * source is the grouping key: one row per payment and per approved voucher,
     * each with its own total. The legacy report grouped by nothing at all - it
     * selected the trace id and then called `distinct()`, which can never
     * collapse anything - so it listed one row per LINE while showing each of
     * them the whole document's total. Against COCSOL's data that is 15,720
     * rows standing for 3,378 documents, and the largest is listed 84 times.
     *
     * AND IT SHOWS THE AMOUNT, which the legacy computed in two correlated
     * subqueries per row and then left out of the table entirely.
     *
     * PAGINATED, unlike the four statements. Those return every row because a
     * statement that cannot be totalled is not a statement; this is a listing
     * of documents, three years of which runs to tens of thousands, and its
     * totals are taken over the whole range rather than over the page.
     */
    public function voucherwise(Request $request): JsonResponse
    {
        $filters = $this->voucherwiseFilters($request);

        $result = $this->voucherwiseRows(
            $filters,
            min((int) $request->query('per_page', 25), 100),
        );

        return response()->json([
            'data' => $result['rows'],
            'meta' => [
                'from' => $filters['from'],
                'to' => $filters['to'],
            ] + $result['totals'] + $result['page'],
        ]);
    }

    /**
     * One document, line by line - what the legacy's single-voucher page was.
     *
     * ADDRESSED BY A TRACE, as the legacy route is, and for a better reason
     * than imitation: the listing row a reader clicked is a GROUP, and that
     * group's key is a class name and an id. A class name is an internal detail
     * and must not become something a client sends back in a URL.
     *
     * IT SAYS WHAT THE LEGACY PAGE DID NOT. That page showed ledger, debit,
     * credit and two totals, and nothing whatever about the document they
     * belong to - not its date, its number, its kind, nor whether it had since
     * been reversed. A page of figures with no heading cannot be filed, checked
     * or disputed afterwards.
     */
    public function voucherwiseDocument(int $trace): JsonResponse
    {
        $anchor = LedgerTrace::findOrFail($trace);

        $lines = LedgerTrace::query()
            ->with(['ledger:id,name,account_group_id', 'ledger.accountGroup:id,name'])
            ->when(
                $anchor->source_type === null,
                /*
                 * An unattributed trace is its own document. `= NULL` matches
                 * nothing, and `whereNull` on its own would sweep in every
                 * other orphan in the books; claiming a grouping we cannot
                 * prove is worse than showing one line and saying so.
                 */
                fn ($q) => $q->whereNull('source_type')->where('id', $anchor->id),
                fn ($q) => $q->where('source_type', $anchor->source_type)
                    ->where('source_id', $anchor->source_id),
            )
            ->orderBy('id')
            ->get();

        $debit = $lines->reduce(fn ($carry, $line) => bcadd($carry, (string) $line->debit, 2), '0.00');
        $credit = $lines->reduce(fn ($carry, $line) => bcadd($carry, (string) $line->credit, 2), '0.00');

        $named = $this->nameDocuments(collect([(object) [
            'source_type' => $anchor->source_type,
            'source_id' => $anchor->source_id,
        ]]));

        $name = $named[$anchor->source_type.'|'.$anchor->source_id];

        return response()->json([
            'data' => [
                'kind' => $this->documentKind($anchor->source_type),
                'kind_label' => $this->documentKindLabel($anchor->source_type),
                'number' => (string) ($anchor->reference ?? ''),
                'posted_on' => $anchor->posted_on->toDateString(),
                'description' => $name['description'],
                'member_id' => $name['member_id'],
                'entries' => $lines->count(),

                'is_reversal' => $lines->contains(fn ($line) => $line->reverses_id !== null),

                /*
                 * Whether this document was later undone. Shown because a
                 * document presented without it reads as current, and a reader
                 * who acts on a reversed receipt has been misled by a report
                 * that was accurate about every single figure on it.
                 */
                'reversed' => LedgerTrace::whereIn('reverses_id', $lines->pluck('id'))->exists(),

                'lines' => $lines->map(fn ($line) => [
                    'ledger' => (string) $line->ledger?->name,
                    'account_group' => (string) $line->ledger?->accountGroup?->name,
                    'debit' => (string) $line->debit,
                    'credit' => (string) $line->credit,
                    'narration' => (string) ($line->narration ?? ''),
                ])->all(),

                'total_debit' => $debit,
                'total_credit' => $credit,
                'balanced' => bccomp($debit, $credit, 2) === 0,

                /*
                 * How far apart the two sides are, computed HERE with bcmath.
                 * "Does not balance" without the figure sends the reader to add
                 * up the column themselves, and the app does not do money
                 * arithmetic - the same rule that puts every column total in
                 * `meta` rather than in the screen.
                 */
                'difference' => bccomp($debit, $credit, 2) >= 0
                    ? bcsub($debit, $credit, 2)
                    : bcsub($credit, $debit, 2),
            ],
        ]);
    }

    // ---------------------------------------------------------------- exports

    public function exportMemberwisePaid(Request $request): Response
    {
        $filters = $this->memberwiseFilters($request);
        $format = $this->exportFormat($request);
        $result = $this->memberwisePaidRows($filters);

        if (($tooLarge = $this->rejectIfTooLarge($result['rows'])) !== null) {
            return $tooLarge;
        }

        return $this->sendExport(
            new Report(
                title: 'Memberwise paid',
                association: $this->associationName(),
                columns: $this->memberwiseColumns($result['totals']),
                rows: $result['rows'],
                filters: array_filter([
                    'Period' => $this->describePeriod($filters['from'], $filters['to']),
                    'Member' => $filters['q'],
                ]),
                currency: $this->currency(),
            ),
            $format,
        );
    }

    public function exportTrialBalance(Request $request): Response
    {
        $asOf = $this->asOfFilter($request);
        $result = $this->trialBalanceRows($asOf);

        if (($tooLarge = $this->rejectIfTooLarge($result['rows'])) !== null) {
            return $tooLarge;
        }

        return $this->sendExport(
            new Report(
                title: 'Trial balance',
                association: $this->associationName(),
                columns: [
                    new Column('ledger', 'Account'),
                    new Column('account_group', 'Group'),
                    new Column('category', 'Category'),
                    new Column('debit', 'Debit', Column::TYPE_MONEY, $result['totals']['total_debit']),
                    new Column('credit', 'Credit', Column::TYPE_MONEY, $result['totals']['total_credit']),
                ],
                rows: $result['rows'],
                filters: array_filter([
                    'As at' => $asOf,

                    // Printed on the file, because a trial balance that does not
                    // balance is the whole finding and must not be lost when the
                    // rows are read away from the screen.
                    'Out of balance by' => $result['totals']['balanced']
                        ? null
                        : $result['totals']['difference'],
                ]),
                currency: $this->currency(),
            ),
            $this->exportFormat($request),
        );
    }

    public function exportBalanceSheet(Request $request): Response
    {
        $asOf = $this->asOfFilter($request);
        $result = $this->balanceSheetRows($asOf);

        if (($tooLarge = $this->rejectIfTooLarge($result['rows'])) !== null) {
            return $tooLarge;
        }

        return $this->sendExport(
            new Report(
                title: 'Balance sheet',
                association: $this->associationName(),
                columns: [
                    new Column('section', 'Section'),
                    new Column('account_group', 'Group'),
                    new Column('ledger', 'Account'),

                    /*
                     * No column total. Assets and the funds against them are the
                     * two halves of one statement and summing them together
                     * produces a number with no meaning - twice the size of the
                     * association. The halves are named in `filters` instead.
                     */
                    new Column('amount', 'Amount', Column::TYPE_MONEY),
                ],
                rows: $result['rows'],
                filters: [
                    'As at' => $asOf,
                    'Total assets' => $result['totals']['total_assets'],
                    'Total liabilities' => $result['totals']['total_liabilities'],
                    'Total equity' => $result['totals']['total_equity'],
                ],
                currency: $this->currency(),
            ),
            $this->exportFormat($request),
        );
    }

    public function exportCashSummary(Request $request): Response
    {
        $filters = $this->statementFilters($request);
        $result = $this->cashSummaryRows($filters);

        if (($tooLarge = $this->rejectIfTooLarge($result['rows'])) !== null) {
            return $tooLarge;
        }

        return $this->sendExport(
            new Report(
                title: 'Cash summary',
                association: $this->associationName(),
                columns: [
                    new Column('ledger', 'Account'),
                    new Column('account_group', 'Group'),
                    new Column('opening', 'Opening', Column::TYPE_MONEY, $result['totals']['total_opening']),
                    new Column('received', 'Received', Column::TYPE_MONEY, $result['totals']['total_received']),
                    new Column('paid', 'Paid', Column::TYPE_MONEY, $result['totals']['total_paid']),
                    new Column('closing', 'Closing', Column::TYPE_MONEY, $result['totals']['total_closing']),
                ],
                rows: $result['rows'],
                filters: ['Period' => $this->describePeriod($filters['from'], $filters['to'])],
                currency: $this->currency(),
            ),
            $this->exportFormat($request),
        );
    }

    public function exportVoucherwise(Request $request): Response
    {
        $filters = $this->voucherwiseFilters($request);
        $format = $this->exportFormat($request);

        // null: every document in the range, not the page the screen is on.
        $result = $this->voucherwiseRows($filters, null);

        if (($tooLarge = $this->rejectIfTooLarge($result['rows'])) !== null) {
            return $tooLarge;
        }

        $unbalanced = count(array_filter($result['rows'], fn ($row) => ! $row['balanced']));

        return $this->sendExport(
            new Report(
                title: 'Voucher-wise report',
                association: $this->associationName(),
                columns: [
                    new Column('posted_on', 'Date'),
                    new Column('kind_label', 'Kind'),
                    new Column('number', 'Number'),
                    new Column('description', 'Description'),
                    new Column('note', 'Note'),
                    new Column('entries', 'Entries', Column::TYPE_INTEGER),
                    new Column('amount', 'Amount', Column::TYPE_MONEY, $result['totals']['total_amount']),
                ],
                rows: $result['rows'],
                filters: array_filter([
                    'Period' => $this->describePeriod($filters['from'], $filters['to']),
                    'Kind' => $filters['kind'] === null ? null : ucfirst($filters['kind']),
                    'Matching' => $filters['q'],

                    // Printed on the file for the same reason the trial balance
                    // prints its difference: a document that does not balance is
                    // the finding, and it must survive being read off the screen.
                    'Documents that do not balance' => $unbalanced === 0 ? null : (string) $unbalanced,
                ]),
                currency: $this->currency(),
            ),
            $format,
        );
    }

    public function exportIncomeStatement(Request $request): Response
    {
        $filters = $this->statementFilters($request);
        $format = $this->exportFormat($request);
        $result = $this->incomeStatementRows($filters);

        if (($tooLarge = $this->rejectIfTooLarge($result['rows'])) !== null) {
            return $tooLarge;
        }

        return $this->sendExport(
            new Report(
                title: 'Income statement',
                association: $this->associationName(),
                columns: $this->statementColumns($result['totals']),
                rows: $result['rows'],
                filters: ['Period' => $this->describePeriod($filters['from'], $filters['to'])],
                currency: $this->currency(),
            ),
            $format,
        );
    }

    public function exportDueInfo(Request $request): Response
    {
        $filters = $this->dueFilters($request);
        $format = $this->exportFormat($request);
        $result = $this->dueInfoRows($filters);

        if (($tooLarge = $this->rejectIfTooLarge($result['rows'])) !== null) {
            return $tooLarge;
        }

        return $this->sendExport(
            new Report(
                title: 'Outstanding dues',
                association: $this->associationName(),
                columns: $this->dueColumns($result['totals']),
                rows: $result['rows'],
                filters: [
                    /*
                     * Named for what it actually did. "Assigned up to 3 Sep"
                     * and "Assigned 1 Jan to 3 Sep" are different reports, and
                     * a printed sheet headed only "As at" cannot tell you which
                     * of them you are holding.
                     */
                    'Instalments assigned' => $filters['from'] === null
                        ? 'Up to '.$filters['as_of']
                        : $filters['from'].' to '.$filters['as_of'],
                    'Member status' => $filters['member_status'] === null
                        ? 'Active and suspended'
                        : ucfirst($filters['member_status']),
                ] + array_filter(['Member' => $filters['q']]),
                currency: $this->currency(),
            ),
            $format,
        );
    }

    // ------------------------------------------------------------------ rows

    /**
     * @param  array{from: ?string, to: ?string, q: ?string}  $filters
     * @return array{rows: list<array<string, string|int>>, totals: array<string, string|int>}
     */
    private function memberwisePaidRows(array $filters): array
    {
        $rows = DB::table('payment_info_items as pii')
            ->join('payment_infos as pi', 'pi.id', '=', 'pii.payment_info_id')
            ->join('members as m', 'm.id', '=', 'pi.member_id')
            ->where('pi.status', PaymentInfo::STATUS_COMPLETED)
            ->when($filters['from'], fn ($q, $d) => $q->whereDate('pi.payment_date', '>=', $d))
            ->when($filters['to'], fn ($q, $d) => $q->whereDate('pi.payment_date', '<=', $d))
            ->tap(fn ($q) => $this->whereMemberMatches($q, $filters['q']))
            ->leftJoin('associators_infos as info', 'info.member_id', '=', 'm.id')
            ->groupBy('m.id', 'm.name', 'info.membership_no')
            ->select([
                'm.id as member_id',
                'm.name as member_name',
                'info.membership_no',

                // DISTINCT assignments, and only lines carrying a real
                // instalment. This is FR-REP-4 expressed in SQL.
                DB::raw('COUNT(DISTINCT CASE WHEN pii.amount > 0 THEN pii.fee_assign_id END) as instalments_paid_count'),

                // Instalments and fines summed apart, never together.
                DB::raw('COALESCE(SUM(pii.amount), 0) as instalments_paid_amount'),
                DB::raw('COALESCE(SUM(pii.fine_amount), 0) as fines_paid_amount'),
            ])
            ->orderBy('m.name')
            ->get();

        /*
         * INSTALMENTS RECEIVED BY TRANSFER, kept apart from everything above.
         *
         * The legacy report adds these into the member's paid total
         * (Paid_Cummulative = cumulative_paid + cumulative_transferred), so a
         * member is shown as having paid money the association never received.
         * That is a defensible thing to tell a member about their own standing
         * and an indefensible thing to call collections, and one column cannot
         * be both. Here it is its own figure: total_paid stays money that came
         * in, and what arrived by transfer sits beside it rather than blended
         * into it.
         *
         * Its own query rather than a join, because a member can receive a
         * transfer in a period they paid nothing in - and joining would either
         * lose them or multiply the payment rows by the transfer rows.
         *
         * The date range applies here too. The legacy deliberately ignored it
         * for this column, which produces a row where one figure answers the
         * chosen period and its neighbour answers all of history - they do not
         * add up and nothing on the page says why.
         */
        $transfers = DB::table('share_transfers as st')
            ->join('members as m', 'm.id', '=', 'st.buyer_id')
            ->leftJoin('associators_infos as info', 'info.member_id', '=', 'm.id')
            ->when($filters['from'], fn ($q, $d) => $q->whereDate('st.transferred_on', '>=', $d))
            ->when($filters['to'], fn ($q, $d) => $q->whereDate('st.transferred_on', '<=', $d))
            ->tap(fn ($q) => $this->whereMemberMatches($q, $filters['q']))
            ->groupBy('m.id', 'm.name', 'info.membership_no')
            ->select([
                'm.id as member_id',
                'm.name as member_name',
                'info.membership_no',

                // Shares and instalments are the same unit here: one share of a
                // share-type fee head IS one instalment paid into it.
                DB::raw('COALESCE(SUM(st.shares), 0) as transfers_in_count'),
                DB::raw('COALESCE(SUM(st.amount), 0) as transfers_in_amount'),
            ])
            ->get()
            ->keyBy('member_id');

        $instalmentTotal = '0.00';
        $fineTotal = '0.00';
        $countTotal = 0;
        $transferTotal = '0.00';
        $transferCountTotal = 0;
        $out = [];

        foreach ($rows as $row) {
            $instalments = $this->money($row->instalments_paid_amount);
            $fines = $this->money($row->fines_paid_amount);

            $received = $transfers->get($row->member_id);
            $transferAmount = $this->money($received->transfers_in_amount ?? 0);
            $transferCount = (int) ($received->transfers_in_count ?? 0);

            $instalmentTotal = bcadd($instalmentTotal, $instalments, 2);
            $fineTotal = bcadd($fineTotal, $fines, 2);
            $countTotal += (int) $row->instalments_paid_count;
            $transferTotal = bcadd($transferTotal, $transferAmount, 2);
            $transferCountTotal += $transferCount;

            $out[] = [
                'member_id' => (int) $row->member_id,
                'membership_no' => $row->membership_no ?? '',
                'member_name' => $row->member_name,
                'instalments_paid_count' => (int) $row->instalments_paid_count,
                'instalments_paid_amount' => $instalments,
                'fines_paid_amount' => $fines,
                'total_paid' => bcadd($instalments, $fines, 2),
                'transfers_in_count' => $transferCount,
                'transfers_in_amount' => $transferAmount,
            ];

            $transfers->forget($row->member_id);
        }

        /*
         * Whoever is left received a transfer and paid nothing themselves in
         * this period. They belong on a memberwise report: a member holding
         * instalments somebody handed them is the exact case this column exists
         * to make visible, and dropping them would hide it where it matters
         * most. The payments query cannot reach them - it starts from payment
         * rows they do not have.
         */
        foreach ($transfers as $received) {
            $transferAmount = $this->money($received->transfers_in_amount);
            $transferCount = (int) $received->transfers_in_count;

            $transferTotal = bcadd($transferTotal, $transferAmount, 2);
            $transferCountTotal += $transferCount;

            $out[] = [
                'member_id' => (int) $received->member_id,
                'membership_no' => $received->membership_no ?? '',
                'member_name' => $received->member_name,
                'instalments_paid_count' => 0,
                'instalments_paid_amount' => '0.00',
                'fines_paid_amount' => '0.00',
                'total_paid' => '0.00',
                'transfers_in_count' => $transferCount,
                'transfers_in_amount' => $transferAmount,
            ];
        }

        // Re-sorted because the rows appended above arrived out of order: the
        // SQL ordering only covered the members who had paid something.
        usort($out, fn (array $a, array $b) => strcasecmp($a['member_name'], $b['member_name']));

        return [
            'rows' => $out,
            // FR-REP-8: column totals for every summable column.
            'totals' => [
                'instalments_paid_count' => $countTotal,
                'instalments_paid_amount' => $instalmentTotal,
                'fines_paid_amount' => $fineTotal,
                'total_paid' => bcadd($instalmentTotal, $fineTotal, 2),
                'transfers_in_count' => $transferCountTotal,
                'transfers_in_amount' => $transferTotal,
            ],
        ];
    }

    /**
     * @param  array{member_status: ?string, from: ?string, as_of: string, q: ?string}  $filters
     * @return array{rows: list<array<string, string|int>>, totals: array<string, string|int>}
     */
    private function dueInfoRows(array $filters): array
    {
        $rows = DB::table('fee_assigns as fa')
            ->join('members as m', 'm.id', '=', 'fa.member_id')
            ->whereIn('fa.status', [FeeAssign::STATUS_UNPAID, FeeAssign::STATUS_REQUESTED])
            ->whereDate('fa.assign_date', '<=', $filters['as_of'])
            ->when($filters['from'], fn ($q, $d) => $q->whereDate('fa.assign_date', '>=', $d))
            ->tap(fn ($q) => $this->whereMemberMatches($q, $filters['q']))
            ->when(
                $filters['member_status'],
                fn ($q, $s) => $q->where('m.status', $s),
                fn ($q) => $q->where('m.status', '!=', Member::STATUS_INACTIVE)
            )
            ->leftJoin('associators_infos as info', 'info.member_id', '=', 'm.id')
            ->groupBy('m.id', 'm.name', 'm.status', 'info.membership_no')
            ->select([
                'm.id as member_id',
                'm.name as member_name',
                'm.status as member_status',
                'info.membership_no',
                DB::raw('COUNT(DISTINCT fa.id) as instalments_due_count'),
                DB::raw('COALESCE(SUM(fa.amount), 0) as instalments_due'),
                DB::raw('COALESCE(SUM(fa.fine_amount), 0) as fines_due'),
            ])
            ->orderBy('m.name')
            ->get();

        $instalmentTotal = '0.00';
        $fineTotal = '0.00';
        $countTotal = 0;
        $out = [];

        foreach ($rows as $row) {
            $instalments = $this->money($row->instalments_due);
            $fines = $this->money($row->fines_due);

            $instalmentTotal = bcadd($instalmentTotal, $instalments, 2);
            $fineTotal = bcadd($fineTotal, $fines, 2);
            $countTotal += (int) $row->instalments_due_count;

            $out[] = [
                'member_id' => (int) $row->member_id,
                'membership_no' => $row->membership_no ?? '',
                'member_name' => $row->member_name,
                'member_status' => $row->member_status,
                'instalments_due_count' => (int) $row->instalments_due_count,
                'instalments_due' => $instalments,
                'fines_due' => $fines,
                'total_due' => bcadd($instalments, $fines, 2),
            ];
        }

        return [
            'rows' => $out,
            'totals' => [
                // The count total was missing here while the paid report had
                // one. FR-REP-8 says every summable column, and a count of
                // instalments is as summable as the money beside it.
                'instalments_due_count' => $countTotal,
                'instalments_due' => $instalmentTotal,
                'fines_due' => $fineTotal,
                'total_due' => bcadd($instalmentTotal, $fineTotal, 2),
            ],
        ];
    }


    /**
     * Narrow a report to one member, by name or membership number.
     *
     * A report of three hundred rows is not read end to end - it is opened to
     * answer a question about somebody, and the number is how the office
     * identifies them. Both fields, because staff who have the number use it
     * and staff who have the person in front of them do not.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     */
    private function whereMemberMatches($query, ?string $term): void
    {
        if ($term === null || $term === '') {
            return;
        }

        $query->where(function ($inner) use ($term) {
            $inner->where('m.name', 'like', "%{$term}%")
                ->orWhereExists(function ($exists) use ($term) {
                    $exists->selectRaw('1')
                        ->from('associators_infos as ai')
                        ->whereColumn('ai.member_id', 'm.id')
                        ->where('ai.membership_no', 'like', "%{$term}%");
                });
        });
    }

    // --------------------------------------------------------------- columns

    /**
     * @param  array<string, string|int>  $totals
     * @return list<Column>
     */
    private function memberwiseColumns(array $totals): array
    {
        return [
            new Column('membership_no', 'No.'),
            new Column('member_name', 'Member'),
            new Column('instalments_paid_count', 'Instalments paid', Column::TYPE_INTEGER, (string) $totals['instalments_paid_count']),
            new Column('instalments_paid_amount', 'Instalments', Column::TYPE_MONEY, (string) $totals['instalments_paid_amount']),
            new Column('fines_paid_amount', 'Fines', Column::TYPE_MONEY, (string) $totals['fines_paid_amount']),
            new Column('total_paid', 'Total paid', Column::TYPE_MONEY, (string) $totals['total_paid']),

            // After the total, deliberately. These instalments were not paid to
            // the association by this member, and a column placed before the
            // total reads as one of its parts.
            new Column('transfers_in_count', 'Instalments received', Column::TYPE_INTEGER, (string) $totals['transfers_in_count']),
            new Column('transfers_in_amount', 'Received by transfer', Column::TYPE_MONEY, (string) $totals['transfers_in_amount']),
        ];
    }

    /**
     * @param  array<string, string|int>  $totals
     * @return list<Column>
     */
    private function dueColumns(array $totals): array
    {
        return [
            new Column('membership_no', 'No.'),
            new Column('member_name', 'Member'),
            new Column('member_status', 'Status'),
            new Column('instalments_due_count', 'Instalments due', Column::TYPE_INTEGER, (string) $totals['instalments_due_count']),
            new Column('instalments_due', 'Instalments', Column::TYPE_MONEY, (string) $totals['instalments_due']),
            new Column('fines_due', 'Fines', Column::TYPE_MONEY, (string) $totals['fines_due']),
            new Column('total_due', 'Total due', Column::TYPE_MONEY, (string) $totals['total_due']),
        ];
    }

    // -------------------------------------------------------------- dashboard

    /**
     * Headline figures for the staff dashboard.
     */
    public function dashboard(): JsonResponse
    {
        $completedItems = PaymentInfoItem::query()->where('payment_status', PaymentInfo::STATUS_COMPLETED);

        return response()->json([
            'data' => [
                'members' => [
                    'active' => Member::where('status', Member::STATUS_ACTIVE)->count(),
                    'inactive' => Member::where('status', Member::STATUS_INACTIVE)->count(),
                    'suspended' => Member::where('status', Member::STATUS_SUSPENDED)->count(),
                ],
                'collections' => [
                    'instalments' => $this->money((clone $completedItems)->sum('amount')),
                    'fines' => $this->money((clone $completedItems)->sum('fine_amount')),
                ],
                'outstanding' => [
                    'instalments' => $this->money(FeeAssign::outstanding()->sum('amount')),
                    'fines' => $this->money(FeeAssign::outstanding()->sum('fine_amount')),
                ],
                /*
                 * What the approval queue actually holds - so the figure that
                 * sends somebody to that screen matches what they find there.
                 * Payments with a gateway_reference are the bank's to resolve
                 * and are not offered for approval (ADR-0007).
                 */
                'payments_pending_approval' => PaymentInfo::where('status', PaymentInfo::STATUS_PENDING)
                    ->whereNull('gateway_reference')
                    ->count(),
            ],
        ]);
    }

    // ---------------------------------------------------------------- filters

    /** @return array{from: ?string, to: ?string, q: ?string} */
    private function memberwiseFilters(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'q' => ['nullable', 'string', 'max:255'],
        ]);

        return [
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
            'q' => $validated['q'] ?? null,
        ];
    }

    /**
     * @return array{member_status: ?string, from: ?string, as_of: string, q: ?string}
     *
     * `as_of` is the upper bound and keeps its name: it is what the report has
     * always meant, and it is what the JSON, the exports and the tests already
     * call it. `from` is new and OPTIONAL, which is what keeps the two readings
     * of this report compatible:
     *
     *   - with no `from`, it is the snapshot it has always been - everything
     *     still unpaid as at a date;
     *   - with one, it narrows to instalments ASSIGNED in that window and still
     *     unpaid, which is a different and also useful question.
     *
     * Absent both, it is today's snapshot, exactly as before.
     */
    /** A single date, defaulting to today. These three are positions, not periods. */
    private function asOfFilter(Request $request): string
    {
        $validated = $request->validate(['as_of' => ['nullable', 'date']]);

        return $validated['as_of'] ?? now()->toDateString();
    }

    /**
     * @return array{rows: list<array<string, string>>, totals: array<string, string|bool>}
     */
    private function trialBalanceRows(string $asOf): array
    {
        $rows = [];
        $debits = '0.00';
        $credits = '0.00';

        foreach ($this->ledgerBalances(null, $asOf) as $row) {
            $balance = bcadd($this->signedOpening($row), $this->signedMovement($row), 2);

            // A chart lists what an association might use; this states what it
            // did. An account that never moved and opened at nothing is not a
            // line of a trial balance.
            if (bccomp($balance, '0.00', 2) === 0) {
                continue;
            }

            $isDebit = bccomp($balance, '0.00', 2) > 0;
            $magnitude = $isDebit ? $balance : bcsub('0.00', $balance, 2);

            $rows[] = [
                'ledger' => (string) $row->ledger,
                'account_group' => (string) $row->account_group,
                'category' => (string) $row->category,
                'debit' => $isDebit ? $magnitude : '0.00',
                'credit' => $isDebit ? '0.00' : $magnitude,
            ];

            if ($isDebit) {
                $debits = bcadd($debits, $magnitude, 2);
            } else {
                $credits = bcadd($credits, $magnitude, 2);
            }
        }

        $difference = bcsub($debits, $credits, 2);

        return [
            'rows' => $rows,
            'totals' => [
                'total_debit' => $debits,
                'total_credit' => $credits,

                /*
                 * The answer this report exists to give. Entries cannot be
                 * unbalanced here - a voucher is refused unless it balances and
                 * every payment posts a pair - so a difference means an OPENING
                 * BALANCE is wrong, which is the one figure staff type by hand.
                 */
                'balanced' => bccomp($difference, '0.00', 2) === 0,
                'difference' => $difference,
            ],
        ];
    }

    /**
     * @return array{rows: list<array<string, string>>, totals: array<string, string|bool>}
     */
    private function balanceSheetRows(string $asOf): array
    {
        $rows = [];
        $assets = '0.00';
        $liabilities = '0.00';
        $equity = '0.00';
        $surplus = '0.00';

        foreach ($this->ledgerBalances(null, $asOf) as $row) {
            $balance = bcadd($this->signedOpening($row), $this->signedMovement($row), 2);

            /*
             * Income and expense accounts are not lines of a balance sheet -
             * but the result they produce is. Everything earned less everything
             * spent, since the beginning, belongs to the members and is added
             * to equity below as one line. Leave it out and the statement fails
             * to balance by exactly that amount.
             */
            if (in_array($row->type, ['income', 'expense'], true)) {
                // Debit-positive, so an expense adds and income subtracts; the
                // surplus is the negative of that sum.
                $surplus = bcsub($surplus, $balance, 2);

                continue;
            }

            if (bccomp($balance, '0.00', 2) === 0) {
                continue;
            }

            // Assets read naturally as debits; what the association owes and
            // what it is worth read naturally as credits.
            $presented = $row->type === 'asset' ? $balance : bcsub('0.00', $balance, 2);

            $rows[] = [
                'section' => (string) $row->type,
                'account_group' => (string) $row->account_group,
                'ledger' => (string) $row->ledger,
                'amount' => $presented,
            ];

            match ($row->type) {
                'asset' => $assets = bcadd($assets, $presented, 2),
                'liability' => $liabilities = bcadd($liabilities, $presented, 2),
                default => $equity = bcadd($equity, $presented, 2),
            };
        }

        if (bccomp($surplus, '0.00', 2) !== 0) {
            $rows[] = [
                'section' => 'equity',
                'account_group' => 'Retained earnings',

                /*
                 * Its own line rather than folded into Share Capital, because
                 * it is not share capital - nobody subscribed for it. An
                 * association should be able to see how much of what it is
                 * worth was paid in and how much was earned.
                 */
                'ledger' => 'Accumulated surplus',
                'amount' => $surplus,
            ];
        }

        $funds = bcadd(bcadd($liabilities, $equity, 2), $surplus, 2);
        $difference = bcsub($assets, $funds, 2);

        return [
            'rows' => $rows,
            'totals' => [
                'total_assets' => $assets,
                'total_liabilities' => $liabilities,
                'total_equity' => bcadd($equity, $surplus, 2),
                'accumulated_surplus' => $surplus,
                'balanced' => bccomp($difference, '0.00', 2) === 0,
                'difference' => $difference,
            ],
        ];
    }

    /**
     * @param  array{from: string, to: string}  $filters
     * @return array{rows: list<array<string, string>>, totals: array<string, string>}
     */
    private function cashSummaryRows(array $filters): array
    {
        /*
         * TWO PASSES, because the opening balance is a different question from
         * the movement. The first asks what was there the day before the period
         * began; the second asks what moved inside it. Asking one query for
         * both would mean subtracting the period back out of the total, which
         * is the same answer by a route nobody can check.
         */
        $dayBefore = CarbonImmutable::createFromFormat('Y-m-d', $filters['from'])
            ->subDay()
            ->toDateString();

        $openings = [];

        foreach ($this->ledgerBalances(null, $dayBefore) as $row) {
            if (! $row->is_cash) {
                continue;
            }

            $openings[$row->id] = bcadd($this->signedOpening($row), $this->signedMovement($row), 2);
        }

        $rows = [];
        $totalOpening = '0.00';
        $totalIn = '0.00';
        $totalOut = '0.00';

        foreach ($this->ledgerBalances($filters['from'], $filters['to']) as $row) {
            if (! $row->is_cash) {
                continue;
            }

            $opening = $openings[$row->id] ?? $this->signedOpening($row);
            $in = (string) $row->debit;
            $out = (string) $row->credit;

            $rows[] = [
                'ledger' => (string) $row->ledger,
                'account_group' => (string) $row->account_group,
                'opening' => $opening,
                'received' => $in,
                'paid' => $out,

                // Computed, not read back, so the four figures on a row are
                // arithmetic the reader can check by eye.
                'closing' => bcadd($opening, bcsub($in, $out, 2), 2),
            ];

            $totalOpening = bcadd($totalOpening, $opening, 2);
            $totalIn = bcadd($totalIn, $in, 2);
            $totalOut = bcadd($totalOut, $out, 2);
        }

        return [
            'rows' => $rows,
            'totals' => [
                'total_opening' => $totalOpening,
                'total_received' => $totalIn,
                'total_paid' => $totalOut,
                'total_closing' => bcadd($totalOpening, bcsub($totalIn, $totalOut, 2), 2),
            ],
        ];
    }

    /**
     * What a document can be, and what the API calls it.
     *
     * `ledger_traces.source_type` is a fully-qualified class name. It names the
     * namespace layout and the framework, it changes when a model moves, and
     * nothing outside the server should ever see it - least of all as the value
     * a client sends back to filter on. So the wire carries a word.
     */
    private const DOCUMENT_KINDS = [
        'payment' => PaymentInfo::class,
        'voucher' => Voucher::class,
    ];

    private function documentKind(?string $sourceType): string
    {
        $kind = array_search($sourceType, self::DOCUMENT_KINDS, true);

        return $kind === false ? 'other' : $kind;
    }

    /** The same, as prose - for a PDF, where "payment" in lower case looks like a mistake. */
    private function documentKindLabel(?string $sourceType): string
    {
        return match ($this->documentKind($sourceType)) {
            'payment' => 'Payment',
            'voucher' => 'Voucher',
            default => 'Unattributed',
        };
    }

    /**
     * @return array{from: string, to: string, kind: ?string, q: ?string}
     *
     * BOTH BOUNDS REQUIRED, as the statements require them. Every document an
     * association has ever posted is not a report anybody asked for, and the
     * legacy form requires them too - it falls back to yesterday and today.
     */
    private function voucherwiseFilters(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'kind' => ['nullable', Rule::in(array_keys(self::DOCUMENT_KINDS))],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        return [
            'from' => $validated['from'],
            'to' => $validated['to'],
            'kind' => $validated['kind'] ?? null,
            'q' => $validated['q'] ?? null,
        ];
    }

    /**
     * One row per document, grouped in the database.
     *
     * `$perPage` of null asks for every row, which is what an export needs.
     *
     * THE TOTAL IS OVER THE RANGE, NOT THE PAGE. A footer that changes as the
     * reader pages through is worse than no footer: it looks like a total and
     * answers a question nobody asked. So the amount is one scalar sum over the
     * filtered traces - which is the same figure, because a document's amount
     * is the sum of its debits and every document is in exactly one page.
     *
     * @param  array{from: string, to: string, kind: ?string, q: ?string}  $filters
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     totals: array<string, string>,
     *     page: array<string, int>
     * }
     */
    private function voucherwiseRows(array $filters, ?int $perPage): array
    {
        $scope = fn ($query) => $query
            ->whereBetween('posted_on', [$filters['from'], $filters['to']])
            ->when(
                $filters['kind'] !== null,
                fn ($q) => $q->where('source_type', self::DOCUMENT_KINDS[$filters['kind']]),
            )
            ->when(
                $filters['q'] !== null,
                fn ($q) => $q->where('reference', 'like', '%'.$filters['q'].'%'),
            );

        $query = $scope(DB::table('ledger_traces'))
            /*
             * NULL source types collapse into a single group, which is what
             * GROUP BY does with them and what this report should say: traces
             * nobody can attribute are one finding, not a list of documents.
             * Nothing in this system writes one - both posting paths set a
             * source - so the row exists for imported data.
             */
            ->groupBy('source_type', 'source_id')
            ->selectRaw(
                'MIN(id) as trace_id, source_type, source_id, '
                .'MIN(posted_on) as posted_on, MAX(reference) as reference, '
                .'SUM(debit) as debit, SUM(credit) as credit, COUNT(*) as entries, '
                .'MAX(CASE WHEN reverses_id IS NULL THEN 0 ELSE 1 END) as is_reversal'
            )
            // Newest first. A listing of documents is read backwards from today,
            // which is the opposite of the way a statement is read.
            ->orderByRaw('MIN(posted_on) DESC, MIN(id) DESC');

        if ($perPage === null) {
            $groups = $query->get();

            $page = [
                'current_page' => 1,
                'last_page' => 1,
                'per_page' => $groups->count(),
                'total' => $groups->count(),
            ];
        } else {
            $paginator = $query->paginate($perPage);
            $groups = $paginator->getCollection();

            $page = [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ];
        }

        $named = $this->nameDocuments($groups);
        $rows = [];

        foreach ($groups as $group) {
            $debit = (string) $group->debit;
            $credit = (string) $group->credit;
            $name = $named[$group->source_type.'|'.$group->source_id];

            $reversal = (bool) $group->is_reversal;

            /*
             * Per DOCUMENT, which no other report asks. A voucher cannot be
             * approved unbalanced and a payment that posted unbalanced is
             * refused, so against data this system wrote it is always true -
             * but this report will be pointed at imported legacy traces, where
             * it is the first thing worth knowing about a row.
             */
            $balanced = bccomp($debit, $credit, 2) === 0;

            $rows[] = [
                'trace_id' => (int) $group->trace_id,
                'posted_on' => (string) $group->posted_on,
                'kind' => $this->documentKind($group->source_type),
                'kind_label' => $this->documentKindLabel($group->source_type),
                'number' => (string) ($group->reference ?? ''),
                'description' => $name['description'],
                'member_id' => $name['member_id'],
                'entries' => (int) $group->entries,
                'amount' => $debit,
                'is_reversal' => $reversal,
                'balanced' => $balanced,

                /*
                 * The two flags as one phrase, computed HERE rather than on the
                 * screen, so the download and the screen cannot come to differ
                 * about which documents were worth remarking on.
                 */
                'note' => implode(', ', array_filter([
                    $reversal ? 'Reversal' : null,
                    $balanced ? null : 'Does not balance',
                ])),
            ];
        }

        $amount = $scope(DB::table('ledger_traces'))->sum('debit');

        return [
            'rows' => $rows,
            'totals' => ['total_amount' => number_format((float) $amount, 2, '.', '')],
            'page' => $page,
        ];
    }

    /**
     * The documents named - one query per kind, not one per row.
     *
     * A trace knows what produced it but not what to call it, and the answer
     * differs by kind: a voucher's description is its narration, a payment's is
     * the member who made it. Both are what somebody scanning the list is
     * actually looking for, and neither is on `ledger_traces`.
     *
     * @param  Collection<int, object>  $groups
     * @return array<string, array{description: string, member_id: ?int}>
     */
    private function nameDocuments(Collection $groups): array
    {
        $idsOf = fn (string $type) => $groups
            ->where('source_type', $type)
            ->pluck('source_id')
            ->all();

        $vouchers = Voucher::query()
            ->whereIn('id', $idsOf(Voucher::class))
            ->get(['id', 'voucher_no', 'type', 'narration'])
            ->keyBy('id');

        $payments = PaymentInfo::query()
            ->whereIn('id', $idsOf(PaymentInfo::class))
            ->with('member:id,name')
            ->get(['id', 'member_id', 'payment_type'])
            ->keyBy('id');

        $named = [];

        foreach ($groups as $group) {
            $named[$group->source_type.'|'.$group->source_id] = match ($group->source_type) {
                Voucher::class => $this->nameVoucher($vouchers->get($group->source_id)),
                PaymentInfo::class => $this->namePayment($payments->get($group->source_id)),

                /*
                 * Said plainly rather than left blank. A row with an empty
                 * description reads as a display fault; this one is a fact
                 * about the books, and a reader should be able to see it.
                 */
                default => ['description' => 'Not attributed to a document', 'member_id' => null],
            };
        }

        return $named;
    }

    /** @return array{description: string, member_id: ?int} */
    private function nameVoucher(?Voucher $voucher): array
    {
        if ($voucher === null) {
            // Its traces are in the ledger and the document is gone. Not
            // possible through this API - an approved voucher cannot be deleted
            // - so it is said out loud rather than shown as an empty cell.
            return ['description' => 'Voucher no longer on file', 'member_id' => null];
        }

        return [
            'description' => $voucher->narration ?: ucfirst((string) $voucher->type).' voucher',
            'member_id' => null,
        ];
    }

    /** @return array{description: string, member_id: ?int} */
    private function namePayment(?PaymentInfo $payment): array
    {
        if ($payment === null) {
            return ['description' => 'Payment no longer on file', 'member_id' => null];
        }

        return [
            'description' => (string) ($payment->member?->name ?? 'Member no longer on file'),
            'member_id' => $payment->member_id,
        ];
    }

    /**
     * Every ledger's balance as at a date, in ONE debit-positive number.
     *
     * The three statements below are the same arithmetic asked three ways, so
     * it is done once here rather than three times slightly differently.
     *
     * WHY DEBIT-POSITIVE. A ledger's balance has a side, and carrying that side
     * around as a separate column means every caller has to remember which way
     * round its account type runs. Signed, the rule is stated once: a positive
     * balance is a debit balance, a negative one is a credit balance, and the
     * sum of every balance in a set of books is zero. A trial balance is that
     * sentence turned into two columns; a balance sheet is it split by
     * category.
     *
     * THE OPENING BALANCE HAS A SIDE TOO, and the column does not say which.
     * `ledgers.opening_balance` is an unsigned decimal, so its side comes from
     * the account: assets and expenses are debit-normal, liabilities, equity
     * and income are credit-normal, and a positive opening means "the normal
     * side for this kind of account". That is the convention every chart of
     * accounts uses, and the alternative - a second column saying which side -
     * would be a field somebody has to keep correct.
     *
     * `$from` EXCLUDES EARLIER MOVEMENT rather than filtering it out. A cash
     * summary wants the period's movement with the opening stated separately;
     * a balance sheet wants everything up to a date. Passing null asks for the
     * second.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function ledgerBalances(?string $from, string $asOf)
    {
        $movement = LedgerTrace::query()
            ->selectRaw('ledger_id, SUM(debit) as debit, SUM(credit) as credit')
            ->where('posted_on', '<=', $asOf)
            ->when($from !== null, fn ($q) => $q->where('posted_on', '>=', $from))
            ->groupBy('ledger_id');

        return DB::table('ledgers')
            ->join('account_groups', 'account_groups.id', '=', 'ledgers.account_group_id')
            ->join('account_categories', 'account_categories.id', '=', 'account_groups.account_category_id')
            ->leftJoinSub($movement, 'm', 'm.ledger_id', '=', 'ledgers.id')
            ->orderByRaw("FIELD(account_categories.type, 'asset', 'liability', 'equity', 'income', 'expense')")
            ->orderBy('account_groups.name')
            ->orderBy('ledgers.name')
            ->selectRaw(
                'ledgers.id, ledgers.name as ledger, ledgers.opening_balance, ledgers.is_cash, '
                .'account_groups.name as account_group, account_categories.name as category, '
                .'account_categories.type as type, '
                .'COALESCE(m.debit, 0) as debit, COALESCE(m.credit, 0) as credit'
            )
            ->get();
    }

    /** Debit-normal kinds. A positive opening balance on these is a debit. */
    private const DEBIT_NORMAL = ['asset', 'expense'];

    /**
     * The opening balance with its side applied, as a debit-positive string.
     *
     * Only meaningful when the caller asked for everything up to a date. A
     * report that states an opening separately - the cash summary - adds it
     * itself, from a prior call.
     */
    private function signedOpening(object $row): string
    {
        $opening = (string) $row->opening_balance;

        return in_array($row->type, self::DEBIT_NORMAL, true)
            ? $opening
            : bcsub('0.00', $opening, 2);
    }

    /** Movement in debit-positive terms: what went in less what went out. */
    private function signedMovement(object $row): string
    {
        return bcsub((string) $row->debit, (string) $row->credit, 2);
    }

    /**
     * @return array{from: string, to: string}
     *
     * BOTH BOUNDS REQUIRED, unlike the listings. "All time" is a sensible thing
     * to ask a list of members; it is not a sensible thing to ask a statement,
     * which only means anything over a stated period. The legacy form requires
     * them too.
     */
    private function statementFilters(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        return [
            'from' => $validated['from'],
            'to' => $validated['to'],
        ];
    }

    /**
     * @param  array{from: string, to: string}  $filters
     * @return array{rows: list<array<string, string>>, totals: array<string, string>}
     */
    private function incomeStatementRows(array $filters): array
    {
        $grouped = LedgerTrace::query()
            ->join('ledgers', 'ledgers.id', '=', 'ledger_traces.ledger_id')
            ->join('account_groups', 'account_groups.id', '=', 'ledgers.account_group_id')
            ->join('account_categories', 'account_categories.id', '=', 'account_groups.account_category_id')
            ->whereIn('account_categories.type', ['income', 'expense'])
            ->whereBetween('ledger_traces.posted_on', [$filters['from'], $filters['to']])
            ->groupBy(
                'account_categories.type',
                'account_groups.name',
                'ledgers.id',
                'ledgers.name',
            )
            ->orderByRaw("FIELD(account_categories.type, 'income', 'expense')")
            ->orderBy('account_groups.name')
            ->orderBy('ledgers.name')
            ->selectRaw(
                'account_categories.type as section, account_groups.name as account_group, '
                .'ledgers.name as ledger, SUM(ledger_traces.debit) as debit, '
                .'SUM(ledger_traces.credit) as credit'
            )
            ->get();

        $rows = [];
        $income = '0.00';
        $expense = '0.00';

        foreach ($grouped as $row) {
            $debit = (string) $row->debit;
            $credit = (string) $row->credit;

            // Both sides, always. See the note on incomeStatement() for why the
            // legacy's one-sided sum cannot see a reversal.
            $amount = $row->section === 'income'
                ? bcsub($credit, $debit, 2)
                : bcsub($debit, $credit, 2);

            if ($row->section === 'income') {
                $income = bcadd($income, $amount, 2);
            } else {
                $expense = bcadd($expense, $amount, 2);
            }

            $rows[] = [
                'section' => $row->section,
                'account_group' => (string) $row->account_group,
                'ledger' => (string) $row->ledger,

                /*
                 * SIGNED: income positive, expense negative. One representation,
                 * so the column a reader sums in a spreadsheet comes to the same
                 * surplus the server printed - which is the whole point of
                 * Column::$total carrying the server's figure (FR-REP-8).
                 */
                'amount' => $row->section === 'income' ? $amount : bcsub('0.00', $amount, 2),
            ];
        }

        return [
            'rows' => $rows,
            'totals' => [
                'total_income' => $income,
                'total_expense' => $expense,

                /*
                 * SURPLUS, not "profit". A cooperative society does not trade
                 * for profit, and its own rules call what is left a surplus -
                 * which is also the word its committee will be looking for.
                 */
                'net_surplus' => bcsub($income, $expense, 2),
            ],
        ];
    }

    /**
     * @param  array<string, string>  $totals
     * @return list<Column>
     */
    private function statementColumns(array $totals): array
    {
        return [
            new Column('section', 'Section'),
            new Column('account_group', 'Group'),
            new Column('ledger', 'Account'),
            new Column(
                'amount',
                'Amount (income +, expense −)',
                Column::TYPE_MONEY,
                $totals['net_surplus'],
            ),
        ];
    }

    private function dueFilters(Request $request): array
    {
        $validated = $request->validate([
            'member_status' => ['nullable', 'in:active,suspended,inactive'],
            'from' => ['nullable', 'date'],
            'as_of' => ['nullable', 'date', 'after_or_equal:from'],
            'q' => ['nullable', 'string', 'max:255'],
        ]);

        return [
            'member_status' => $validated['member_status'] ?? null,
            'from' => $validated['from'] ?? null,
            'as_of' => $validated['as_of'] ?? now()->toDateString(),
            'q' => $validated['q'] ?? null,
        ];
    }
}
