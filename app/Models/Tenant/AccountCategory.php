<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccountCategory extends Model
{
    protected $fillable = ['name', 'type'];

    public function groups(): HasMany
    {
        return $this->hasMany(AccountGroup::class);
    }
}
