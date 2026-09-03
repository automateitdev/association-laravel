<?php

declare(strict_types=1);

namespace App\Audit;

use App\Models\Tenant\PaymentInfo;
use Illuminate\Support\Facades\DB;

/**
 * FR-REP-6. Everything the payment data must never say, asked as questions.
 *
 * WHY THIS IS NOT A PORT OF THE LEGACY AUDIT
 * ------------------------------------------
 * The legacy service's fourteen checks were run against the production copy on
 * 2026-09-03 and returned **zero findings** - and that was not because the data
 * was clean. Two defects were quietly corrupting member-visible figures at the
 * time, and neither had a check looking for it:
 *
 *   - six members' share balances disagreed with what they had paid for (D-19)
 *   - 716 completed payments had no ledger entry at all (D-20)
 *
 * An audit that reports zero while that is true is worse than no audit, because
 * it is taken as evidence. So the money and status checks are carried over -
 * they are cheap and a regression in them would be serious - but the checks
 * that actually found damage are the point of this class.
 *
 * WHAT THIS SCHEMA MAKES IMPOSSIBLE
 * ---------------------------------
 * Three legacy checks cannot fire here, and are listed by `preventedChecks()`
 * rather than implemented as code that can only ever return nothing:
 *
 *   - duplicate assignment for a member, head and period - `uniq_member_head_period`
 *   - the same assignment twice on one invoice - `uniq_invoice_assign`
 *   - a non-numeric ledger reference - `ledger_id` is a real foreign key here,
 *     where the legacy column was `text` and held the string 'Choose One'
 *
 * Saying so is part of the report. "We looked and found nothing" and "this
 * cannot happen" are different assurances, and only the second one stays true
 * when nobody is looking.
 *
 * ARITHMETIC
 * ----------
 * Comparisons happen in SQL, which is safe here and would not have been in the
 * legacy schema: money is `DECIMAL(15,2)`, so MySQL compares it exactly. The
 * legacy audit needed an epsilon because its columns were `DOUBLE`.
 */
class PaymentAudit
{
    /**
     * Every check, in the order a reader should meet them: money first, because
     * a wrong total is what a member notices.
     *
     * @var array<string, array{0: string, 1: string}> check => [label, severity]
     */
    public const CHECKS = [
        'TOTAL_MISMATCH' => ['Total is not instalments plus fine', Finding::SEVERITY_HIGH],
        'PAYABLE_NOT_SUM_OF_ITEMS' => ['Instalment total does not match its items', Finding::SEVERITY_HIGH],
        'FINE_NOT_SUM_OF_ITEMS' => ['Fine total does not match its items', Finding::SEVERITY_HIGH],
        'ITEM_AMOUNT_DIFFERS_FROM_ASSIGN' => ['Item charged an amount the assignment does not', Finding::SEVERITY_HIGH],
        'NO_LEDGER_POSTING' => ['Completed payment with no ledger entry', Finding::SEVERITY_HIGH],
        'UNBALANCED_POSTING' => ['Ledger entries for a payment do not balance', Finding::SEVERITY_HIGH],
        'COMPLETED_WITHOUT_LEDGER' => ['Completed payment names no receiving ledger', Finding::SEVERITY_HIGH],
        'SHARE_BALANCE_MISMATCH' => ['Share balance disagrees with shares paid for', Finding::SEVERITY_HIGH],
        'PAID_WITHOUT_PAYMENT' => ['Instalment marked Paid with no payment behind it', Finding::SEVERITY_MEDIUM],
        'PAID_NOT_MARKED' => ['Instalment paid but not marked Paid', Finding::SEVERITY_MEDIUM],
        'ITEM_PERIOD_MISMATCH' => ['Item period differs from its assignment', Finding::SEVERITY_MEDIUM],
        'INVOICE_WHITESPACE' => ['Invoice number has leading or trailing whitespace', Finding::SEVERITY_MEDIUM],
    ];

