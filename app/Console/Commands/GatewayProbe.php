<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\Tenant\PaymentInfo;
use App\Services\Gateways\GatewayRegistry;
use Illuminate\Console\Command;
use Throwable;

/**
 * Exercise one association's real gateway, one step at a time (FR-PAY-11).
 *
 * WHY A COMMAND AND NOT A TEST. Everything about online payment is covered
 * against `FakePaymentGateway` - session, callback, verification, ledger
 * posting, share minting - and all of that proves the design. None of it proves
 * that SPG's UAT endpoint answers the shape we expect, because the fake answers
 * the shape we wrote. The gap between those two is where every gateway
 * integration actually fails, and it can only be closed by talking to the real
 * thing.
 *
 * SO THIS IS THE CHECKLIST, not a test. It runs against whatever an association
 * is configured with, prints exactly what came back, and stops between steps so
 * a person can go and pay on the hosted page in between.
 *
 *   php artisan gateway:probe demo-one                 what is configured
 *   php artisan gateway:probe demo-one --payment=41    create a session
 *   php artisan gateway:probe demo-one --verify=INV-1  ask what happened
 *
 * IT NEVER COMPLETES A PAYMENT. `verify` reports; it does not apply. Settling a
 * payment posts to the ledger and can mint shares, and a diagnostic that did
 * that as a side effect would be a diagnostic nobody dares run twice. Use
 * `payments:reconcile` when the answer looks right.
 *
 * COMPLETION DOES NOT REQUIRE AN INBOUND CALLBACK. That matters for a sandbox
 * run from a laptop: SPG cannot reach `localhost`, so the callback half cannot
 * be proved without a tunnel - but `verify()` is an OUTBOUND call, and
 * reconciliation completes payments on it alone (ADR-0007). The outbound half
 * is also where the shape surprises live.
 */
class GatewayProbe extends Command
{
    protected $signature = 'gateway:probe
        {slug : The association whose gateway to exercise}
        {--payment= : Create a hosted session for this pending payment id}
        {--verify= : Ask the gateway what happened to this reference}';

    protected $description = 'Talk to an association\'s real payment gateway and print what it says';

    public function handle(GatewayRegistry $registry): int
    {
        $tenant = Tenant::find((string) $this->argument('slug'));

        if (! $tenant) {
            $this->error("No association [{$this->argument('slug')}].");

            return self::FAILURE;
        }

        return $tenant->run(function () use ($tenant, $registry) {
            $driver = (string) config('services.gateway.driver');
            $provider = $registry->activeProvider();

            $this->line("Association: {$tenant->getKey()}");
            $this->line("PAYMENT_GATEWAY: {$driver}");
            $this->line('Active provider: '.($provider ?? 'none configured'));

            if ($driver === 'fake') {
                /*
                 * Refused rather than quietly probed. The fake always answers,
                 * always succeeds, and would make this command print a clean
                 * bill of health for a deployment that cannot take a payment -
                 * which is the exact false assurance the command exists to
                 * prevent.
                 */
                $this->newLine();
                $this->warn('PAYMENT_GATEWAY is `fake`, so nothing real would be contacted.');
                $this->line('Set PAYMENT_GATEWAY=auto to probe the association\'s own gateway.');

                return self::FAILURE;
            }

            if (! $provider) {
                $this->newLine();
                $this->warn('This association has no active gateway. Configure one in the console.');

                return self::FAILURE;
            }

            $gateway = $registry->active();

            $this->line('Adapter: '.$gateway::class);
            $this->newLine();

            return match (true) {
                $this->option('payment') !== null => $this->session($gateway, $tenant),
                $this->option('verify') !== null => $this->verify($gateway),
                default => $this->summary(),
            };
        });
    }

    private function summary(): int
    {
        $this->line('Nothing contacted. Next:');
        $this->line('  --payment=<id>     create a hosted session for a PENDING online payment');
        $this->line('  --verify=<ref>     ask what happened to a reference');
        $this->newLine();

        $pending = PaymentInfo::query()
            ->where('status', PaymentInfo::STATUS_PENDING)
            ->where('payment_type', PaymentInfo::TYPE_ONLINE)
            ->latest('id')
            ->limit(5)
            ->get(['id', 'invoice_no', 'total_amount', 'gateway_reference']);

        if ($pending->isEmpty()) {
            $this->comment('No pending online payments. Make one in the app first.');

            return self::SUCCESS;
        }

        $this->line('Pending online payments:');

        foreach ($pending as $payment) {
            $this->line(sprintf(
                '  #%d  %s  %s  ref=%s',
                $payment->id,
                $payment->invoice_no,
                $payment->total_amount,
                $payment->gateway_reference ?? '-',
            ));
        }

        return self::SUCCESS;
    }

    private function session($gateway, Tenant $tenant): int
    {
        $payment = PaymentInfo::find((int) $this->option('payment'));

        if (! $payment) {
            $this->error('No such payment.');

            return self::FAILURE;
        }

        if (! $payment->isPending()) {
            // A completed payment already has money against it. Asking the
            // gateway for a second session is how one invoice gets paid twice.
            $this->error("Payment {$payment->invoice_no} is {$payment->status}, not pending.");

            return self::FAILURE;
        }

        $returnUrl = route('api.gateway.return', [
            'tenant' => $tenant->getKey(),
            'payment' => $payment->id,
        ]);

        $this->line("Creating a session for {$payment->invoice_no} ({$payment->total_amount})");
        $this->line("Return URL: {$returnUrl}");
        $this->newLine();

        try {
            $session = $gateway->createSession($payment, $returnUrl);
        } catch (Throwable $e) {
            $this->error('Refused: '.$e->getMessage());

            return self::FAILURE;
        }

        $payment->update(['gateway_reference' => $session->reference]);

        $this->info('Session created.');
        $this->line('  Reference: '.$session->reference);
        $this->line('  Open this: '.$session->url);
        $this->newLine();
        $this->comment('Pay on that page, then:');
        $this->comment("  php artisan gateway:probe {$tenant->getKey()} --verify={$session->reference}");

        return self::SUCCESS;
    }

    private function verify($gateway): int
    {
        $reference = (string) $this->option('verify');

        $this->line("Asking about {$reference}");

        try {
            $result = $gateway->verify($reference);
        } catch (Throwable $e) {
            $this->error('Failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('  Status:      '.$result->status);
        $this->line('  Transaction: '.($result->transactionId ?? '-'));
        $this->line('  Amount:      '.($result->amount ?? '-').'  (the gateway\'s figure, never payable_amount)');
        $this->newLine();

        /*
         * The raw body, in full. The whole point of this command is the shape
         * we did not expect, and a summary would hide exactly that.
         */
        $this->line('Raw:');
        $this->line(json_encode($result->raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->newLine();

        $this->comment($result->isPaid()
            ? 'Reported as paid. Nothing was applied - run `payments:reconcile` to settle it.'
            : 'Not paid. Nothing was applied.');

        return self::SUCCESS;
    }
}
