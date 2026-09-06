<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Controllers\Controller;
use App\Models\Tenant\PaymentInfo;
use App\Reports\Column;
use App\Reports\ExportsListings;
use App\Reports\Report;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The payment approval queue (FR-PAY-3, FR-PAY-4).
 *
 * Batch approval is where the legacy system breaks outright: the SMS payload
 * overwrites the loop variable, so the second payment in a batch throws a
 * TypeError which the surrounding catch(\Exception) does not catch - the request
 * 500s and the whole transaction rolls back (defect D-3). Approvals of a single
 * payment, or with SMS off, are unaffected, which is why it survived.
 *
 * Here each payment is decided independently and the response reports per-item
 * outcomes, so one failure never silently discards the rest of the batch.
 */
class PaymentApprovalController extends Controller
{
    use ExportsListings;

    public function __construct(private readonly PaymentService $payments) {}

    /**
     * Columns a caller may order by. A whitelist, because `?sort=` reaching
     * orderBy unchecked is an injection point.
     */
    private const SORTABLE = [
        'invoice_no' => 'payment_infos.invoice_no',
        'member_name' => 'members.name',
        'payable_amount' => 'payment_infos.payable_amount',
        'fine_amount' => 'payment_infos.fine_amount',
        'total_amount' => 'payment_infos.total_amount',
        'submitted' => 'payment_infos.created_at',
    ];

    public function pending(Request $request): JsonResponse
    {
        $payments = $this->queue($request)
            ->paginate(min((int) $request->query('per_page', 25), 100));

        /*
         * Totals across the WHOLE queue, not this page.
         *
         * A separate aggregate query rather than a sum of what was paginated:
         * summing the page would produce a figure that changes as you page
         * through, which is worse than no figure at all.
         *
         * The server does this arithmetic because the app is barred from it -
         * and FR-REP-8 wants the screen and the downloaded file to agree, which
         * they cannot if only one of them has totals.
         */
        $totalsQuery = $this->queue($request);

        /*
         * The listing's own SELECT and ORDER BY have to go before the
         * aggregate.
         *
         * queue() selects payment_infos.* for the table; leaving that in place
         * puts non-aggregated columns beside SUM() and MySQL refuses the whole
         * query under only_full_group_by. The eager loads go too - there is no
         * row here to load relations for, just three numbers.
         */
        $totalsQuery->getQuery()->columns = null;
        $totalsQuery->setEagerLoads([]);

        $totals = $totalsQuery
            ->reorder()
            ->selectRaw('COALESCE(SUM(payment_infos.payable_amount), 0) as instalments')
            ->selectRaw('COALESCE(SUM(payment_infos.fine_amount), 0) as fines')
            ->selectRaw('COALESCE(SUM(payment_infos.total_amount), 0) as total')
            ->first();

        return response()->json([
            'data' => $payments->getCollection()->map(fn (PaymentInfo $p) => $this->shape($p)),
            'meta' => [
                'current_page' => $payments->currentPage(),
                'total' => $payments->total(),
                'last_page' => $payments->lastPage(),

                // Instalments and fines apart, never added together here -
                // the app is given both and adds neither (FR-REP-3).
                'instalments_amount' => $this->money($totals->instalments ?? 0),
                'fines_amount' => $this->money($totals->fines ?? 0),
                'total_amount' => $this->money($totals->total ?? 0),
            ],
        ]);
    }

