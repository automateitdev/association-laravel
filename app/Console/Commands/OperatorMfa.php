<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Operator;
use App\Models\OperatorAuditLog;
use App\Services\Totp;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Enrol an operator's second factor (NFR-SEC-5).
 *
 * AT THE SERVER, LIKE CREATING THE ACCOUNT. Enrolment is the moment the second
 * factor is decided, so doing it from inside a signed-in browser session would
 * mean a stolen session could quietly re-enrol itself onto an attacker's phone
 * and lock the real operator out. The same access that creates operators
 * enrols them.
 *
 * THE SECRET IS SHOWN ONCE, HERE. That is unavoidable - an authenticator app
 * has to be given it - and it is why this prints to a terminal rather than
 * emailing or storing anything readable. It is displayed as text as well as an
 * `otpauth://` URI, because a URI is only useful if something can turn it into
 * a QR code, and a server console usually cannot.
 *
 * Enrolment is not complete until a code is verified: a secret nobody has
 * proved against their phone is a lockout waiting to happen, so the command
 * asks for one before it saves anything.
 */
class OperatorMfa extends Command
{
    protected $signature = 'operator:mfa
        {email : The operator to enrol}
        {--reset : Clear their second factor so it can be set up again}';

    protected $description = 'Enrol or reset an operator second factor (NFR-SEC-5)';

    /** Ten is enough to survive a lost phone without becoming a list nobody guards. */
    private const RECOVERY_CODES = 10;

    public function handle(Totp $totp): int
    {
        $email = (string) $this->argument('email');
        $operator = Operator::where('email', $email)->first();

        if (! $operator) {
            $this->error("No operator with [{$email}].");

            return self::FAILURE;
        }

        if ($this->option('reset')) {
            return $this->reset($operator);
        }

        if ($operator->hasMfa()) {
            $this->error("[{$email}] already has a second factor.");
            $this->line('Use --reset first. Resetting is deliberately a separate, recorded act.');

            return self::FAILURE;
        }

        $secret = $totp->generateSecret();

        $this->info("Enrolling [{$email}].");
        $this->newLine();
        $this->line('  Secret:  '.trim(chunk_split($secret, 4, ' ')));
        /*
         * `app.name` as it stands, with nothing appended. It is already
         * "BCS Platform" on this deployment, and the appended word produced
         * "BCS Platform Platform" - which is display-only, but it is the
         * label somebody then reads in their authenticator every day.
         */
        $this->line('  URI:     '.$totp->uri($secret, $email, (string) config('app.name')));
        $this->newLine();
        $this->comment('  Add it to an authenticator app, then enter the code it shows.');
        $this->newLine();

        $code = (string) $this->ask('Code from the app');

        /*
         * Verified against the secret in hand, before it is stored. Saving an
         * unproved secret is how somebody ends up locked out of an account they
         * were told was configured.
         */
        if ($totp->verify($secret, $code) === null) {
            $this->error('That code did not match. Nothing was saved - start again.');

            return self::FAILURE;
        }

        $recovery = $this->generateRecoveryCodes();

        $operator->forceFill([
            'mfa_secret' => $secret,
            'mfa_confirmed_at' => now(),
            'mfa_recovery_codes' => array_map(fn (string $c) => Hash::make($c), $recovery),
            'mfa_last_used_step' => null,
        ])->save();

        OperatorAuditLog::record(
            action: 'operator.mfa_enrolled',
            after: ['email' => $email, 'recovery_codes' => count($recovery)],
            reason: 'Enrolled at the server console.',
            source: 'console',
            operatorEmail: $email,
        );

        $this->newLine();
        $this->info('Second factor enrolled.');
        $this->newLine();
        $this->warn('  RECOVERY CODES - shown once, each usable once:');

        foreach ($recovery as $recoveryCode) {
            $this->line('    '.$recoveryCode);
        }

        $this->newLine();
        $this->comment('  Keep them somewhere the phone is not. They are hashed here, so a lost');
        $this->comment('  list cannot be reprinted - only reset, which replaces all of them.');

        return self::SUCCESS;
    }

    /**
     * Clear a second factor.
     *
     * Recorded loudly, because this is the one operation that takes an account
     * back to password-only. A reset nobody notices is how a compromised
     * account becomes a permanent one.
     */
    private function reset(Operator $operator): int
    {
        if (! $operator->mfa_secret) {
            $this->warn("[{$operator->email}] has no second factor to reset.");

            return self::SUCCESS;
        }

        $operator->forceFill([
            'mfa_secret' => null,
            'mfa_confirmed_at' => null,
            'mfa_recovery_codes' => null,
            'mfa_last_used_step' => null,
        ])->save();

        OperatorAuditLog::record(
            action: 'operator.mfa_reset',
            before: ['had_mfa' => true],
            after: ['email' => $operator->email, 'had_mfa' => false],
            reason: 'Reset at the server console.',
            source: 'console',
            operatorEmail: $operator->email,
        );

        $this->info("Second factor cleared for [{$operator->email}].");
        $this->warn('They cannot sign in until it is set up again - the console refuses');
        $this->warn('an operator without one.');

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function generateRecoveryCodes(): array
    {
        return collect(range(1, self::RECOVERY_CODES))
            // Upper case and hyphenated: these get written down and read back,
            // and a lowercase l next to a 1 is a support call.
            ->map(fn () => strtoupper(Str::random(5).'-'.Str::random(5)))
            ->all();
    }
}
