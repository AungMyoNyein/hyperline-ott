<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\Console\Input\StreamableInputInterface;

class CreateAdministrator extends Command
{
    protected $signature = 'hyperline:admin
                            {email : Administrator email address}
                            {name=HyperLine Admin : Display name}
                            {--password= : Password; falls back to HYPERLINE_INITIAL_ADMIN_PASSWORD}
                            {--password-stdin : Read a password from standard input, never from arguments}
                            {--create-only : Refuse to reset or modify an existing account}';

    protected $description = 'Create or reset the HyperLine CMS administrator and grant the admin role';

    public function handle(): int
    {
        $email = (string) $this->argument('email');

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('A valid administrator email is required.');

            return self::FAILURE;
        }

        if ($this->option('create-only') && User::whereRaw('lower(email) = ?', [mb_strtolower($email)])->exists()) {
            $this->error('Create-only refused: an account already exists. No changes made.');

            return self::FAILURE;
        }

        if ($this->option('password-stdin') && $this->option('password') !== null) {
            $this->error('Use either standard input or the password option, never both.');

            return self::FAILURE;
        }

        if ($this->option('password-stdin')) {
            $stream = $this->input instanceof StreamableInputInterface ? $this->input->getStream() : null;
            $password = rtrim((string) fgets($stream ?? STDIN), "\r\n");
        } else {
            $password = trim((string) ($this->option('password') ?: config('ott.admin.initial_password')));
        }

        if ($password === '') {
            $this->error('No password supplied. Pass --password or set HYPERLINE_INITIAL_ADMIN_PASSWORD.');

            return self::FAILURE;
        }

        $role = Role::firstOrCreate(['slug' => Role::ADMIN], ['name' => 'Administrator']);

        $user = User::firstOrNew(['email' => $this->argument('email')]);
        $user->name = $this->argument('name') ?: 'HyperLine Admin';
        $user->password = Hash::make($password);

        if ($user->email_verified_at === null) {
            $user->email_verified_at = now();
        }

        $user->save();
        $user->roles()->syncWithoutDetaching([$role->id]);

        $this->info("Administrator {$user->email} is ready.");
        if (! $this->option('password-stdin')) {
            $this->warn('Remove HYPERLINE_INITIAL_ADMIN_PASSWORD from .env, then recreate the api, worker and scheduler containers.');
        }

        return self::SUCCESS;
    }
}
