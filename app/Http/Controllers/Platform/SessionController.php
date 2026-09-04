<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\OperatorAuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Signing in to the platform console.
 *
 * MFA IS REQUIRED BY NFR-SEC-5 AND IS NOT BUILT. This is a password login.
 * That is stated here, on the login page itself, and in the docs, rather than
 * left for somebody to discover: an operator account can suspend an
 * association and set where its payments land, so the gap is material and
 * pretending otherwise would be the worst option.
 *
 * What IS here in the meantime:
 *   - the console is disabled unless `PLATFORM_CONSOLE_ENABLED=true`, so a
 *     deployment that does not need it does not expose it at all;
 *   - an optional IP allowlist, `PLATFORM_CONSOLE_IPS`;
 *   - throttling by email and address;
 *   - every sign-in and refusal recorded in `operator_audit_logs`.
 *
 * None of that is MFA. They narrow the window; they do not close it.
 */
class SessionController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    public function create(): View
    {
        return view('platform.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $key = 'platform-login:'.mb_strtolower($validated['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($key);

            OperatorAuditLog::record(
                action: 'operator.login_throttled',
                after: ['email' => $validated['email']],
                source: 'web',
            );

            throw ValidationException::withMessages([
                'email' => "Too many attempts. Try again in {$seconds} seconds.",
            ]);
        }

        if (! Auth::guard('operator')->attempt($validated, $request->boolean('remember'))) {
            RateLimiter::hit($key, 900);

            /*
             * The failure is logged with the email TRIED, which is not
             * necessarily an account that exists. That is deliberate: repeated
             * attempts against a name that was never an operator is exactly the
             * pattern worth seeing.
             */
            OperatorAuditLog::record(
                action: 'operator.login_failed',
                after: ['email' => $validated['email']],
                source: 'web',
            );

            throw ValidationException::withMessages([
                'email' => 'Those credentials do not match our records.',
            ]);
        }

        $operator = Auth::guard('operator')->user();

        if (! $operator->canAuthenticate()) {
            Auth::guard('operator')->logout();

            OperatorAuditLog::record(
                action: 'operator.login_refused_disabled',
                after: ['email' => $operator->email],
                source: 'web',
                operator: $operator,
            );

            throw ValidationException::withMessages([
                'email' => 'That account is disabled.',
            ]);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        $operator->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        OperatorAuditLog::record(action: 'operator.signed_in', source: 'web');

        return redirect()->intended(route('platform.index'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        OperatorAuditLog::record(action: 'operator.signed_out', source: 'web');

        Auth::guard('operator')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('platform.login');
    }
}
