<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

/**
 * One association's merchant credentials.
 *
 * PER TENANT, and encrypted at rest (NFR-SEC-2). Each association holds its own
 * the payment gateway merchant account; the platform stores the credentials but does not
 * resell the service (A-1).
 *
 * The legacy system has live credentials as literals in PaymentController and
 * committed to git history (D-10) - so rotating them is a code deploy, and
 * anyone who has ever had repository access still holds them.
 */
class GatewayCredential extends Model
{
    protected $fillable = ['provider', 'credentials', 'webhook_secret', 'is_active'];

    protected function casts(): array
    {
        return [
            // Laravel encrypts on write and decrypts on read, with a key held
            // outside the database - otherwise encryption at rest is theatre.
            'credentials' => 'encrypted:array',
            'webhook_secret' => 'encrypted',
            'is_active' => 'boolean',
        ];
    }

    public static function activeFor(string $provider): ?self
    {
        return static::query()
            ->where('provider', $provider)
            ->where('is_active', true)
            ->first();
    }

    public function credential(string $key, mixed $default = null): mixed
    {
        return $this->credentials[$key] ?? $default;
    }
}
