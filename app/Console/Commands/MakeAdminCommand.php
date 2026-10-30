<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class MakeAdminCommand extends Command
{
    protected $signature = 'app:make-admin {email?}';

    protected $description = 'Create or promote an administrator account';

    public function handle(): int
    {
        $email = Str::lower(trim($this->argument('email') ?: $this->ask('Administrator email')));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Enter a valid email address.');

            return self::FAILURE;
        }

        do {
            $name = trim((string) $this->ask('Administrator name'));

            if ($name === '') {
                $this->error('Administrator name is required.');
            }
        } while ($name === '');

        do {
            $password = $this->secret('Administrator password (at least 10 characters)');
            $confirmation = $this->secret('Confirm administrator password');

            if (strlen($password) < 10 || ! hash_equals($password, $confirmation)) {
                $this->error('The password must contain at least 10 characters and match the confirmation.');
            }
        } while (strlen($password) < 10 || ! hash_equals($password, $confirmation));

        $user = User::firstOrNew(['email' => $email]);
        $user->name = $name;
        $user->password = Hash::make($password);
        $user->is_admin = true;
        $user->save();

        $this->info('Administrator account is ready.');

        return self::SUCCESS;
    }
}