    /**
     * Checks this schema makes structurally impossible, and what prevents them.
     *
     * @return array<string, string>
     */
    public static function preventedChecks(): array
    {
        return [
            'DUPLICATE_ASSIGNMENT' => 'Prevented by the unique index uniq_member_head_period on (member_id, fee_setup_id, period).',
            'DUPLICATE_ITEM_ON_INVOICE' => 'Prevented by the unique index uniq_invoice_assign on (payment_info_id, fee_assign_id).',
            'LEDGER_REFERENCE_NOT_A_LEDGER' => 'Prevented by payment_infos.ledger_id being a real foreign key. The legacy column was text and held the string "Choose One" on eleven completed payments.',
        ];
    }

    /**
     * Run everything.
     *
     * @param  list<string>|null  $only  Limit to these check ids.
     * @return list<Finding>
     */
    public function run(?array $only = null): array
    {
        $checks = [
            'TOTAL_MISMATCH' => fn () => $this->totalMismatch(),
            'PAYABLE_NOT_SUM_OF_ITEMS' => fn () => $this->payableNotSumOfItems(),
            'FINE_NOT_SUM_OF_ITEMS' => fn () => $this->fineNotSumOfItems(),
            'ITEM_AMOUNT_DIFFERS_FROM_ASSIGN' => fn () => $this->itemAmountDiffersFromAssign(),
            'NO_LEDGER_POSTING' => fn () => $this->noLedgerPosting(),
            'UNBALANCED_POSTING' => fn () => $this->unbalancedPosting(),
            'COMPLETED_WITHOUT_LEDGER' => fn () => $this->completedWithoutLedger(),
            'SHARE_BALANCE_MISMATCH' => fn () => $this->shareBalanceMismatch(),
            'PAID_WITHOUT_PAYMENT' => fn () => $this->paidWithoutPayment(),
            'PAID_NOT_MARKED' => fn () => $this->paidNotMarked(),
            'ITEM_PERIOD_MISMATCH' => fn () => $this->itemPeriodMismatch(),
            'INVOICE_WHITESPACE' => fn () => $this->invoiceWhitespace(),
        ];

        $findings = [];

        foreach ($checks as $check => $run) {
            if ($only !== null && ! in_array($check, $only, true)) {
                continue;
            }

            foreach ($run() as $finding) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    // ------------------------------------------------------------------ money

    /** @return list<Finding> */
    private function totalMismatch(): array
    {
        $rows = $this->completed()
            ->whereRaw('total_amount <> payable_amount + fine_amount')
            ->get(['id', 'invoice_no', 'payable_amount', 'fine_amount', 'total_amount']);

        return $this->map($rows, 'TOTAL_MISMATCH', fn ($row) => sprintf(
            'Total %s, but instalments %s plus fine %s is %s.',
            $row->total_amount,
            $row->payable_amount,
            $row->fine_amount,
            bcadd((string) $row->payable_amount, (string) $row->fine_amount, 2),
        ));
    }

    /** @return list<Finding> */
    private function payableNotSumOfItems(): array
    {
        return $this->componentMismatch('payable_amount', 'amount', 'PAYABLE_NOT_SUM_OF_ITEMS', 'Instalments');
    }

    /**
     * A fine is not an instalment (ADR-0005), so the two totals are checked
     * separately against their own item columns. The legacy system had no such
     * check because it had nowhere consistent to put a fine.
     *
     * @return list<Finding>
     */
    private function fineNotSumOfItems(): array
    {
        return $this->componentMismatch('fine_amount', 'fine_amount', 'FINE_NOT_SUM_OF_ITEMS', 'Fines');
    }

    /** @return list<Finding> */
    private function componentMismatch(string $header, string $item, string $check, string $noun): array
    {
        $rows = $this->completed()
            ->whereRaw(sprintf(
                '%s <> (SELECT COALESCE(SUM(%s), 0) FROM payment_info_items WHERE payment_info_id = payment_infos.id)',
                $header,
                $item,
            ))
            ->get(['id', 'invoice_no', $header]);

        return $this->map($rows, $check, function ($row) use ($header, $item, $noun) {
            $sum = DB::table('payment_info_items')
                ->where('payment_info_id', $row->id)
                ->sum($item);

            return sprintf(
                '%s recorded as %s, but its items add up to %s.',
                $noun,
                $row->{$header},
                number_format((float) $sum, 2, '.', ''),
            );
        });
    }

    /**
     * An item that charged something its assignment does not say.
     *
     * The fine is excluded on purpose: an item's `fine_amount` legitimately
     * differs from the assignment's, because a fine grows with each month that
     * passes and the item records what was actually charged on the day.
     *
     * @return list<Finding>
     */
    private function itemAmountDiffersFromAssign(): array
    {
        $rows = DB::table('payment_info_items as it')
            ->join('payment_infos as p', 'p.id', '=', 'it.payment_info_id')
            ->join('fee_assigns as fa', 'fa.id', '=', 'it.fee_assign_id')
            ->where('p.status', 'completed')
            ->whereColumn('it.amount', '<>', 'fa.amount')
            ->get(['it.id', 'p.invoice_no', 'it.amount', 'fa.amount as assigned', 'it.period']);

        return array_map(fn ($row) => new Finding(
            check: 'ITEM_AMOUNT_DIFFERS_FROM_ASSIGN',
            severity: self::CHECKS['ITEM_AMOUNT_DIFFERS_FROM_ASSIGN'][1],
            subject: $row->invoice_no . ' / ' . $row->period,
            detail: sprintf('Charged %s where the assignment says %s.', $row->amount, $row->assigned),
            id: (int) $row->id,
        ), $rows->all());
    }

    // ------------------------------------------------------------- accounting

    /**
     * Completed payments with nothing in the ledger (D-20).
     *
     * This found 716 payments in the legacy production data, unnoticed for
     * three years. It should never fire here - LedgerService posts inside the
     * completing transaction, so a payment that fails to post cannot commit -
     * which is exactly why it is worth asserting. A check that only guards an
     * invariant somebody else already guarantees is the cheapest kind to keep.
     *
     * @return list<Finding>
     */
    private function noLedgerPosting(): array
    {
        $rows = $this->completed()
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')
                    ->from('ledger_traces')
                    ->where('source_type', PaymentInfo::class)
                    ->whereColumn('ledger_traces.source_id', 'payment_infos.id');
            })
            ->get(['id', 'invoice_no', 'total_amount', 'payment_date']);

        return $this->map($rows, 'NO_LEDGER_POSTING', fn ($row) => sprintf(
            'Completed on %s for %s with no ledger entry. That is money taken which the accounts do not show.',
            $row->payment_date ?? 'an unrecorded date',
            $row->total_amount,
        ));
    }

