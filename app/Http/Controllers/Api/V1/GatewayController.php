<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Tenant\Member;
use App\Models\Tenant\PaymentInfo;
use App\Services\GatewayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use RuntimeException;
use Illuminate\Support\Facades\Log;

class GatewayController extends Controller
{
    public function __construct(private readonly GatewayService $gateway) {}

    /**
     * Start an online payment for a payment that already exists.
     *
     * Two calls, not one, and deliberately: `POST /payments` creates the row,
     * this sends the member to the gateway. A dropped round trip then leaves a
     * reconcilable pending row rather than nothing at all (FR-PAY-7).
     */
    public function startSession(Request $request, int $payment): JsonResponse
    {
        $member = $this->member($request);

        $record = PaymentInfo::find($payment) ?? throw ApiException::notFound('Payment');

        if ($record->member_id !== $member->id) {
            throw ApiException::notOwner('payment');
        }

        try {
            $session = $this->gateway->startSession(
                $record,
                // The association must be in the URL: the gateway sends the
                // member's browser back with no header and no session of ours.
                route('api.gateway.return', [
                    'tenant' => tenant()->getKey(),
                    'payment' => $record->id,
                ]),
            );
        } catch (\DomainException $e) {
            // Our own refusal - "this payment is already completed". The message
            // names the situation, so it goes through as it stands.
            throw new ApiException('GATEWAY_SESSION_REFUSED', $e->getMessage(), 409);
        } catch (ConnectionException $e) {
            /*
             * The gateway could not be reached at all: DNS, TLS, a timeout, a
             * bank with its shutters down.
             *
             * ITS OWN CODE, because it is the one failure where the member
             * should be told plainly that nothing was charged. Left as a 500 it
             * reached them as "Something went wrong. Please try again." - a
             * shrug about their money, for a condition we know exactly.
             *
             * The detail is logged rather than returned. A cURL error naming a
             * certificate path on our server is a fact about our deployment,
             * not something a member can act on, and it is in the log where an
             * operator will look.
             */
            Log::error('Gateway unreachable while starting a session', [
                'tenant' => tenant()?->getKey(),
                'invoice_no' => $record->invoice_no,
                'error' => $e->getMessage(),
            ]);

            throw new ApiException(
                'GATEWAY_UNREACHABLE',
                'We could not reach the payment gateway. Nothing has been charged - '
                    .'please try again in a moment, or pay at the bank instead.',
                503,
            );
        } catch (RuntimeException $e) {
            /*
             * The gateway answered and said no - bad credentials, an AR account
             * it does not recognise, a malformed request.
             *
             * The member gets a clean sentence; the bank's own words go to the
             * log. They are written for whoever integrated the gateway, tend to
             * carry a raw response body, and are not a member's problem.
             */
            Log::error('Gateway refused a session', [
                'tenant' => tenant()?->getKey(),
                'invoice_no' => $record->invoice_no,
                'error' => $e->getMessage(),
            ]);

            throw new ApiException(
                'GATEWAY_SESSION_REFUSED',
                'The payment gateway would not start this payment. Nothing has been charged - '
                    .'please tell your association, or pay at the bank instead.',
                502,
            );
        }

        return response()->json([
            'data' => [
                'url' => $session->url,
                'reference' => $session->reference,
                'expires_at' => $session->expiresAt,
            ],
        ]);
    }

    /**
     * The gateway's server-to-server callback.
     *
     * Not called by the app, and not authenticated by a token - the gateway has
     * no session with us. Authenticity comes from the signature, checked inside
     * GatewayService before anything is acted on. The legacy equivalents accept
     * anything at all (D-16).
     *
     * ALWAYS returns 200 on a payload we recorded, even when we refuse to act on
     * it. Gateways retry non-2xx responses, and a retry storm against a callback
     * we have already stored helps nobody. What we could not process is visible
     * in gateway_events, which is where an operator should look.
     */
    public function webhook(Request $request): JsonResponse
    {
        try {
            $outcome = $this->gateway->handleCallback(
                payload: $request->all(),
                rawBody: $request->getContent(),
                signature: $request->header('X-Gateway-Signature'),
            );

            return response()->json(['data' => ['outcome' => $outcome]]);
        } catch (\DomainException $e) {
            Log::warning('Gateway callback refused', [
                'tenant' => tenant()?->getKey(),
                'reason' => $e->getMessage(),
            ]);

            return response()->json([
                'data' => ['outcome' => 'refused', 'reason' => $e->getMessage()],
            ]);
        }
    }

    /**
     * Where the gateway sends the member's BROWSER back to.
     *
     * Deliberately does nothing but redirect into the app. The member's return
     * is not evidence of anything - the webhook is what completes the payment,
     * and the app polls GET /payments/{id} for the real answer.
     */
    public function returnUrl(Request $request, int $payment): mixed
    {
        return redirect()->away(
            config('app.mobile_deep_link', 'bcsapp://payment').'/'.$payment
        );
    }

    private function member(Request $request): Member
    {
        $account = $request->user();

        abort_unless($account instanceof Member, 403);

        return $account;
    }
}
