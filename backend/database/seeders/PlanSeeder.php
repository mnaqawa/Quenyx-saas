<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    // NOTE (v1.0.0): 'qyncore' (platform core) and 'qynintegrations' (Integrations platform capability)
    // are NOT business modules. They remain in modules_allowed for backward compatibility: 'qyncore'
    // backs billing/subscriptions and 'qynintegrations' is the entitlement key that gates the
    // Integrations platform page at the gateway. Do not remove without migrating existing plans.
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
                        'qyncore',
                        'qynintegrations',
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
                        'qyncore',
                        'qynsight',
                        'qynasset',
                        'qynnotify',
                        'qynknow',
                        'qynsupport',
                        'qynintegrations',
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
                        'qyncore',
                        'qynsight',
                        'qynasset',
                        'qynreact',
                        'qynshield',
                        'qynnotify',
                        'qynva',
                        'qynknow',
                        'qynrun',
                        'qynbalance',
                        'qynsupport',
                        'qynintegrations',
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
                        'qyncore',
                        'qynsight',
                        'qynasset',
                        'qynreact',
                        'qynshield',
                        'qynnotify',
                        'qynva',
                        'qynknow',
                        'qynrun',
                        'qynbalance',
                        'qynsupport',
                        'qynintegrations',
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
