<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Every API failure, in one envelope.
 *
 * The app branches on `code` and displays `message`. It never parses `message`,
 * so wording can change without breaking a released client - which matters more
 * than usual here, because members update on their own schedule and an old build
 * stays in the field for months.
 *
 * Adding a failure mode means adding a code here AND teaching the app to handle
 * it. See bcs-docs/05-api-contract.md section 1.4 for the catalogue.
 */
class ApiException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 400,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        $payload = [
            'error' => array_filter([
                'code' => $this->errorCode,
                'message' => $this->getMessage(),
                'details' => $this->details ?: null,
                'request_id' => request()->header('X-Request-Id'),
            ], fn ($value) => $value !== null),
        ];

        return response()->json($payload, $this->status);
    }

    // ---- tenancy -------------------------------------------------------

    public static function tenantNotResolved(): self
    {
        return new self(
            'TENANT_NOT_RESOLVED',
            'No association was identified for this request.',
            400,
        );
    }

    public static function tenantSuspended(string $status): self
    {
        return new self(
            'TENANT_SUSPENDED',
            'This association is not currently active. Please contact the association office.',
            403,
            ['tenant_status' => $status],
        );
    }

    // ---- authentication ------------------------------------------------

    public static function invalidCredentials(): self
    {
        return new self(
            'INVALID_CREDENTIALS',
            'The mobile number or password is incorrect.',
            401,
        );
    }

    /**
     * Deliberately distinct from suspension. "Waiting for approval" and "you owe
     * money" are entirely different situations for the member, and one shared
     * message sends both of them to the office to ask which (FR-AUTH-3).
     */
    public static function memberInactive(): self
    {
        return new self(
            'MEMBER_INACTIVE',
            'Your membership is awaiting approval by the association office.',
            403,
        );
    }

    public static function memberSuspended(int $overduePeriods = 0): self
    {
        return new self(
            'MEMBER_SUSPENDED',
            'Your membership is suspended for overdue instalments. Please contact the association office.',
            403,
            $overduePeriods > 0 ? ['overdue_periods' => $overduePeriods] : [],
        );
    }

    // ---- authorisation -------------------------------------------------

    public static function insufficientPermission(string $permission): self
    {
        return new self(
            'INSUFFICIENT_PERMISSION',
            'You do not have permission to do that.',
            403,
            ['required' => $permission],
        );
    }

    /**
     * The member referenced something that is not theirs. This is defect D-5's
     * error code - in the legacy system the request simply succeeds.
     */
    public static function notOwner(string $what = 'resource'): self
    {
        return new self(
            'NOT_OWNER',
            "That {$what} does not belong to you.",
            403,
        );
    }

    // ---- payments ------------------------------------------------------

    public static function idempotencyKeyReused(): self
    {
        return new self(
            'IDEMPOTENCY_KEY_REUSED',
            'This request key was already used with different content.',
            409,
        );
    }

    public static function conflict(string $code, string $message): self
    {
        return new self($code, $message, 409);
    }

    public static function notFound(string $what = 'Resource'): self
    {
        return new self('NOT_FOUND', "{$what} was not found.", 404);
    }
}