    /** @return list<Finding> */
    private function unbalancedPosting(): array
    {
        $rows = DB::table('ledger_traces')
            ->join('payment_infos as p', 'p.id', '=', 'ledger_traces.source_id')
            ->where('ledger_traces.source_type', PaymentInfo::class)
            ->groupBy('p.id', 'p.invoice_no')
            ->havingRaw('SUM(ledger_traces.debit) <> SUM(ledger_traces.credit)')
            ->get([
                'p.id',
                'p.invoice_no',
                DB::raw('SUM(ledger_traces.debit) as debits'),
                DB::raw('SUM(ledger_traces.credit) as credits'),
            ]);

        return array_map(fn ($row) => new Finding(
            check: 'UNBALANCED_POSTING',
            severity: self::CHECKS['UNBALANCED_POSTING'][1],
            subject: $row->invoice_no,
            detail: sprintf('Debits %s against credits %s.', $row->debits, $row->credits),
            id: (int) $row->id,
        ), $rows->all());
    }

    /**
     * `ledger_id` is nullable, so a completed payment can still name no ledger
     * even though it cannot name a non-existent one.
     *
     * @return list<Finding>
     */
    private function completedWithoutLedger(): array
    {
        $rows = $this->completed()
            ->whereNull('ledger_id')
            ->get(['id', 'invoice_no', 'total_amount']);

        return $this->map($rows, 'COMPLETED_WITHOUT_LEDGER', fn ($row) => sprintf(
            'Completed for %s without naming the ledger the money went into.',
            $row->total_amount,
        ));
    }

    // ----------------------------------------------------------------- shares

