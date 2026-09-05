<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Operator;
use App\Models\OperatorAuditLog;
use App\Services\Totp;
use App\Support\TerminalQr;
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
 * emailing or storing anything readable.
 *
 * IT IS DRAWN AS A QR CODE, in the terminal, locally. The first version printed
 * a 32-character base32 key and an `otpauth://` URI and left the operator to
 * transcribe one into a phone. That failed in every available way: the key was
 * mistyped, an earlier run's key was entered against a later run's secret, and
 * at one point an example number from the instructions was typed in place of a
 * code. The typed key is still printed underneath, because a scanner sometimes
 * will not focus - but nobody should have to reach for it.
 *
 * Not a link to a QR service: the URI contains the secret, so generating it on
 * somebody else's website hands a second factor to a stranger. That is exactly
 * the shortcut a person under time pressure takes, which is why the code is
 * drawn here rather than merely advised against.
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

        $uri = $totp->uri($secret, $email, (string) config('app.name'));

        foreach (TerminalQr::render($uri) as $line) {
            $this->line('  '.$line);
        }

        $this->newLine();
        $this->line('  Scan the code above. If the camera will not focus, enter this by hand:');
        $this->newLine();
        $this->line('  Secret:  '.trim(chunk_split($secret, 4, ' ')));
        /*
         * `app.name` as it stands, with nothing appended. It is already
         * "BCS Platform" on this deployment, and the appended word produced
         * "BCS Platform Platform" - which is display-only, but it is the
         * label somebody then reads in their authenticator every day.
         */
        $this->newLine();

        /*
         * Said plainly, because not saying it cost an hour. Every run mints a
         * NEW secret, so an entry added from a previous run is dead the moment
         * this line prints - and the failure it causes is a rejected code,
         * which reads like a broken clock or a broken implementation rather
         * than like the wrong account being read.
         */
        $this->warn('  This is a NEW secret. Any BCS entry already in your authenticator');
        $this->warn('  is now dead - delete it, or you will read codes from the wrong one.');
        $this->newLine();
        $this->comment('  Then enter the six digits the app is showing. Not a password, not');
        $this->comment('  the key above - the six digits that change every 30 seconds.');
        $this->newLine();

        $code = (string) $this->ask('Code from the app');

        /*
         * Verified against the secret in hand, before it is stored. Saving an
         * unproved secret is how somebody ends up locked out of an account they
         * were told was configured.
         */
        if ($totp->verify($secret, $code) === null) {
            $this->error('That code did not match. Nothing was saved.');
            $this->newLine();

            /*
             * The three things it actually is, in the order they turn out to be
             * true. "Did not match" on its own sends people to check the
             * algorithm, which is the one thing it has never been.
             */
            $this->line('  Almost always one of three things:');
            $this->line('   1. The code came from an entry left over from an earlier run.');
            $this->line('      Delete every BCS entry and scan the code above again.');
            $this->line('   2. What was typed was not the six rotating digits.');
            $this->line('   3. The phone clock is off by more than 30 seconds. Turn on');
            $this->line('      automatic date and time.');
            $this->newLine();
            $this->line('  Server time is '.date('H:i:s').' UTC. If the phone disagrees, that is it.');

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
