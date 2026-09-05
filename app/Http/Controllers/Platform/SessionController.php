<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Operator;
use App\Models\OperatorAuditLog;
use App\Services\Totp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Signing in to the platform console: password, then a second factor.
 *
 * THE PASSWORD STEP DOES NOT SIGN ANYBODY IN. It uses `validate()`, not
 * `attempt()`, and puts the operator's id in the session as *pending*. Nothing
 * is authenticated until a code is verified. Logging in and then asking for a
 * code would leave a window where a session exists without the second factor
 * having been proved - and a redirect, a race or a forgotten middleware turns
 * that window into the whole hole MFA was added to close.
 *
 * WHAT IS RECORDED, AND WHY THE DISTINCTION MATTERS
 * A wrong password and a wrong code are different events. The first says
 * somebody is guessing; the second says somebody HAS the password and is stuck
 * at the factor - which is the alarm worth having. They are separate actions in
 * the audit log and separate throttles, so one cannot mask the other.
 */
class SessionController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    /** Tighter than the password: reaching here means the password is known. */
    private const MAX_CODE_ATTEMPTS = 5;

    private const PENDING_KEY = 'platform.mfa.pending_operator';

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
                operatorEmail: $validated['email'],
            );

            throw ValidationException::withMessages([
                'email' => "Too many attempts. Try again in {$seconds} seconds.",
            ]);
        }

        /*
         * validate(), not attempt(): this checks the credentials WITHOUT
         * establishing a session. See the class note - a session that exists
         * before the second factor is proved is the hole this closes.
         */
        if (! Auth::guard('operator')->validate($validated)) {
            RateLimiter::hit($key, 900);

            /*
             * Logged with the email TRIED, which is not necessarily an account
             * that exists. Repeated attempts against a name that was never an
             * operator is exactly the pattern worth seeing.
             */
            OperatorAuditLog::record(
                action: 'operator.login_failed',
                after: ['email' => $validated['email']],
                source: 'web',
                operatorEmail: $validated['email'],
            );

            throw ValidationException::withMessages([
                'email' => 'Those credentials do not match our records.',
            ]);
        }

        $operator = Operator::where('email', $validated['email'])->firstOrFail();

        if (! $operator->canAuthenticate()) {
            OperatorAuditLog::record(
                action: 'operator.login_refused_disabled',
                after: ['email' => $operator->email],
                source: 'web',
                operatorEmail: $operator->email,
            );

            throw ValidationException::withMessages(['email' => 'That account is disabled.']);
        }

        /*
         * An operator without a second factor cannot get in at all.
         *
         * The alternative - let them through and nag - means the requirement is
         * optional in practice, which is the state this console was already in.
         * Enrolment is at the server (`operator:mfa`), so being refused here is
         * a deliberate trip to the machine rather than a self-service bypass.
         */
        if (! $operator->hasMfa()) {
            OperatorAuditLog::record(
                action: 'operator.login_refused_no_mfa',
                after: ['email' => $operator->email],
                source: 'web',
                operatorEmail: $operator->email,
            );

            throw ValidationException::withMessages([
                'email' => 'This account has no second factor. Run `php artisan operator:mfa '
                    .$operator->email.'` at the server to enrol one.',
            ]);
        }

        RateLimiter::clear($key);

        // Pending, not authenticated. `remember` is carried across so the
        // second step can honour it without re-asking.
        $request->session()->put(self::PENDING_KEY, [
            'id' => $operator->id,
            'remember' => $request->boolean('remember'),
            'at' => now()->timestamp,
        ]);

        return redirect()->route('platform.challenge');
    }

    public function challenge(Request $request): View|RedirectResponse
    {
        if (! $this->pending($request)) {
            return redirect()->route('platform.login');
        }

        return view('platform.challenge');
    }

    /**
     * The second step: a TOTP code, or a recovery code.
     *
     * One field, not two. Somebody reaching for a recovery code has lost their
     * phone and is already having a bad day; making them first find the right
     * box is friction for no security gain, since both are checked here anyway.
     */
    public function verify(Request $request): RedirectResponse
    {
        $pending = $this->pending($request);

        if (! $pending) {
            return redirect()->route('platform.login')
                ->withErrors(['email' => 'That sign-in expired. Start again.']);
        }

        $validated = $request->validate(['code' => ['required', 'string', 'max:32']]);

        $operator = Operator::find($pending['id']);

        if (! $operator || ! $operator->canAuthenticate()) {
            $request->session()->forget(self::PENDING_KEY);

            return redirect()->route('platform.login')
                ->withErrors(['email' => 'That account is no longer available.']);
        }

        $key = 'platform-mfa:'.$operator->id.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::MAX_CODE_ATTEMPTS)) {
            /*
             * The pending sign-in is DISCARDED, not merely paused. Somebody
             * throwing codes at a session already holding a valid password is
             * the event this whole feature exists for; they should have to
             * present the password again, and the attempt is recorded.
             */
            $request->session()->forget(self::PENDING_KEY);

            OperatorAuditLog::record(
                action: 'operator.mfa_throttled',
                after: ['email' => $operator->email],
                source: 'web',
                operatorEmail: $operator->email,
            );

            throw ValidationException::withMessages([
                'code' => 'Too many codes. Sign in again.',
            ]);
        }

        $code = trim($validated['code']);

        $usedRecoveryCode = false;

        if (! $operator->verifyMfaCode($code, app(Totp::class))) {
            if (! $operator->consumeRecoveryCode($code)) {
                RateLimiter::hit($key, 900);

                OperatorAuditLog::record(
                    action: 'operator.mfa_failed',
                    after: ['email' => $operator->email],
                    source: 'web',
                    operatorEmail: $operator->email,
                );

                throw ValidationException::withMessages([
                    'code' => 'That code is not right, or has already been used.',
                ]);
            }

            $usedRecoveryCode = true;
        }

        RateLimiter::clear($key);
        $request->session()->forget(self::PENDING_KEY);

        Auth::guard('operator')->login($operator, $pending['remember'] ?? false);

        // Regenerated AFTER login, against session fixation.
        $request->session()->regenerate();

        $operator->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        OperatorAuditLog::record(
            action: $usedRecoveryCode ? 'operator.signed_in_with_recovery_code' : 'operator.signed_in',
            after: $usedRecoveryCode
                // How many are left, so a dwindling list is visible in the log
                // rather than discovered when the last one is spent.
                ? ['recovery_codes_remaining' => $operator->recoveryCodesRemaining()]
                : null,
            source: 'web',
        );

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

    /**
     * The half-finished sign-in, if there is one and it is still fresh.
     *
     * Five minutes. A pending sign-in is a password already proved, so leaving
     * one lying around on a shared machine is most of a login.
     *
     * @return array{id: int, remember: bool, at: int}|null
     */
    private function pending(Request $request): ?array
    {
        $pending = $request->session()->get(self::PENDING_KEY);

        if (! is_array($pending) || ! isset($pending['id'], $pending['at'])) {
            return null;
        }

        if (now()->timestamp - $pending['at'] > 300) {
            $request->session()->forget(self::PENDING_KEY);

            return null;
        }

        return $pending;
    }
}
