<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Canonical service definition layer for ShieldObserve.
     * Engine-agnostic; args_schema is an ordered list (position matters for Nagios etc.).
     */
    public function up(): void
    {
        Schema::create('observe_service_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('engine', 50)->default('nagios');
            $table->string('service_key', 100); // Canonical ID: ping, http, tcp_port, custom
            $table->string('display_name');
            $table->string('check_command'); // Engine command name, e.g. check_ping
            $table->json('args_schema'); // Ordered list: [{position, key, default, required}, ...]
            $table->json('capability_flags'); // Array of flag names for UI + validation
            $table->string('status', 20)->default('active'); // active | disabled
            $table->timestamps();

            $table->unique(['engine', 'service_key'], 'observe_service_definitions_engine_key');
            $table->index(['engine', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('observe_service_definitions');
    }
};
