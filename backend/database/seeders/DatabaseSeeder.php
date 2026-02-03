<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            UserSeeder::class,
            ModuleSeeder::class,
            ModuleSubscriptionSeeder::class,
            IntegrationSeeder::class,
            IntegrationConfigurationSeeder::class,
            PlanSeeder::class,
            ProjectSeeder::class,
            ProjectSubscriptionSeeder::class,
            ProjectIntegrationConfigurationSeeder::class,
            ObserveServiceDefinitionSeeder::class,
            ObserveServiceDefinitionReadyPluginsSeeder::class,
        ]);
    }
}