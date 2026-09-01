<?php

declare(strict_types=1);

namespace App\Support\Gateway;

/**
 * What the gateway says happened to a payment.
 *
 * `amount` is what the gateway collected. It is recorded on the payment as
 * `gateway_amount` for reconciliation and NEVER written to `payable_amount` -
 * that single assignment is defect D-1, and it is why every "savings" figure in
 * the legacy reports is overstated by the fines collected alongside.
 */
final readonly class GatewayVerification
{
    public const PAID = 'paid';
    public const FAILED = 'failed';
    public const PENDING = 'pending';
    public const CANCELLED = 'cancelled';

    public function __construct(
        public string $status,
        public string $reference,
        public ?string $amount = null,
        public ?string $transactionId = null,
        public array $raw = [],
    ) {}

    public function isPaid(): bool
    {
        return $this->status === self::PAID;
    }

    /** Terminal and unsuccessful: the intent can be released. */
    public function isFinalFailure(): bool
    {
        return in_array($this->status, [self::FAILED, self::CANCELLED], true);
    }
}
