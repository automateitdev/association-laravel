<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Where uploaded documents go
    |--------------------------------------------------------------------------
    |
    | ADR-0011. `s3` in a deployment that has a bucket, `local` otherwise, and a
    | failed write to the preferred disk falls back to local rather than losing
    | a member's upload. The disk each file landed on is recorded WITH the file,
    | so nothing has to guess afterwards.
    |
    | Named separately from `filesystems.default` on purpose: that is also where
    | unrelated scratch files go, and where a member's NID image lives is a
    | decision worth making on its own.
    |
    | THE BUCKET MUST NOT BE PUBLIC. Nothing here is served by URL - every
    | download goes through a controller that checks ownership or permission
    | first (NFR-SEC-4). A public bucket would put members' identity documents
    | on the open internet behind links that never expire.
    |
    */

    'disk' => env('DOCUMENT_DISK', env('FILESYSTEM_DISK', 'local')),

];