    /**
     * The approvals queue as a file (FR-REP-7).
     *
     * Worth having as a paper trail rather than as a convenience: an approver
     * signing off a batch of payments is making a decision about other
     * people's money, and being able to hand a committee the list they acted
     * on - with the instalment and the fine shown apart - is the point.
     */
    public function exportPending(Request $request): Response
    {
        $format = $this->exportFormat($request);
        $filters = $this->queueFilters($request);

        $rows = $this->queue($request)->get()->map(function (PaymentInfo $p) {
            $shaped = $this->shape($p);

            return [
                'invoice_no' => $shaped['invoice_no'],
                'membership_no' => $shaped['membership_no'],
                'member_name' => $shaped['member_name'],
                'payment_type' => $shaped['payment_type'],
                'instalment_count' => $shaped['instalment_count'],
                'payable_amount' => $shaped['payable_amount'],
                'fine_amount' => $shaped['fine_amount'],
                'total_amount' => $shaped['total_amount'],
                'document_count' => $shaped['document_count'],
                'submitted' => $p->created_at?->toDateString() ?? '',
            ];
        })->all();

        if (($tooLarge = $this->rejectIfTooLarge($rows)) !== null) {
            return $tooLarge;
        }

        $instalments = '0.00';
        $fines = '0.00';
        $total = '0.00';
        $count = 0;

        foreach ($rows as $row) {
            $instalments = bcadd($instalments, (string) $row['payable_amount'], 2);
            $fines = bcadd($fines, (string) $row['fine_amount'], 2);
            $total = bcadd($total, (string) $row['total_amount'], 2);
            $count += (int) $row['instalment_count'];
        }

        return $this->sendExport(
            new Report(
                title: 'Payments awaiting approval',
                association: $this->associationName(),
                columns: [
                    new Column('invoice_no', 'Invoice'),
                    new Column('membership_no', 'No.'),
                    new Column('member_name', 'Member'),
                    new Column('payment_type', 'Method'),
                    new Column('instalment_count', 'Instalments', Column::TYPE_INTEGER, (string) $count),
                    /*
                     * Instalment and fine stay apart here exactly as they do on
                     * screen (FR-REP-3). A single "amount" column on an
                     * approvals list is how an approver ends up unable to say
                     * how much of what they signed off was penalty.
                     */
                    new Column('payable_amount', 'Instalments', Column::TYPE_MONEY, $instalments),
                    new Column('fine_amount', 'Fines', Column::TYPE_MONEY, $fines),
                    new Column('total_amount', 'Total', Column::TYPE_MONEY, $total),
                    new Column('document_count', 'Slips', Column::TYPE_INTEGER),
                    new Column('submitted', 'Submitted'),
                ],
                rows: $rows,
                filters: array_filter([
                    'Submitted' => $this->describePeriod($filters['from'], $filters['to'], 'Any date'),
                    'Member' => $filters['q'],
                ]),
                currency: $this->currency(),
            ),
            $format,
        );
    }

    /** The queue as both the screen and the download see it. */
    private function queue(Request $request): Builder
    {
        $filters = $this->queueFilters($request);
        $sort = $this->sortFrom($request, self::SORTABLE);

        $query = PaymentInfo::query()
            ->select('payment_infos.*')
            ->with(['member:id,name', 'member.associatorInfo:id,member_id,membership_no', 'items'])
            ->where('payment_infos.status', PaymentInfo::STATUS_PENDING)
            /*
             * NOT payments that are with a bank (ADR-0007).
             *
             * A `gateway_reference` means this payment was handed to a gateway
             * and the member is - or was - on the bank's page. Its outcome is
             * the bank's to report: the callback completes it, `payments:
             * reconcile` asks every ten minutes in case the callback is lost,
             * and `payments:expire-intents` releases it if it never resolves.
             *
             * Approval is for money a human confirmed arriving. Here nobody has
             * confirmed anything yet, and approving it would mark the dues paid
             * and post the ledger for money that may never have been taken.
             * Listing it at all invites exactly that on a queue worked quickly.
             */
            ->whereNull('payment_infos.gateway_reference')
            // When the member submitted it - the only date on a pending
            // payment, since it has not been approved or paid yet.
            ->when($filters['from'], fn ($q, $d) => $q->whereDate('payment_infos.created_at', '>=', $d))
            ->when($filters['to'], fn ($q, $d) => $q->whereDate('payment_infos.created_at', '<=', $d))
            /*
             * By member name, membership number or invoice.
             *
             * Subqueries rather than joins: this builder is also joined to
             * `members` when sorting by name, and joining the same table twice
             * is an error rather than a duplicate.
             */
            ->when($filters['q'], function ($q, $term) {
                $q->where(function ($inner) use ($term) {
                    $inner->where('payment_infos.invoice_no', 'like', "%{$term}%")
                        ->orWhereExists(function ($exists) use ($term) {
                            $exists->selectRaw('1')
                                ->from('members as sm')
                                ->whereColumn('sm.id', 'payment_infos.member_id')
                                ->where('sm.name', 'like', "%{$term}%");
                        })
                        ->orWhereExists(function ($exists) use ($term) {
                            $exists->selectRaw('1')
                                ->from('associators_infos as sai')
                                ->whereColumn('sai.member_id', 'payment_infos.member_id')
                                ->where('sai.membership_no', 'like', "%{$term}%");
                        });
                });
            });

        if ($sort !== null && str_starts_with($sort['column'], 'members.')) {
            $query->leftJoin('members', 'members.id', '=', 'payment_infos.member_id');
        }

        return $sort === null
            // Oldest first by default: the queue is worked from the front, and
            // the member who has waited longest should be seen first.
            ? $query->orderBy('payment_infos.created_at')
            : $query->orderBy($sort['column'], $sort['direction']);
    }

