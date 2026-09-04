<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Platform console
    |--------------------------------------------------------------------------
    |
    | The operator console (FR-PLT-1..5). OFF unless explicitly enabled: it can
    | suspend an association and reach the controls deciding where its payments
    | land, and NFR-SEC-5's MFA requirement is not met yet. A laptop, a staging
    | box and a demo instance have no business serving it.
    |
    | `console_ips` optionally narrows it to named addresses. An empty value
    | means any address - which is only an acceptable default because an IP
    | allowlist is not a substitute for MFA, and treating it as one would be
    | worse than leaving the gap visible.
    |
    */

    'console_enabled' => env('PLATFORM_CONSOLE_ENABLED', false),

    'console_ips' => env('PLATFORM_CONSOLE_IPS', ''),

];
