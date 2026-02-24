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
        $newAdmin = User::where('email', 'admin@quenyx.test')->first();

        $attrs = [
            'name' => 'Quenyx Admin',
            'password' => Hash::make('AWGPBU2vuGc9ur3'),
            'api_calls_30d' => 2400,
            'last_login_at' => now()->subDay(),
        ];

        if ($oldAdmin && $newAdmin) {
            $newAdmin->delete();
            $oldAdmin->update(array_merge(['email' => 'admin@quenyx.test'], $attrs));
            return;
        }
        if ($oldAdmin) {
            $oldAdmin->update(array_merge(['email' => 'admin@quenyx.test'], $attrs));
            return;
        }

        User::updateOrCreate(
            ['email' => 'admin@quenyx.test'],
            $attrs
        );
    }
}