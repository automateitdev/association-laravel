<?php

namespace App\Providers;

use App\Contracts\PaymentGateway;
use App\Services\Gateways\FakePaymentGateway;
use App\Services\Gateways\GatewayRegistry;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        /*
         * The payment gateway, resolved PER ASSOCIATION.
         *
         * Not a container-level choice, because there is more than one way to
         * reach the same bank: SPG directly (its v2 API) or SPG through the
         * PayFlex middleware (its v3). Each association holds its own merchant
         * account (A-1) and names its own provider in `gateway_credentials`, so
         * binding one implementation here would turn a per-association setting
         * into a deploy decision for everybody at once - and would mean the
         * unproven path could only be tried by making it everybody's path.
         *
         * `PAYMENT_GATEWAY` defaults to `fake`, so a deployment takes no real
         * money until somebody deliberately says otherwise, and every test -
         * which configures no credentials - keeps getting the fake exactly as
         * before. The whole payment lifecycle (session, callback, verification,
         * ledger posting, share minting) is exercised against the fake, which is
         * why a new provider plugs in behind this interface without any of that
         * design moving.
         */
        $this->app->bind(PaymentGateway::class, fn () => $this->app->make(GatewayRegistry::class)->active());

        // Singleton so tests can configure the fake and have the service see it.
        $this->app->singleton(FakePaymentGateway::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->failFastOnLockedTablesInTests();
    }

    /**
     * Make a blocked metadata lock fail in seconds rather than hang forever.
     *
     * Tenancy tests create and drop real databases, and `migrate:fresh` issues
     * DROP TABLE against the central schema. If any connection is idle inside an
     * open transaction that touched `tenants` or `plans`, that DROP waits on a
     * metadata lock - and MySQL's default `lock_wait_timeout` is 31,536,000
     * seconds. One leaked connection turns the whole suite into a silent hang
     * with no output and no error, which is the worst failure mode a test suite
     * can have: it looks like slowness, so people wait.
     *
     * Fifteen seconds and a clear exception is far more useful. Testing only -
     * production has no business shortening lock waits.
     */
    private function failFastOnLockedTablesInTests(): void
    {
        if (! $this->app->environment('testing')) {
            return;
        }

        Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $event): void {
            if ($event->connection->getDriverName() !== 'mysql') {
                return;
            }

            /*
             * 60 seconds, not 15.
             *
             * 15 was chosen only to turn an infinite hang into an error, and it
             * did that job. But parallel workers provision real tenants, and
             * CREATE/DROP DATABASE plus CREATE/DROP USER contend for global
             * metadata locks - mysql.user writes especially. Under four workers
             * an honest DDL queue could exceed 15s and fail a CREATE TABLE,
             * which looked exactly like a tenancy bug.
             *
             * 60 still fails fast against MySQL's default of a YEAR, while
             * tolerating legitimate contention. An intermittently red suite is
             * worse than a slow one: it teaches people to re-run rather than
             * investigate, and here the thing they would learn to shrug off is
             * a tenancy failure.
             */
            $event->connection->statement('SET SESSION lock_wait_timeout = 60');
            $event->connection->statement('SET SESSION innodb_lock_wait_timeout = 60');
        });
    }
}
