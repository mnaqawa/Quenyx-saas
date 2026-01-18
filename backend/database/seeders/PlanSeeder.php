<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        // Free Plan - minimal modules
        Plan::create([
            'key' => 'free',
            'name' => 'Free',
            'price_cents' => 0,
            'interval' => null,
            'features' => [
                'modules' => [
                    'shieldcore',
                ],
                'limits' => [
                    'api_calls_per_month' => 1000,
                ],
            ],
        ]);

        // Pro Plan - more modules
        Plan::create([
            'key' => 'pro',
            'name' => 'Pro',
            'price_cents' => 2900, // $29.00
            'interval' => 'month',
            'features' => [
                'modules' => [
                    'shieldcore',
                    'shieldobserve',
                    'shieldinventory',
                    'shieldnotify',
                    'shieldknowledge',
                    'shieldintegrations',
                ],
                'limits' => [
                    'api_calls_per_month' => 10000,
                ],
            ],
        ]);

        // Enterprise Plan - all modules
        Plan::create([
            'key' => 'enterprise',
            'name' => 'Enterprise',
            'price_cents' => 9900, // $99.00
            'interval' => 'month',
            'features' => [
                'modules' => [
                    'shieldcore',
                    'shieldobserve',
                    'shieldinventory',
                    'shieldrespond',
                    'shieldsecure',
                    'shieldnotify',
                    'shieldvoice',
                    'shieldknowledge',
                    'shieldautomate',
                    'shieldbalance',
                    'shielddesk',
                    'shieldintegrations',
                ],
                'limits' => [
                    'api_calls_per_month' => 100000,
                ],
            ],
        ]);
    }
}
