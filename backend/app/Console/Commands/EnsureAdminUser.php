<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class EnsureAdminUser extends Command
{
    protected $signature = 'user:ensure-admin
                            {--email=admin@quenyx.test : Admin email}
                            {--force : Required in production}';

    protected $description = 'Create or update the platform admin user using SEED_ADMIN_PASSWORD from .env (recovery when user is missing).';

    public function handle(): int
    {
        if (config('app.env') === 'production' && !$this->option('force')) {
            $this->error('In production, re-run with --force.');
            return 1;
        }

        $password = env('SEED_ADMIN_PASSWORD');
        if ($password === null || $password === '') {
            $this->error('Set SEED_ADMIN_PASSWORD in .env (same as UserSeeder).');
            return 1;
        }

        $email = strtolower(trim((string) $this->option('email')));
        if ($email === '') {
            $this->error('Invalid --email.');
            return 1;
        }

        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        if ($user === null) {
            $user = new User();
            $user->email = $email;
            $this->info("Creating new admin user: {$email}");
        } else {
            $this->info("Updating existing user id {$user->id}: {$user->email}");
        }

        $user->name = 'Quenyx Admin';
        $user->password = Hash::make($password);
        if (Schema::hasColumn($user->getTable(), 'api_calls_30d')) {
            $user->setAttribute('api_calls_30d', 2400);
        }
        if (Schema::hasColumn($user->getTable(), 'last_login_at')) {
            $user->setAttribute('last_login_at', now()->subDay());
        }
        $user->save();

        $this->info('Admin user is ready. You can log in with that email and SEED_ADMIN_PASSWORD.');

        return 0;
    }
}
