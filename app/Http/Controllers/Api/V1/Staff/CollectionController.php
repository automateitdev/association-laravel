<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Staff;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Tenant\FeeAssign;
use App\Models\Tenant\Member;
use App\Models\Tenant\PaymentInfo;
use App\Reports\InvoiceRenderer;
use App\Services\PaymentDocumentService;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Taking money at the counter (FR-FEE-9, "Quick Collection" in the legacy).
 *
 * WHY THIS EXISTS
 * ---------------
 * Until now only a MEMBER could create a payment - `POST /api/v1/payments` is
 * gated on the `member.payments.create` ability. At a cooperative society most
 * members pay cash across a desk, so without this the platform could not take
 * the majority of an association's money. It is the one gap that stopped the
 * system running an office for a day.
 *
 * IT DOES NOT COMPLETE THE PAYMENT, AND THAT IS DELIBERATE
 * -------------------------------------------------------
 * FR-PAY-2: manual payments are created `pending`, moving their assignments to
 * `Requested`. Approval is a separate act (FR-PAY-3) which is what posts the
 * ledger entries and credits shares.
 *
 * It would be easy to argue the opposite - the cash is in the drawer, why make
 * someone approve it - and the legacy system agrees with the spec here: an
 * admin-created payment is written as `pending` exactly like a member's. That
 * separation is the association's cash control. The person who takes the money
 * is not the person who confirms it was taken, and collapsing the two would
 * remove a check that exists precisely because this is other people's money.
 *
 * So this creates the payment. The approvals queue does the rest, unchanged.
 */
