<?php

declare(strict_types=1);

namespace Tests\Support\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * A queued job that writes a marker into whichever tenant database it runs
 * against.
 *
 * Deliberately writes with a raw query rather than through a model, so the test
 * proves the CONNECTION was switched rather than that a model happened to be
 * bound correctly.
 */
class RecordTenantMarkerJob implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $marker) {}

    public function handle(): void
    {
        DB::table('settings')->insert([
            'key' => 'marker.'.$this->marker,
            'value' => json_encode(tenant()?->getKey() ?? 'NO-TENANT'),
            'group' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