    /**
     * A share balance that disagrees with the shares the member paid for.
     *
     * This is the check that found D-19: six members holding shares nobody had
     * bought, because a suspended payment's shares were never taken back.
     *
     * Expected = shares paid for on completed payments, plus shares bought in a
     * transfer, minus shares sold. The transfer arithmetic matters here in a way
     * it did not in the legacy data, where `share_transfers` was empty.
     *
     * @return list<Finding>
     */
    private function shareBalanceMismatch(): array
    {
        $paidFor = DB::table('payment_info_items as it')
            ->join('payment_infos as p', 'p.id', '=', 'it.payment_info_id')
            ->join('fee_assigns as fa', 'fa.id', '=', 'it.fee_assign_id')
            ->join('fee_setups as fs', 'fs.id', '=', 'fa.fee_setup_id')
            ->where('p.status', 'completed')
            ->where('fs.is_share', true)
            ->where('fs.amount', '>', 0)
            ->groupBy('fa.member_id', 'fs.id')
            ->get([
                'fa.member_id',
                'fs.id as fee_setup_id',
                DB::raw('SUM(FLOOR(it.amount / fs.amount)) as shares'),
            ]);

        $expected = [];

        foreach ($paidFor as $row) {
            $expected[$row->member_id][$row->fee_setup_id] = (int) $row->shares;
        }

        foreach (DB::table('share_transfers')->get() as $transfer) {
            $expected[$transfer->buyer_id][$transfer->fee_setup_id] =
                ($expected[$transfer->buyer_id][$transfer->fee_setup_id] ?? 0) + (int) $transfer->shares;

            $expected[$transfer->seller_id][$transfer->fee_setup_id] =
                ($expected[$transfer->seller_id][$transfer->fee_setup_id] ?? 0) - (int) $transfer->shares;
        }

        $held = DB::table('member_share_balances')->get();

        $findings = [];

        foreach ($held as $balance) {
            $should = $expected[$balance->member_id][$balance->fee_setup_id] ?? 0;

            /*
              Consumed whether or not it matched. Leaving a matched expectation
              in place made the leftover pass below report it a second time as
              "paid for shares but holds no balance", so a perfectly correct
              transfer produced a finding. Caught by
              test_a_transferred_share_is_not_reported_as_a_mismatch.
            */
            unset($expected[$balance->member_id][$balance->fee_setup_id]);

            if ((int) $balance->shares === $should) {
                continue;
            }

            $findings[] = new Finding(
                check: 'SHARE_BALANCE_MISMATCH',
                severity: self::CHECKS['SHARE_BALANCE_MISMATCH'][1],
                subject: $this->memberSubject($balance->member_id),
                detail: sprintf(
                    'Holds %d share(s) where payments and transfers support %d.',
                    $balance->shares,
                    $should,
                ),
                id: (int) $balance->id,
            );
        }

        // A member who paid for shares and has no balance row at all is just as
        // wrong as one whose number is off, and is easier to miss.
        foreach ($expected as $memberId => $byFeeSetup) {
            foreach ($byFeeSetup as $shares) {
                if ($shares === 0) {
                    continue;
                }

                $findings[] = new Finding(
                    check: 'SHARE_BALANCE_MISMATCH',
                    severity: self::CHECKS['SHARE_BALANCE_MISMATCH'][1],
                    subject: $this->memberSubject((int) $memberId),
                    detail: sprintf('Paid for %d share(s) but holds no share balance at all.', $shares),
                    id: null,
                );
            }
        }

        return $findings;
    }

    // ----------------------------------------------------------------- status