    /** @return array{from: ?string, to: ?string, q: ?string} */
    private function queueFilters(Request $request): array
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

    /** @return array<string, string|int|null> */
    private function shape(PaymentInfo $p): array
    {
        return [
            'id' => $p->id,
            'invoice_no' => $p->invoice_no,
            'member_id' => $p->member_id,
            'member_name' => $p->member->name,
            // How the office identifies the member, and what an approver has
            // in front of them when checking a slip against a record.
            'membership_no' => $p->member->associatorInfo?->membership_no ?? '',
            'payment_type' => $p->payment_type,

            // Instalments and fine reported apart, so an approver can see
            // what they are actually approving.
            'payable_amount' => (string) $p->payable_amount,
            'fine_amount' => (string) $p->fine_amount,
            'total_amount' => (string) $p->total_amount,

            'instalment_count' => $p->items->count(),

            // How many slips the member attached, so an approver can see at
            // a glance whether there is anything to approve AGAINST. With no
            // gateway, a manual payment with no document is a claim, not
            // evidence.
            'document_count' => count($p->documents ?? []),
            'created_at' => $p->created_at?->toIso8601String(),
            'expires_at' => $p->expires_at?->toIso8601String(),
        ];
    }

    /**
     * Approve or suspend a batch.
     *
     * Each payment succeeds or fails on its own. A partial batch returns 207
     * with per-payment outcomes rather than pretending the whole thing worked
     * or discarding the successes.
     */
    public function decide(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'payment_ids' => ['required', 'array', 'min:1'],
            'payment_ids.*' => ['integer'],
            'decision' => ['required', 'in:completed,suspended'],
            'reason' => ['required_if:decision,suspended', 'nullable', 'string', 'max:1000'],
            'ledger_id' => ['sometimes', 'nullable', 'integer', 'exists:ledgers,id'],
        ]);

        $results = [];

        foreach ($validated['payment_ids'] as $id) {
            $payment = PaymentInfo::find($id);

            if (! $payment) {
                $results[] = ['payment_id' => $id, 'ok' => false, 'error' => 'Not found.'];

                continue;
            }

            /*
             * The list above hides these; this refuses them. The screen is a
             * courtesy, and an id can be posted without it - stale tab, a
             * export worked from, a script.
             *
             * Suspending is refused too, and for the same reason in reverse: a
             * payment released here while the bank is still processing it would
             * be completed by the callback afterwards, out of a status nobody
             * expected it to leave.
             */
            if ($payment->gateway_reference !== null && $payment->isPending()) {
                $results[] = [
                    'payment_id' => $id,
                    'ok' => false,
                    'error' => "{$payment->invoice_no} is with the bank. It completes when the bank "
                        .'confirms it, and is released automatically if it never does - deciding it '
                        .'here would record money that may never have been taken.',
                ];

                continue;
            }

            try {
                if ($validated['decision'] === PaymentInfo::STATUS_COMPLETED) {
                    $this->payments->complete(
                        $payment,
                        $validated['ledger_id'] ?? $payment->ledger_id,
                        $request->user()->id,
                    );
                } else {
                    $this->payments->suspend($payment, $validated['reason'], $request->user()->id);
                }

                $results[] = ['payment_id' => $id, 'ok' => true, 'status' => $payment->fresh()->status];
            } catch (\Throwable $e) {
                // Contained. The legacy failure mode is one bad payment taking
                // the entire batch down with it.
                $results[] = ['payment_id' => $id, 'ok' => false, 'error' => $e->getMessage()];
            }
        }

        $failed = collect($results)->where('ok', false)->count();

        return response()->json([
            'data' => [
                'decided' => count($results) - $failed,
                'failed' => $failed,
                'results' => $results,
            ],
        ], $failed > 0 ? 207 : 200);
    }
}
