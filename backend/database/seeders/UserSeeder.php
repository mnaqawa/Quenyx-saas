<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@portshield.test'],
            [
                'name' => 'PortShield Admin',
                'password' => Hash::make('Password123!'),
                'api_calls_30d' => 2400,
                'last_login_at' => now()->subDay(),
            ]
        );
    }
}
