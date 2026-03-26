<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class SetUserPassword extends Command
{
    protected $signature = 'user:set-password
                            {email : User email}
                            {password : New password (wrap in quotes if it contains spaces)}
                            {--force : Required in production}';

    protected $description = 'Set a user password hash (recovery / ops).';

    public function handle(): int
    {
        if (config('app.env') === 'production' && !$this->option('force')) {
            $this->error('In production, re-run with --force after you understand this overwrites the password hash.');
            return 1;
        }

        $email = trim((string) $this->argument('email'));
        $password = (string) $this->argument('password');

        $user = User::query()->whereRaw('LOWER(email) = ?', [strtolower($email)])->first();
        if ($user === null) {
            $this->error("No user found for email: {$email}");
            return 1;
        }

        $user->password = Hash::make($password);
        $user->save();

        $this->info("Password updated for user id {$user->id} ({$user->email}).");

        return 0;
    }
}
