<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\SchoolService;
use App\Support\PasswordPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Creates the independent Developer account (or resets its password).
 * This is the ONLY way to make a Developer account: it cannot be created,
 * edited or deleted from the school Users pages, and there is no default password.
 *
 *   php artisan developer:create devuser --name="System Developer"
 *   php artisan developer:create devuser --reset      (new password for an existing Developer)
 */
class CreateDeveloperCommand extends Command
{
    protected $signature = 'developer:create
                            {username : Username for the Developer sign-in at /developer/login}
                            {--name=System Developer : Full name shown in the dashboard and audit log}
                            {--reset : Set a new password for an existing Developer account}';

    protected $description = 'Create the independent Developer account (or reset its password)';

    public function handle(): int
    {
        $username = trim((string) $this->argument('username'));
        if ($username === '' || mb_strlen($username) > 191) {
            $this->error('Please give a username (at most 191 characters).');
            return self::FAILURE;
        }

        $existing = User::findByUsername($username);
        if ($existing && !$existing->isDeveloper()) {
            $this->error('That username belongs to a school account. Choose another username for the Developer.');
            return self::FAILURE;
        }
        if ($existing && !$this->option('reset')) {
            $this->error('A Developer account with this username already exists. Use --reset to set a new password.');
            return self::FAILURE;
        }
        if (!$existing && User::usernameTaken($username)) {
            $this->error('That username is already taken.');
            return self::FAILURE;
        }

        $password = (string) $this->secret('Password for the Developer account');
        if ($error = PasswordPolicy::error($password, $username)) {
            $this->error($error);
            return self::FAILURE;
        }
        if ($password !== (string) $this->secret('Type the password again')) {
            $this->error('The two passwords do not match.');
            return self::FAILURE;
        }

        if ($existing) {
            $existing->update(['password' => Hash::make($password), 'active' => 1, 'failed_login_attempts' => 0, 'locked_until' => null]);
            SchoolService::logAction(null, 'RESET_DEVELOPER_PASSWORD', "{$username} (from the server command line)");
            $this->info("Password reset for Developer '{$username}'. Older sessions of this account are signed out.");

            return self::SUCCESS;
        }

        User::create([
            'username' => $username,
            'password' => Hash::make($password),
            'full_name' => trim((string) $this->option('name')) ?: 'System Developer',
            'role' => SchoolService::ROLE_DEVELOPER,
            'title' => 'Developer',
            'class_id' => null,
            'active' => 1,
            'must_change_password' => 0,
        ]);
        SchoolService::logAction(null, 'CREATE_DEVELOPER', "{$username} (from the server command line)");
        $this->info("Developer account '{$username}' created. Sign in at /developer/login.");

        return self::SUCCESS;
    }
}
