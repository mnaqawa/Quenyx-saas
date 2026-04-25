<?php

namespace Database\Seeders;

use App\Models\Integration;
use App\Models\IntegrationConfiguration;
use App\Models\Project;
use Illuminate\Database\Seeder;

class ProjectIntegrationConfigurationSeeder extends Seeder
{
    public function run(): void
    {
        Project::query()->with('owner')->each(function (Project $project) {
            $integrations = Integration::query()->inRandomOrder()->limit(2)->get();

            foreach ($integrations as $integration) {
                IntegrationConfiguration::updateOrCreate(
                    [
                        'project_id' => $project->id,
                        'integration_id' => $integration->id,
                    ],
                    [
                        'settings' => [
                            'endpoint' => $integration->endpoint ?? 'Not configured',
                            'api_key' => 'key_' . $project->id . '_' . $integration->id,
                            'webhook_url' => 'https://hooks.quenyx.local/' . $project->id,
                        ],
                    ]
                );
            }
        });
    }
}
