<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\PaymentService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Release abandoned payment intents (FR-PAY-8).
 *
 * A member opens the gateway, changes their mind, and closes the app. Without
 * this, their instalment sits in `Requested` forever: it does not appear as due,
 * it cannot be paid again, and it quietly disappears from the association's
 * outstanding balance. Nothing in the legacy system ever releases one (D-18).
 *
 * Expiry only ever moves an assignment from Requested back to Unpaid, so a
 * payment that completed in the meantime is untouched.
 */
class ExpirePaymentIntents extends Command
{
    protected $signature = 'payments:expire-intents {--tenant= : Restrict to one association slug}';

    protected $description = 'Return abandoned payment intents to Unpaid';

    public function handle(): int
    {
        $tenants = Tenant::query()
            ->where('status', Tenant::STATUS_ACTIVE)
            ->when($this->option('tenant'), fn ($q, $slug) => $q->where('id', $slug))
            ->get();

        $failed = 0;

        foreach ($tenants as $tenant) {
            try {
                $expired = $tenant->run(
                    fn () => app(PaymentService::class)->expireStaleIntents()
                );

                if ($expired > 0) {
                    $this->info("{$tenant->getKey()}: {$expired} intent(s) expired.");
                }
            } catch (Throwable $e) {
                $failed++;
                $this->error("{$tenant->getKey()}: {$e->getMessage()}");
            }
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
