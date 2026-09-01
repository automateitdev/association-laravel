<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One record of a provisioning, migration or lifecycle command against a tenant.
 *
 * Deliberately not a foreign key to `tenants`: a failed provision rolls the tenant
 * row back, and the record of why it failed must outlive it.
 */
class TenantProvisioningRun extends Model
{
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_ROLLED_BACK = 'rolled_back';

    protected $fillable = [
        'tenant_id',
        'command',
        'status',
        'output',
        'error',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public static function begin(string $tenantId, string $command): self
    {
        return static::create([
            'tenant_id' => $tenantId,
            'command' => $command,
            'status' => self::STATUS_RUNNING,
            'started_at' => now(),
        ]);
    }

    public function succeed(string $output = ''): void
    {
        $this->update([
            'status' => self::STATUS_SUCCEEDED,
            'output' => $output,
            'finished_at' => now(),
        ]);
    }

    public function fail(string $error, string $status = self::STATUS_FAILED): void
    {
        $this->update([
            'status' => $status,
            'error' => $error,
            'finished_at' => now(),
        ]);
    }
}
