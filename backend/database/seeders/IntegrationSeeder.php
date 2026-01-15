<?php

namespace Database\Seeders;

use App\Models\Integration;
use Illuminate\Database\Seeder;

class IntegrationSeeder extends Seeder
{
    public function run(): void
    {
        $integrations = [
            [
                'name' => 'GitHub',
                'description' => 'Connect your repositories for automated deployments and monitoring.',
                'status' => 'connected',
                'endpoint' => 'https://api.github.com/webhooks/qyncore',
                'primary_action' => 'Reconfigure',
                'secondary_action' => 'Test',
            ],
            [
                'name' => 'Slack',
                'description' => 'Receive alerts and notifications in your Slack channels.',
                'status' => 'connected',
                'endpoint' => 'https://hooks.slack.com/services/qyncore',
                'primary_action' => 'Reconfigure',
                'secondary_action' => 'Test',
            ],
            [
                'name' => 'Email SMTP',
                'description' => 'Configure email notifications and alerts.',
                'status' => 'configured',
                'endpoint' => 'smtp.qyncore.com:587',
                'primary_action' => 'Configure',
                'secondary_action' => null,
            ],
            [
                'name' => 'External Database',
                'description' => 'Connect external databases for monitoring and analytics.',
                'status' => 'disconnected',
                'endpoint' => 'Not configured',
                'primary_action' => 'Configure',
                'secondary_action' => null,
            ],
            [
                'name' => 'AWS CloudWatch',
                'description' => 'Monitor AWS resources and receive CloudWatch metrics.',
                'status' => 'disconnected',
                'endpoint' => 'Not configured',
                'primary_action' => 'Configure',
                'secondary_action' => null,
            ],
            [
                'name' => 'Custom Webhooks',
                'description' => 'Configure custom webhook endpoints for third-party integrations.',
                'status' => 'configured',
                'endpoint' => 'https://api.qyncore.com/webhooks/custom',
                'primary_action' => 'Configure',
                'secondary_action' => null,
            ],
        ];

        foreach ($integrations as $integration) {
            Integration::updateOrCreate(
                ['name' => $integration['name']],
                $integration
            );
        }
    }
}
