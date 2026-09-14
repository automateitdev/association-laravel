<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * Set the password of one staff account in one association.
 *
 * WHY THIS HAD TO EXIST. `legacy:migrate` carries the association's staff
 * accounts across with a password that is a bcrypt hash of something nobody
 * holds - MG-1's forced reset. That is the correct thing to migrate, and it
 * left a gap nobody had noticed: there is no password reset flow for staff and
 * no command to create one, so a freshly migrated association had four accounts
 * and no way to sign in to ANY of them. The association would have been handed
 * its own data and locked out of it.
 *
 * THE PASSWORD IS PROMPTED, NEVER AN ARGUMENT, for the same reason
 * `operator:create` prompts: an argument sits in shell history, in the process
 * list while it runs, and in whatever log the terminal writes. It is typed by
 * the person who will use it and read by nobody else.
 *
 * It changes an existing account and will not invent one. Creating staff is the
 * association's own act, done where it can be seen; this exists to let somebody
 * back INTO an account the migration already carried, not to mint authority at
 * a shell prompt.
 */
class TenantStaffPassword extends Command
{
    protected $signature = 'tenant:staff-password
        {tenant : The association slug, e.g. cocsol}
        {email : The staff account to set a password for}';

    protected $description = 'Set a staff password for one association (prompts for it)';

    public function handle(): int
    {
        $slug = (string) $this->argument('tenant');
        $email = (string) $this->argument('email');

        $tenant = Tenant::find($slug);

        if (! $tenant) {
            $this->error("No association [{$slug}].");

            return self::FAILURE;
        }

        return $tenant->run(function () use ($slug, $email) {
            $user = User::where('email', $email)->first();

            if (! $user) {
                $this->error("No staff account [{$email}] in [{$slug}].");

                $known = User::orderBy('id')->pluck('email');

                if ($known->isNotEmpty()) {
                    $this->line('This association has: '.$known->implode(', '));
                }

                return self::FAILURE;
            }

            $this->info("Setting the password for {$user->name} <{$user->email}> in [{$slug}].");

            $password = $this->secret('New password (at least 12 characters)');
            $again = $this->secret('Repeat it');

            if ($password !== $again) {
                $this->error('They do not match. Nothing was changed.');

                return self::FAILURE;
            }

            $check = Validator::make(['password' => $password], [
                'password' => ['required', 'string', 'min:12'],
            ]);

            if ($check->fails()) {
                $this->error($check->errors()->first('password'));

                return self::FAILURE;
            }

            $user->forceFill([
                'password' => Hash::make($password),

                /*
                 * Every existing token dies with the old password. If this is
                 * being used because somebody lost access, it may equally be
                 * because somebody else GAINED it, and leaving live tokens
                 * behind would make the reset cosmetic.
                 */
                'remember_token' => null,
            ])->save();

            $user->tokens()->delete();

            $this->info('Done. Any existing sessions and API tokens for this account are now invalid.');

            return self::SUCCESS;
        });
    }
}
