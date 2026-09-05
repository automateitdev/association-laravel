<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant;
use App\Models\TenantProvisioningRun;

/**
 * What happened when an association was provisioned.
 *
 * THREE OUTCOMES, NOT TWO. "Refused" and "failed" look alike from a distance
 * and are completely different events: refused means nothing was attempted -
 * a bad slug, a name already taken - while failed means a database was created
 * and then rolled back. Collapsing them would have the console report a
 * rollback that never happened, and hide one that did.
 */
final readonly class ProvisionResult
{
    /**
     * @param  list<string>  $steps
     */
    private function __construct(
        public bool $ok,
        public ?Tenant $tenant = null,
        public ?TenantProvisioningRun $run = null,
        public ?string $domain = null,

        /**
         * Shown ONCE and never stored in readable form - only its hash goes to
         * `password_reset_tokens`. It is not a password: it lets the
         * association's first administrator set one nobody else has seen.
         */
        public ?string $setupToken = null,

        public array $steps = [],
        public ?string $error = null,
        public ?string $rollbackNotes = null,
        public bool $attempted = true,
    ) {}

    /** @param  list<string>  $steps */
    public static function succeeded(
        Tenant $tenant,
        TenantProvisioningRun $run,
        string $domain,
        ?string $setupToken,
        array $steps,
    ): self {
        return new self(
            ok: true,
            tenant: $tenant,
            run: $run,
            domain: $domain,
            setupToken: $setupToken,
            steps: $steps,
        );
    }

    /** Something was created and then undone. @param list<string> $steps */
    public static function failed(
        TenantProvisioningRun $run,
        string $error,
        string $rollbackNotes,
        array $steps,
    ): self {
        return new self(
            ok: false,
            run: $run,
            steps: $steps,
            error: $error,
            rollbackNotes: $rollbackNotes,
        );
    }

    /** Nothing was attempted, so there is nothing to roll back. */
    public static function refused(string $error): self
    {
        return new self(ok: false, error: $error, attempted: false);
    }
}
