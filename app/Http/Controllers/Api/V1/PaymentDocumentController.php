<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Tenant\Member;
use App\Models\Tenant\PaymentInfo;
use App\Models\User;
use App\Services\PaymentDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Proof-of-payment documents.
 *
 * Serves both audiences from one place, because the authorisation rule is the
 * same shape for each: a member reaches their OWN payment's documents, staff
 * reach any payment's documents if they may approve payments. Splitting it
 * would mean two places to get that wrong.
 */
class PaymentDocumentController extends Controller
{
    public function __construct(private readonly PaymentDocumentService $documents) {}

    /**
     * Attach documents to a payment that is still awaiting approval.
     *
     * Separate from payment creation on purpose: a member who paid at the bank
     * on Tuesday and photographed the slip on Wednesday should not have to
     * cancel and recreate the payment.
     */
    public function store(Request $request, int $payment): JsonResponse
    {
        $record = $this->authorisePayment($request, $payment);

        if (! $record->isPending()) {
            throw ApiException::conflict(
                'PAYMENT_NOT_PENDING',
                'Documents can only be added while a payment is awaiting approval.'
            );
        }

        $request->validate([
            'documents' => ['required', 'array', 'min:1'],
            'documents.*' => ['required', 'file'],
        ]);

        try {
            $this->documents->attach($record, $request->file('documents'));
        } catch (\DomainException $e) {
            throw new ApiException('DOCUMENT_REJECTED', $e->getMessage(), 422);
        }

        return response()->json([
            'data' => ['documents' => $this->documents->list($record->fresh())],
        ], 201);
    }

    public function index(Request $request, int $payment): JsonResponse
    {
        $record = $this->authorisePayment($request, $payment);

        return response()->json(['data' => $this->documents->list($record)]);
    }

    /**
     * Stream a document.
     *
     * Streamed through the application rather than exposed by URL, because the
     * file sits on a non-public tenant-suffixed path and the authorisation
     * decision belongs here, not in a link somebody might forward (NFR-SEC-4).
     */
    public function show(Request $request, int $payment, int $index): StreamedResponse
    {
        $record = $this->authorisePayment($request, $payment);

        try {
            $document = $this->documents->locate($record, $index);
        } catch (\DomainException $e) {
            throw ApiException::notFound('Document');
        }

        // The recorded disk, not a constant: since ADR-0011 a document may be
        // on S3 or on local storage depending on which took it.
        return Storage::disk($document['disk'])->response(
            $document['path'],
            $document['name'],
            [
                'Content-Type' => $document['mime'],

                // A bank slip is not something a browser should cache to disk
                // on a shared machine at the association office.
                'Cache-Control' => 'private, no-store',
            ],
        );
    }

    /**
     * Members reach their own payments; staff reach any, if they may approve.
     */
    private function authorisePayment(Request $request, int $paymentId): PaymentInfo
    {
        $payment = PaymentInfo::find($paymentId) ?? throw ApiException::notFound('Payment');
        $account = $request->user();

        if ($account instanceof Member) {
            if ($payment->member_id !== $account->id) {
                throw ApiException::notOwner('payment');
            }

            return $payment;
        }

        if ($account instanceof User && $account->can('payments.view')) {
            return $payment;
        }

        throw ApiException::insufficientPermission('payments.view');
    }
}
