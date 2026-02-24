<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $oldAdmin = User::where('email', 'admin@portshield.test')->first();
        if ($oldAdmin) {
            $oldAdmin->update([
                'email' => 'admin@quenyx.test',
                'name' => 'Quenyx Admin',
                'password' => Hash::make('AWGPBU2vuGc9ur3'),
                'api_calls_30d' => 2400,
                'last_login_at' => now()->subDay(),
            ]);
            return;
        }

        User::updateOrCreate(
            ['email' => 'admin@quenyx.test'],
            [
                'name' => 'Quenyx Admin',
                'password' => Hash::make('AWGPBU2vuGc9ur3'),
                'api_calls_30d' => 2400,
                'last_login_at' => now()->subDay(),
            ]
        );
    }
}
