<?php

declare(strict_types=1);

namespace App\Support\Gateway;

/**
 * A hosted payment session the member is sent to.
 *
 * `reference` is what ties the gateway's later callback back to our payment,
 * and it is stored on the payment row BEFORE the member is redirected. Without
 * that, a callback arriving for an abandoned attempt has nothing to match.
 */
final readonly class GatewaySession
{
    public function __construct(
        public string $url,
        public string $reference,
        public ?string $expiresAt = null,
    ) {}
}
