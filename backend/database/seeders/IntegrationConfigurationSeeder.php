<?php

namespace Database\Seeders;

use App\Models\IntegrationConfiguration;
use Illuminate\Database\Seeder;

class IntegrationConfigurationSeeder extends Seeder
{
    public function run(): void
    {
        IntegrationConfiguration::updateOrCreate(
            ['id' => 1],
            [
                'github_pat' => 'ghp_xxxxxxxxxxxxxxxxxxxxxxx',
                'slack_webhook_url' => 'https://hooks.slack.com/services/...',
                'primary_webhook_url' => 'https://your-app.com/webhooks/qyncore',
                'backup_webhook_url' => 'https://backup.your-app.com/webhooks/qyncore',
            ]
        );
    }
}
