<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Tenant\Member;
use App\Models\Tenant\PaymentInfo;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    public function index(Request $request): JsonResponse
    {
        $member = $this->member($request);

        $payments = PaymentInfo::query()
            ->where('member_id', $member->id)
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('id')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return response()->json([
            'data' => $payments->getCollection()->map(fn (PaymentInfo $p) => $this->shape($p)),
            'meta' => [
                'current_page' => $payments->currentPage(),
                'per_page' => $payments->perPage(),
                'total' => $payments->total(),
                'last_page' => $payments->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, int $payment): JsonResponse
    {
        $member = $this->member($request);

        $record = PaymentInfo::query()->with('items.feeAssign.feeSetup')->find($payment);

        if (! $record) {
            throw ApiException::notFound('Payment');
        }

        // Ownership, checked on the row rather than trusted from the query.
        // A 403 rather than a 404 is deliberate here: the member asked about a
        // specific id, and pretending it does not exist helps nobody.
        if ($record->member_id !== $member->id) {
            throw ApiException::notOwner('payment');
        }

        return response()->json(['data' => $this->shape($record, withItems: true)]);
    }

    /**
     * Create a payment. Requires an Idempotency-Key (FR-PAY-6).
     *
     * A flaky mobile connection retrying this call is the single most likely
     * source of NEW duplicate charges once an app exists - which is precisely
     * the problem the legacy audit report exists to detect after the fact.
     */
    public function store(Request $request): JsonResponse
    {
        $member = $this->member($request);

        $validated = $request->validate([
            'fee_assign_ids' => ['required', 'array', 'min:1'],
            'fee_assign_ids.*' => ['required', 'integer'],
            'payment_type' => ['sometimes', 'in:manual,online'],
            'ledger_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $key = trim((string) $request->header('Idempotency-Key'));

        if ($key === '') {
            throw new ApiException(
                'IDEMPOTENCY_KEY_REQUIRED',
                'An Idempotency-Key header is required when creating a payment.',
                400,
            );
        }

        $requestHash = hash('sha256', json_encode([
            'member' => $member->id,
            'assigns' => collect($validated['fee_assign_ids'])->sort()->values()->all(),
            'type' => $validated['payment_type'] ?? PaymentInfo::TYPE_MANUAL,
        ]));

        $existing = DB::table('idempotency_keys')->where('key', $key)->first();

        if ($existing) {
            // Same key, different body: the client has a bug, and silently
            // returning the old payment would hide it.
            if ($existing->request_hash !== $requestHash) {
                throw ApiException::idempotencyKeyReused();
            }

            // Same key, same body: return the ORIGINAL payment. No second charge.
            $original = PaymentInfo::with('items')->find($existing->payment_info_id);

            return response()->json(['data' => $this->shape($original, withItems: true)], 200);
        }

        try {
            $payment = $this->payments->create(
                $member->id,
                $validated['fee_assign_ids'],
                $validated['payment_type'] ?? PaymentInfo::TYPE_MANUAL,
                $validated['ledger_id'] ?? null,
            );
        } catch (\DomainException $e) {
            throw $this->translate($e);
        }

        DB::table('idempotency_keys')->insert([
            'key' => $key,
            'member_id' => $member->id,
            'endpoint' => 'POST /api/v1/payments',
            'request_hash' => $requestHash,
            'payment_info_id' => $payment->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['data' => $this->shape($payment, withItems: true)], 201);
    }

    // ---- internals -----------------------------------------------------

    /**
     * Domain refusals become API codes the app can branch on, rather than a
     * generic 500 with a message nobody can act upon.
     */
    private function translate(\DomainException $e): ApiException
    {
        $message = $e->getMessage();

        return match (true) {
            str_contains($message, 'does not belong to member') => ApiException::notOwner('instalment'),
            str_contains($message, 'already paid') => ApiException::conflict(
                'ASSIGN_ALREADY_SETTLED',
                'One of those instalments has already been paid.'
            ),
            str_contains($message, 'awaiting confirmation') => ApiException::conflict(
                'ASSIGN_ALREADY_PENDING',
                'One of those instalments already has a payment awaiting confirmation.'
            ),
            default => new ApiException('PAYMENT_REFUSED', $message, 422),
        };
    }

    private function shape(PaymentInfo $payment, bool $withItems = false): array
    {
        $data = [
            'id' => $payment->id,
            'invoice_no' => $payment->invoice_no,
            'status' => $payment->status,
            'payment_type' => $payment->payment_type,

            // Instalments only. The gateway figure lives in its own field and is
            // never folded in here (defect D-1).
            'payable_amount' => (string) $payment->payable_amount,
            'fine_amount' => (string) $payment->fine_amount,
            'total_amount' => (string) $payment->total_amount,

            'payment_date' => $payment->payment_date?->toDateString(),
            'expires_at' => $payment->expires_at?->toIso8601String(),
        ];

        if ($withItems) {
            $data['items'] = $payment->items->map(fn ($item) => [
                'fee_assign_id' => $item->fee_assign_id,
                'period' => $item->period,
                'instalment_amount' => (string) $item->amount,
                'fine_amount' => (string) $item->fine_amount,
            ])->all();
        }

        return $data;
    }

    private function member(Request $request): Member
    {
        $account = $request->user();

        abort_unless($account instanceof Member, 403);

        return $account;
    }
}
