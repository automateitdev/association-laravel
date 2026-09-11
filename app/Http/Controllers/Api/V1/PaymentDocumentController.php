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
 * same shape for each: a member reaches their OWN payment's documents, and
 * staff reach any payment's. Splitting it would mean two places to get that
 * wrong.
 *
 * WHAT STAFF NEED DIFFERS BY DIRECTION. Reading a slip is `payments.view` -
 * an approver has to see what they are deciding on. ATTACHING one is
 * `payments.approve`, because it is evidence a decision will rest on, and a
 * read-only account filing it is not a thing anybody asked for.
 */
class PaymentDocumentController extends Controller
{
    public function __construct(private readonly PaymentDocumentService $documents) {}

    /**
     * Attach FURTHER documents to a payment that is still awaiting approval.
     *
     * This used to say a member who paid on Tuesday and photographed the slip
     * on Wednesday should not have to recreate the payment - written when a
     * manual payment could be filed with nothing attached. It cannot any more:
     * the slip is required at creation, as it is in the legacy system, because
     * "I paid, approve it" with no proof is a request rather than a record.
     *
     * So this is for what comes AFTER the first one - the back of the slip, a
     * stamped copy the bank gave them the next day, or an approver filing what
     * a member brought to the counter.
     */
    public function store(Request $request, int $payment): JsonResponse
    {
        /*
         * ATTACHING IS NOT READING, and this used to be gated as though it
         * were. The docblock above has always said staff reach these "if they
         * may APPROVE payments"; the code asked for `payments.view`, so any
         * read-only account could file evidence against a payment somebody else
         * was about to decide on. The doc was right and the code was looser.
         */
        $record = $this->authorisePayment($request, $payment, 'payments.approve');

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
    /**
     * @param  string  $permission  what a STAFF account needs. A member's own
     *                              payment is reached by owning it either way.
     */
    private function authorisePayment(
        Request $request,
        int $paymentId,
        string $permission = 'payments.view',
    ): PaymentInfo {
        $payment = PaymentInfo::find($paymentId) ?? throw ApiException::notFound('Payment');
        $account = $request->user();

        if ($account instanceof Member) {
            if ($payment->member_id !== $account->id) {
                throw ApiException::notOwner('payment');
            }

            return $payment;
        }

        if ($account instanceof User && $account->can($permission)) {
            return $payment;
        }

        throw ApiException::insufficientPermission($permission);
    }
}
