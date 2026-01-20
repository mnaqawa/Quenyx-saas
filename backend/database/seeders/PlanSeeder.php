<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        // Free Plan
        Plan::updateOrCreate(
            ['key' => 'free'],
            [
                'name' => 'Free',
                'price_cents' => 0,
                'interval' => 'month',
                'features' => [
                    'modules_allowed' => [
                        'shieldcore',
                    ],
                    'limits' => [
                        'projects' => 1,
                        'members_per_project' => 1,
                    ],
                ],
            ]
        );

        // Pro Plan
        Plan::updateOrCreate(
            ['key' => 'pro'],
            [
                'name' => 'Pro',
                'price_cents' => 4900, // $49.00
                'interval' => 'month',
                'features' => [
                    'modules_allowed' => [
                        'shieldcore',
                        'shieldobserve',
                        'shieldinventory',
                        'shieldnotify',
                        'shieldknowledge',
                        'shielddesk',
                        'shieldintegrations',
                    ],
                    'limits' => [
                        'projects' => 5,
                        'members_per_project' => 10,
                    ],
                ],
            ]
        );

        // Enterprise Plan
        Plan::updateOrCreate(
            ['key' => 'enterprise'],
            [
                'name' => 'Enterprise',
                'price_cents' => 19900, // $199.00
                'interval' => 'month',
                'features' => [
                    'modules_allowed' => [
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
                        'projects' => 999,
                        'members_per_project' => 999,
                    ],
                ],
            ]
        );

        // Internal Plan (optional, for internal use only)
        Plan::updateOrCreate(
            ['key' => 'internal'],
            [
                'name' => 'Internal',
                'price_cents' => 0,
                'interval' => 'month',
                'features' => [
                    'modules_allowed' => [
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
                        'projects' => 999,
                        'members_per_project' => 999,
                    ],
                ],
            ]
        );
    }
}
