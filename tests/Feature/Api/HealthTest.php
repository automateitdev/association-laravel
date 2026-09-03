<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The health check.
 *
 * WHAT THIS IS ACTUALLY GUARDING
 * ------------------------------
 * Not that the endpoint answers - that was never in doubt. That it answers
 * HONESTLY when the database is gone.
 *
 * The version this replaced was `fn () => response()->json(['status' => 'ok'])`,
 * and during development MySQL stopped twice while it carried on returning 200.
 * Every real request was failing and the one endpoint whose job is to say so
 * reported the API healthy; a monitor pointed at it would have stayed green
 * through a total outage.
 *
 * So the test that matters here is the failure one. A green-path test alone
 * would have passed against the broken version too.
 */
class HealthTest extends TestCase
{
    public function test_it_reports_ok_when_the_database_answers(): void
    {
        $this->getJson('/api/v1/health')
            ->assertSuccessful()
            ->assertJsonPath('data.status', 'ok');
    }

    public function test_it_reports_unavailable_when_the_database_does_not(): void
    {
        /*
         * The connection is made to throw rather than the server being stopped,
         * which is the only way to test this without taking the developer's
         * MySQL down mid-suite. What is being verified is the controller's
         * response to a failing query, which is the same either way.
         */
        DB::shouldReceive('connection')
            ->once()
            ->andThrow(new QueryException('mysql', 'select 1', [], new \Exception('refused')));

        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(503);
        $response->assertJsonPath('error.code', 'DATABASE_UNAVAILABLE');
    }

    public function test_it_does_not_leak_connection_details(): void
    {
        DB::shouldReceive('connection')
            ->once()
            ->andThrow(new QueryException(
                'mysql',
                'select count(*) from `tenants`',
                [],
                new \Exception('No connection could be made to 127.0.0.1:3306 database bcs_central'),
            ));

        $body = $this->getJson('/api/v1/health')->getContent();

        /*
         * This endpoint is UNAUTHENTICATED. A driver error names the host, the
         * port and the database, which is a map for anybody who asks - so the
         * message is fixed text and the exception goes to the log instead.
         */
        $this->assertStringNotContainsString('127.0.0.1', (string) $body);
        $this->assertStringNotContainsString('3306', (string) $body);
        $this->assertStringNotContainsString('bcs_central', (string) $body);
    }
}
