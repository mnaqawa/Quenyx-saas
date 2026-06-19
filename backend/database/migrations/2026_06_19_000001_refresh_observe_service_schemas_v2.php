<?php

use Database\Seeders\ObserveServiceDefinitionReadyPluginsSeeder;
use Database\Seeders\ObserveServiceDefinitionSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        (new ObserveServiceDefinitionSeeder)->run();
        (new ObserveServiceDefinitionReadyPluginsSeeder)->run();
    }

    public function down(): void
    {
        //
    }
};
