<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * TLS ends at the reverse proxy, and the app has to be told.
 *
 * WHY THIS IS TESTED RATHER THAN LEFT TO CONFIGURATION
 * ----------------------------------------------------
 * Behind a proxy that terminates HTTPS, PHP sees a plain HTTP request. Without
 * trusted proxies the damage is not cosmetic:
 *
 *   - `url()` and `route()` emit http://, so links in mail and anything built
 *     from the queue point at a scheme the site redirects away from;
 *   - a SIGNED URL is signed over the http form while the browser requests the
 *     https one, so the signature never matches and every member's document
 *     download 403s (NFR-SEC-4). That is the one that breaks rather than looks
 *     wrong, and it looks like a permissions bug rather than a config one.
 *
 * The setting lives in bootstrap/app.php, has no visible effect in local
 * development - where there is no proxy - and will not be noticed missing until
 * somebody puts one in front. Which is exactly what a test is for.
 */
class ProxyTrustTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // A probe rather than a real endpoint: what is under test is the global
        // middleware stack, which every route goes through.
        Route::get('/__proxy_probe', fn () => [
            'secure' => request()->isSecure(),
            'scheme' => request()->getScheme(),
        ]);
    }

    /** The proxy is on the docker bridge, and it is believed. */
    public function test_https_terminated_at_a_private_range_proxy_is_believed(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.5'])
            ->getJson('/__proxy_probe', ['X-Forwarded-Proto' => 'https'])
            ->assertOk()
            ->assertJsonPath('secure', true)
            ->assertJsonPath('scheme', 'https');
    }

    /** Loopback too: a proxy on the same host is the other supported shape. */
    public function test_a_loopback_proxy_is_believed(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->getJson('/__proxy_probe', ['X-Forwarded-Proto' => 'https'])
            ->assertJsonPath('secure', true);
    }

    /**
     * AND A STRANGER IS NOT.
     *
     * `X-Forwarded-Proto` is a header, which means it is whatever the client
     * typed until something in front replaces it. Trusting every proxy - `at:
     * '*'` - would let anyone reaching the app directly assert their own
     * address and scheme, and this application is deployed beside other things
     * on shared hosts where "reaching it directly" is not hypothetical.
     */
    public function test_a_forwarded_header_from_an_untrusted_address_is_ignored(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->getJson('/__proxy_probe', ['X-Forwarded-Proto' => 'https'])
            ->assertJsonPath('secure', false)
            ->assertJsonPath('scheme', 'http');
    }

    /** No header, no claim: a plain request stays plain. */
    public function test_a_request_with_no_forwarded_header_is_not_secure(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.5'])
            ->getJson('/__proxy_probe')
            ->assertJsonPath('secure', false);
    }
}