    /** @return list<Finding> */
    private function paidWithoutPayment(): array
    {
        $rows = DB::table('fee_assigns as fa')
            ->where('fa.status', 'Paid')
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')
                    ->from('payment_info_items as it')
                    ->join('payment_infos as p', 'p.id', '=', 'it.payment_info_id')
                    ->whereColumn('it.fee_assign_id', 'fa.id')
                    ->where('p.status', 'completed');
            })
            ->get(['fa.id', 'fa.member_id', 'fa.period', 'fa.amount']);

        return array_map(fn ($row) => new Finding(
            check: 'PAID_WITHOUT_PAYMENT',
            severity: self::CHECKS['PAID_WITHOUT_PAYMENT'][1],
            subject: $this->memberSubject($row->member_id) . ' / ' . $row->period,
            detail: sprintf('Marked Paid for %s with no completed payment behind it.', $row->amount),
            id: (int) $row->id,
        ), $rows->all());
    }

    /** @return list<Finding> */
    private function paidNotMarked(): array
    {
        $rows = DB::table('fee_assigns as fa')
            ->where('fa.status', '<>', 'Paid')
            ->whereExists(function ($q) {
                $q->selectRaw('1')
                    ->from('payment_info_items as it')
                    ->join('payment_infos as p', 'p.id', '=', 'it.payment_info_id')
                    ->whereColumn('it.fee_assign_id', 'fa.id')
                    ->where('p.status', 'completed');
            })
            ->get(['fa.id', 'fa.member_id', 'fa.period', 'fa.status']);

        return array_map(fn ($row) => new Finding(
            check: 'PAID_NOT_MARKED',
            severity: self::CHECKS['PAID_NOT_MARKED'][1],
            subject: $this->memberSubject($row->member_id) . ' / ' . $row->period,
            detail: sprintf('Paid on a completed payment but still marked %s.', $row->status),
            id: (int) $row->id,
        ), $rows->all());
    }

    /**
     * `period` is denormalised onto the item so a receipt can name the month
     * without a join. Two copies of a fact can disagree, so they are checked.
     *
     * @return list<Finding>
     */
    private function itemPeriodMismatch(): array
    {
        $rows = DB::table('payment_info_items as it')
            ->join('payment_infos as p', 'p.id', '=', 'it.payment_info_id')
            ->join('fee_assigns as fa', 'fa.id', '=', 'it.fee_assign_id')
            ->whereColumn('it.period', '<>', 'fa.period')
            ->get(['it.id', 'p.invoice_no', 'it.period', 'fa.period as assigned_period']);

        return array_map(fn ($row) => new Finding(
            check: 'ITEM_PERIOD_MISMATCH',
            severity: self::CHECKS['ITEM_PERIOD_MISMATCH'][1],
            subject: $row->invoice_no,
            detail: sprintf('Item says %s, its assignment says %s.', $row->period, $row->assigned_period),
            id: (int) $row->id,
        ), $rows->all());
    }

    // --------------------------------------------------------------- hygiene

    /**
     * Whitespace in an invoice number (D-21).
     *
     * Two legacy production rows ended in a tab, which silently broke every
     * exact-match lookup on them - including, embarrassingly, the first version
     * of the tool written to find them, because MySQL's TRIM() strips spaces
     * but not tabs. Hence the explicit character list rather than TRIM().
     *
     * @return list<Finding>
     */
    private function invoiceWhitespace(): array
    {
        $clean = "TRIM(REPLACE(REPLACE(REPLACE(invoice_no, CHAR(9), ''), CHAR(13), ''), CHAR(10), ''))";

        $rows = PaymentInfo::query()
            ->whereRaw("invoice_no <> {$clean}")
            ->get(['id', 'invoice_no']);

        return $this->map($rows, 'INVOICE_WHITESPACE', fn () =>
            'Invoice number carries whitespace, so any exact-match lookup on it will miss this payment.');
    }

    // ----------------------------------------------------------------- shared

    private function completed()
    {
        return PaymentInfo::query()->where('status', 'completed');
    }

    /**
     * Findings keyed by invoice number, which is how staff refer to a payment.
     *
     * @param  \Illuminate\Support\Collection<int, object>  $rows
     * @return list<Finding>
     */
    private function map($rows, string $check, callable $detail): array
    {
        return array_map(fn ($row) => new Finding(
            check: $check,
            severity: self::CHECKS[$check][1],
            subject: (string) $row->invoice_no,
            detail: $detail($row),
            id: (int) $row->id,
        ), $rows->all());
    }

    /** `Rokeya Begum (114)`, falling back to the id if the member is gone. */
    private function memberSubject(int $memberId): string
    {
        $row = DB::table('members as m')
            ->leftJoin('associators_infos as ai', 'ai.member_id', '=', 'm.id')
            ->where('m.id', $memberId)
            ->first(['m.name', 'ai.membership_no']);

        if (! $row) {
            return "member #{$memberId}";
        }

        return $row->membership_no
            ? sprintf('%s (%s)', $row->name, $row->membership_no)
            : (string) $row->name;
    }
}
