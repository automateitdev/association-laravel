<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Operator;
use App\Models\OperatorAuditLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Create or disable a platform operator account.
 *
 * ONLY AT THE SERVER. There is no screen that creates operators, and that is
 * deliberate: an operator can suspend an association and reach the controls
 * deciding where its payments land, so the ability to mint one should require
 * the access that provisioning already requires. A console that could create
 * its own users would make one stolen session permanent.
 *
 * The password is prompted, never an argument - it would otherwise sit in shell
 * history and in `ps` output while the command runs.
 */
class OperatorCreate extends Command
{
    protected $signature = 'operator:create
        {email : The operator to create or update}
        {--name= : Display name, defaults to the email}
        {--disable : Disable this operator instead of creating one}';

    protected $description = 'Create or disable a platform operator (prompts for the password)';

    public function handle(): int
    {
        $email = (string) $this->argument('email');

        if ($this->option('disable')) {
            return $this->disable($email);
        }

        if (Operator::where('email', $email)->exists()) {
            $this->error("An operator with [{$email}] already exists.");
            $this->line('Use --disable to disable it, or change the password with this command after removing that guard.');

            return self::FAILURE;
        }

        $password = $this->secret('Password (at least 12 characters)');
        $again = $this->secret('Repeat it');

        if ($password !== $again) {
            $this->error('Those did not match. Nothing was created.');

            return self::FAILURE;
        }

        $validator = Validator::make(
            ['email' => $email, 'password' => $password],
            [
                'email' => ['required', 'email', 'max:255'],

                /*
                 * Twelve, not eight. MFA is required by NFR-SEC-5 and is not
                 * built, so the password is currently the only thing between a
                 * guessed login and the ability to suspend an association.
                 * Length is the cheap part of closing that gap; it is not a
                 * substitute for closing it.
                 */
                'password' => ['required', 'string', 'min:12'],
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $operator = Operator::create([
            'name' => $this->option('name') ?: $email,
            'email' => $email,
            'password' => $password,
            'is_active' => true,
        ]);

        OperatorAuditLog::record(
            action: 'operator.created',
            after: ['email' => $operator->email, 'name' => $operator->name],
            reason: 'Created at the server console.',
            source: 'console',
        );

        $this->info("Operator [{$email}] created.");
        $this->newLine();
        $this->warn('The console is OFF unless PLATFORM_CONSOLE_ENABLED=true on this deployment.');
        $this->warn('There is no second factor yet (NFR-SEC-5). Treat this password accordingly.');

        return self::SUCCESS;
    }

    private function disable(string $email): int
    {
        $operator = Operator::where('email', $email)->first();

        if (! $operator) {
            $this->error("No operator with [{$email}].");

            return self::FAILURE;
        }

        // Disabled, never deleted: the audit entries have to keep resolving to
        // a name long after somebody has left.
        $operator->update(['is_active' => false]);

        OperatorAuditLog::record(
            action: 'operator.disabled',
            before: ['is_active' => true],
            after: ['is_active' => false, 'email' => $email],
            reason: 'Disabled at the server console.',
            source: 'console',
        );

        $this->info("Operator [{$email}] disabled. Their audit entries are kept.");

        return self::SUCCESS;
    }
}
