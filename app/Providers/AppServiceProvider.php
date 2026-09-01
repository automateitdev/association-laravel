<?php

namespace App\Providers;

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
        //
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

            // Metadata locks (DDL) and row locks respectively.
            $event->connection->statement('SET SESSION lock_wait_timeout = 15');
            $event->connection->statement('SET SESSION innodb_lock_wait_timeout = 15');
        });
    }
}
