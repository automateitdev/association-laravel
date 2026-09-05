<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\GatewayService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Ask the gateway about every pending online payment (ADR-0007).
 *
 * THE SECOND COMPLETION PATH, and until now the only one without a way to run
 * it. `GatewayService::reconcilePending()` has existed and been tested since the
 * gateway design was written; nothing scheduled it and no command invoked it, so
 * a payment whose callback never arrived stayed pending forever and the
 * "degrades to slower completion, not incorrect completion" promise was not
 * actually kept.
 *
 * WHY IT MATTERS EVEN WITH CALLBACKS WORKING. A callback that never arrives is
 * invisible: the member paid, the bank has the money, and our row says pending
 * with nothing anywhere reporting a problem. The only way to notice is to ask.
 *
 * AND IT IS THE ONLY PATH THAT WORKS FROM A LAPTOP. A gateway cannot reach
 * `localhost`, so a sandbox run cannot prove the callback half without a tunnel
 * - but `verify()` is an outbound call, so this completes a real sandbox payment
 * with nothing inbound at all.
 *
 * Every association is asked separately, inside its own database, and one
 * association's gateway being unreachable must not stop the rest (FR-TEN-11).
 * Payments are settled by the same `apply()` the callback uses, so the two paths
 * cannot disagree about what completion means.
 */
class ReconcilePayments extends Command
{
    protected $signature = 'payments:reconcile {--tenant= : Restrict to one association slug}';

    protected $description = 'Ask the gateway what happened to pending online payments';

    public function handle(): int
    {
        $tenants = Tenant::query()
            ->where('status', Tenant::STATUS_ACTIVE)
            ->when($this->option('tenant'), fn ($q, $slug) => $q->where('id', $slug))
            ->get();

        $failed = 0;

        foreach ($tenants as $tenant) {
            try {
                $result = $tenant->run(
                    fn () => app(GatewayService::class)->reconcilePending()
                );

                /*
                 * Silent when there was nothing to do. This runs every few
                 * minutes on every association, and a line per empty run is how
                 * a log stops being read - which matters here, because the
                 * lines that DO appear are money moving.
                 */
                if ($result['checked'] > 0) {
                    $this->info(sprintf(
                        '%s: checked %d, completed %d, released %d.',
                        $tenant->getKey(),
                        $result['checked'],
                        $result['completed'],
                        $result['released'],
                    ));
                }
            } catch (Throwable $e) {
                $failed++;

                // Recorded and carried on. One association whose gateway is
                // down must not leave every other association's members waiting.
                $this->error("{$tenant->getKey()}: {$e->getMessage()}");
            }
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
