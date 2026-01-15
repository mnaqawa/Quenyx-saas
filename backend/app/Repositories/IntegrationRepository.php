<?php

namespace App\Repositories;

use App\Models\Integration;
use App\Models\IntegrationConfiguration;

class IntegrationRepository
{
    public function getIntegrations(): array
    {
        return Integration::query()
            ->orderBy('name')
            ->get()
            ->map(function (Integration $integration) {
                return [
                    'id' => (string) $integration->id,
                    'name' => $integration->name,
                    'description' => $integration->description,
                    'status' => $integration->status,
                    'endpoint' => $integration->endpoint ?? 'Not configured',
                    'primary_action' => $integration->primary_action,
                    'secondary_action' => $integration->secondary_action,
                ];
            })
            ->toArray();
    }

    public function getApiConfiguration(): array
    {
        $configuration = IntegrationConfiguration::query()->latest()->first();

        return [
            'api_keys' => [
                'github_pat' => $configuration?->github_pat ?? 'Not configured',
                'slack_webhook_url' => $configuration?->slack_webhook_url ?? 'Not configured',
            ],
            'webhook_endpoints' => [
                'primary' => $configuration?->primary_webhook_url ?? 'Not configured',
                'backup' => $configuration?->backup_webhook_url ?? 'Not configured',
            ],
        ];
    }
}
