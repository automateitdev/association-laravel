<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Whether the platform console exists at all on this deployment.
 *
 * OFF BY DEFAULT. The console can suspend an association and reach the controls
 * that decide where its payments land, and NFR-SEC-5's MFA requirement is not
 * met yet. A surface like that should not be reachable on every environment
 * merely because the code is deployed there — a developer's laptop, a staging
 * box and a demo instance have no business serving it.
 *
 * `PLATFORM_CONSOLE_ENABLED=true` turns it on. `PLATFORM_CONSOLE_IPS` optionally
 * narrows it to named addresses; empty means any address, which is the right
 * default only because an IP allowlist is not a substitute for MFA and
 * pretending otherwise would encourage treating this as solved.
 *
 * A 404, not a 403: an address that may not reach the console learns nothing
 * about whether one exists.
 */
class PlatformConsole
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('platform.console_enabled')) {
            abort(404);
        }

        $allowed = array_filter(array_map('trim', explode(',', (string) config('platform.console_ips'))));

        if ($allowed !== [] && ! in_array($request->ip(), $allowed, true)) {
            abort(404);
        }

        $this->assertSessionsPersist();

        return $next($request);
    }

    /**
     * Refuse to serve a console whose sessions cannot outlive a request.
     *
     * This exists because of how the failure presents without it. The API is
     * stateless, so `SESSION_DRIVER` was `array` - correct for tokens, and
     * fatal for a server-rendered login. Signing in genuinely succeeded, the
     * redirect fired, the next request arrived with an empty session, and the
     * operator landed back on the login page with no error anywhere: not on
     * screen, not in the log, not in the audit trail, which recorded a
     * perfectly successful sign-in each time they tried.
     *
     * A misconfiguration that authenticates you and then silently pretends you
     * are a stranger is worth failing loudly for.
     */
    private function assertSessionsPersist(): void
    {
        $driver = config('session.driver');

        if (in_array($driver, ['array', null], true)) {
            abort(500, sprintf(
                'The platform console needs sessions that survive a request, and SESSION_DRIVER '
                    .'is "%s", which does not. Set SESSION_DRIVER=database (the sessions table '
                    .'lives in the central database) or file. Without this, signing in appears '
                    .'to do nothing.',
                $driver ?? 'null',
            ));
        }
    }
}