class CollectionController extends Controller
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly PaymentDocumentService $documents,
    ) {}

    /**
     * What this member currently owes, for the collection screen.
     *
     * The member-facing `/fees/dues` answers the same question for whoever is
     * signed in. Staff need it for somebody else, so this is the same shape
     * addressed by member id - the app renders one component either way.
     */
    public function dues(int $member): JsonResponse
    {
        $record = Member::find($member);

        if (! $record) {
            throw new ApiException('NOT_FOUND', 'No such member.', 404);
        }

        $assigns = FeeAssign::query()
            ->with('feeSetup:id,fee_head')
            ->where('member_id', $record->id)
            ->outstanding()
            ->orderBy('period')
            ->get();

        $instalmentTotal = $assigns->reduce(
            fn (string $carry, FeeAssign $a) => bcadd($carry, (string) $a->amount, 2),
            '0.00'
        );
        $fineTotal = $assigns->reduce(
            fn (string $carry, FeeAssign $a) => bcadd($carry, (string) $a->fine_amount, 2),
            '0.00'
        );

        return response()->json([
            'data' => $assigns->map(fn (FeeAssign $assign) => [
                'fee_assign_id' => $assign->id,
                'fee_head' => $assign->feeSetup->fee_head,
                'period' => $assign->period,

                // Never merged. A single "amount" field would be the legacy bug
                // in a new coat.
                'instalment_amount' => (string) $assign->amount,
                'fine_amount' => (string) $assign->fine_amount,

                // Computed here so the app never adds money.
                'total_due' => $assign->totalDue(),
                'status' => $assign->status,
            ]),
            'meta' => [
                'member_id' => $record->id,
                'member_name' => $record->name,
                'membership_no' => $record->associatorInfo?->membership_no,
                'member_status' => $record->status,
                'instalment_total' => $instalmentTotal,
                'fine_total' => $fineTotal,
                'grand_total' => bcadd($instalmentTotal, $fineTotal, 2),
            ],
        ]);
    }

    /**
     * Record a counter payment on a member's behalf.
     *
     * Idempotent on the same terms as the member endpoint, and for the same
     * reason: a staff member whose connection drops mid-submit will press the
     * button again, and a cooperative cannot afford to record that as two
     * payments. The key is scoped to the endpoint so a member's key and a
     * collection key cannot collide.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'member_id' => ['required', 'integer'],
            'fee_assign_ids' => ['required', 'array', 'min:1'],
            'fee_assign_ids.*' => ['required', 'integer'],

            /*
             * The account the money went INTO - the cash box, or the bank.
             *
             * REQUIRED, and it was optional in the first version of this file.
             * That was wrong in a way that only showed up two steps later:
             * a payment created without one is accepted happily and then
             * CANNOT BE APPROVED - `PaymentService::complete` refuses with
             * "has no receiving ledger; cannot post", so the money sits in the
             * queue and nobody can tell from the collection screen why.
             *
             * The approver can supply one instead, which is why this is not
             * fatal by design. But the person who knows which drawer the cash
             * went into is the person at the counter, not whoever clears the
             * queue afterwards - so it is asked for where it is known.
             */
            'ledger_id' => ['required', 'integer', 'exists:ledgers,id'],

            /*
             * The slip, when there is one - OPTIONAL, where a member's is
             * required, and the legacy splits it exactly this way.
             *
             * The asymmetry is not an oversight. A member filing a manual
             * payment is ASSERTING that money left their account, and the slip
             * is the only thing an approver has to check that against. A clerk
             * recording a collection took the money themselves; their word is
             * the record, and the association's own audit trail says who they
             * are. Often there IS a slip - somebody paid at the bank and
             * brought it to the counter - and until now there was nowhere to
             * put it at the moment it was in the clerk's hand.
             */
            'documents' => ['sometimes', 'array', 'max:'.PaymentDocumentService::MAX_PER_PAYMENT],
            'documents.*' => ['file'],
        ]);

        $key = trim((string) $request->header('Idempotency-Key'));

        if ($key === '') {
            throw new ApiException(
                'IDEMPOTENCY_KEY_REQUIRED',
                'An Idempotency-Key header is required when recording a collection.',
                400,
            );
        }

        $requestHash = hash('sha256', json_encode([
            'member' => $validated['member_id'],
            'assigns' => collect($validated['fee_assign_ids'])->sort()->values()->all(),
        ]));

        $existing = DB::table('idempotency_keys')->where('key', $key)->first();

        if ($existing) {
            // Same key, different body: the client has a bug, and silently
            // returning the old payment would hide it.
            if ($existing->request_hash !== $requestHash) {
                throw ApiException::idempotencyKeyReused();
            }

            // Same key, same body: the ORIGINAL payment. No second collection.
            return response()->json([
                'data' => $this->shape(PaymentInfo::with('items')->find($existing->payment_info_id)),
            ]);
        }

        try {
            $payment = $this->payments->create(
                $validated['member_id'],
                $validated['fee_assign_ids'],
                // Cash at a counter is a manual payment. Nothing here goes near
                // the gateway.
                PaymentInfo::TYPE_MANUAL,
                $validated['ledger_id'],
                // WHO took the money. The member endpoint leaves this null, so
                // a payment's origin is readable from the record itself rather
                // than inferred from what it looks like.
                $request->user()?->id,
            );
        } catch (\DomainException $e) {
            throw new ApiException('VALIDATION_FAILED', $e->getMessage(), 422);
        }

        // Before the idempotency key is recorded, so a rejected file leaves no
        // collection behind and the same key and body can be retried - the same
        // ordering the member endpoint uses.
        if ($request->hasFile('documents')) {
            try {
                $this->documents->attach($payment, $request->file('documents'));
            } catch (\DomainException $e) {
                throw new ApiException('DOCUMENT_REJECTED', $e->getMessage(), 422);
            }
        }

        DB::table('idempotency_keys')->insert([
            'key' => $key,
            'member_id' => $validated['member_id'],
            'endpoint' => 'POST /api/v1/staff/collections',
            'request_hash' => $requestHash,
            'payment_info_id' => $payment->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['data' => $this->shape($payment->fresh('items'))], 201);
    }

    /**
     * The receipt for a completed payment (FR-PAY-14).
     *
     * COMPLETED ONLY, and refusing the others is the point. A receipt says the
     * association has the money; issuing one for a payment still sitting in the
     * approvals queue would hand a member proof of something that has not
     * happened, and which may yet be rejected.
     */
    public function invoice(int $payment, InvoiceRenderer $renderer): Response
    {
        $record = PaymentInfo::with(['member.associatorInfo', 'items.feeAssign.feeSetup', 'ledger'])
            ->find($payment);

        if (! $record) {
            throw new ApiException('NOT_FOUND', 'No such payment.', 404);
        }

        if ($record->status !== PaymentInfo::STATUS_COMPLETED) {
            throw new ApiException(
                'PAYMENT_NOT_COMPLETED',
                'A receipt is only available once the payment has been approved.',
                422,
            );
        }

        return $renderer->render(
            $record,
            (string) (tenant()->name ?? tenant()->getKey()),
            (string) (tenant()->currency ?? 'BDT'),
        );
    }

    /** @return array<string, mixed> */
    private function shape(PaymentInfo $payment): array
    {
        return [
            'id' => $payment->id,
            'invoice_no' => $payment->invoice_no,
            'member_id' => $payment->member_id,
            'status' => $payment->status,
            'payment_type' => $payment->payment_type,

            // Apart, always (FR-MON-1). The collector needs to be able to say
            // how much of what they took was penalty.
            'payable_amount' => (string) $payment->payable_amount,
            'fine_amount' => (string) $payment->fine_amount,
            'total_amount' => (string) $payment->total_amount,

            'instalment_count' => $payment->items->count(),
            'created_at' => $payment->created_at?->toIso8601String(),
        ];
    }
}
