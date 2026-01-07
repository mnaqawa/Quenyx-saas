<?php

namespace Database\Seeders;

use App\Module;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ModuleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Module::create([
            'name' => 'Firewall',
            'description' => 'Network firewall protection',
            'status' => 'active',
        ]);

        Module::create([
            'name' => 'Antivirus',
            'description' => 'Malware detection and removal',
            'status' => 'active',
        ]);

        Module::create([
            'name' => 'Intrusion Detection',
            'description' => 'Real-time threat monitoring',
            'status' => 'maintenance',
        ]);
    }
}
