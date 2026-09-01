<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Controllers\Controller;
use App\Models\Tenant\PaymentInfo;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
    public function __construct(private readonly PaymentService $payments) {}

    public function pending(Request $request): JsonResponse
    {
        $payments = PaymentInfo::query()
            ->with(['member:id,name', 'items'])
            ->where('status', PaymentInfo::STATUS_PENDING)
            ->orderBy('created_at')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return response()->json([
            'data' => $payments->getCollection()->map(fn (PaymentInfo $p) => [
                'id' => $p->id,
                'invoice_no' => $p->invoice_no,
                'member_id' => $p->member_id,
                'member_name' => $p->member->name,
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
            ]),
            'meta' => [
                'current_page' => $payments->currentPage(),
                'total' => $payments->total(),
                'last_page' => $payments->lastPage(),
            ],
        ]);
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
