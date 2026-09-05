<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\GatewayService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Where PayFlex tells us an invoice is worth asking about (FR-PAY-11).
 *
 * TWO PATHS, ONE MEANING. PayFlex posts to `/api/pay-flex/notify` when SPG
 * notifies it, and retries to `/api/pay-flex/verify` if that first post failed.
 * They carry the same thing and are handled identically here; the difference is
 * PayFlex's retry bookkeeping, not ours.
 *
 * THE BODY IS A DOORBELL. PayFlex signs nothing - it posts with
 * `Http::post($url, $payload)` and no shared secret - so the ONLY thing taken
 * from the request is an invoice number. Not the amount, not the status, not the
 * transaction id. Everything that decides whether a member has paid comes from
 * asking PayFlex back over an authenticated request.
 *
 * An attacker who guesses this URL and posts an invoice number therefore
 * achieves exactly one thing: we ask the gateway about a payment we already
 * have, which the reconciliation sweep would have done anyway. That is the whole
 * of the exposure, and it is why this endpoint can exist without a signature
 * while the legacy system's unauthenticated callback (D-16) could not.
 *
 * THE ASSOCIATION COMES FROM THE HOST. PayFlex builds this URL by stripping
 * everything from `/api/` off the `callback_url` we gave it and appending its
 * own path, so a tenant in the path cannot survive the round trip the way it
 * does for `/webhooks/{tenant}/gateway`. `ResolveTenant` reads the host against
 * the `domains` table instead - which means an association using PayFlex MUST
 * have a working domain row, and a deployment serving every association from one
 * bare host cannot use this route.
 */
class PayflexCallbackController extends Controller
{
    public function __construct(private readonly GatewayService $gateway) {}

    public function __invoke(Request $request): JsonResponse
    {
        /*
         * Several spellings, because two different senders reach this URL: SPG's
         * own IPN shape (`InvoiceNo`) travels through PayFlex, and PayFlex's
         * verification payload nests the same field under `data`.
         */
        $invoice = $request->input('data.InvoiceNo')
            ?? $request->input('InvoiceNo')
            ?? $request->input('invoice');

        if (! $invoice) {
            /*
             * 200, not 400. PayFlex retries a failed callback on a queue, and a
             * body with no invoice in it will not become one by being sent
             * again - it would retry forever against a request nobody can act
             * on. Recorded and accepted.
             */
            Log::warning('PayFlex notice carried no invoice', [
                'tenant' => tenant()?->getKey(),
                'keys' => array_keys($request->all()),
            ]);

            return response()->json(['data' => ['outcome' => 'ignored', 'reason' => 'No invoice in the notice.']]);
        }

        try {
            $outcome = $this->gateway->handleUnsignedNotice((string) $invoice, $request->all());

            return response()->json(['data' => ['outcome' => $outcome]]);
        } catch (DomainException $e) {
            Log::warning('PayFlex notice refused', [
                'tenant' => tenant()?->getKey(),
                'invoice' => $invoice,
                'reason' => $e->getMessage(),
            ]);

            // Also 200, and for the same reason: an invoice we never issued
            // will not start existing on the fourth retry.
            return response()->json([
                'data' => ['outcome' => 'refused', 'reason' => $e->getMessage()],
            ]);
        }
    }
}
