<?php

declare(strict_types=1);

/**
 * CORS.
 *
 * Published for ONE reason: `exposed_headers`. Everything else here is the
 * framework default, written out because a config file that hides which values
 * are deliberate and which are inherited is worse than no config file.
 *
 * WHY Content-Disposition HAS TO BE EXPOSED
 * A browser can read only a handful of response headers from a cross-origin
 * request unless the server says otherwise, and Content-Disposition is not one
 * of them. The web client downloads a report by fetching it with its bearer
 * token and saving the resulting blob - which means IT has to name the file,
 * and the only correct name is the one the server already put in that header.
 *
 * Without this line the client would have to rebuild the filename itself, from
 * the association slug and the report title and the date, duplicating a rule
 * that lives in Report::filename(). Two implementations of one filename is how
 * downloads end up named inconsistently depending on which one ran.
 */
return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    /*
     * Open, and safe to be open BECAUSE this API is token-authenticated and
     * carries no cookies. `supports_credentials` is false below, so a browser
     * will not attach ambient credentials to a cross-origin request - there is
     * no session for another origin to ride on. An association's data is
     * reachable only by presenting a bearer token and an X-Tenant header, both
     * of which an attacker's page cannot obtain by making a request.
     *
     * If cookie-based auth is ever added, this MUST be narrowed to a list of
     * known origins first: `*` and credentials together is the classic
     * cross-origin data leak.
     */
    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    // The reason this file exists. See the note above.
    'exposed_headers' => ['Content-Disposition'],

    'max_age' => 0,

    'supports_credentials' => false,

];
