<?php

use App\Providers\AppServiceProvider;
use App\Providers\TenancyServiceProvider;

return [
    AppServiceProvider::class,

    // Wires the TenantCreated -> CreateDatabase -> MigrateDatabase pipeline and
    // the TenantDeleted -> DeleteDatabase teardown. `tenancy:install` writes the
    // provider but does not register it: the installer predates Laravel 11's
    // bootstrap/providers.php. Without this line a tenant is a registry row with
    // no database behind it.
    TenancyServiceProvider::class,
];
