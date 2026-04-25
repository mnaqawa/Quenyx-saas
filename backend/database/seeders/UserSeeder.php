<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $password = env('SEED_ADMIN_PASSWORD');
        if (empty($password)) {
            throw new \RuntimeException('Set SEED_ADMIN_PASSWORD in .env before running UserSeeder.');
        }

        $attrs = [
            'name' => 'Quenyx Admin',
            'password' => Hash::make($password),
            'api_calls_30d' => 2400,
            'last_login_at' => now()->subDay(),
        ];

        User::updateOrCreate(
            ['email' => 'admin@quenyx.test'],
            $attrs
        );
    }
}