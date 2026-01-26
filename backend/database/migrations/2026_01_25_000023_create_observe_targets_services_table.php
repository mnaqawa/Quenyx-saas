<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('observe_targets_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('projects')->onDelete('cascade');
            $table->foreignId('host_id')->constrained('observe_targets_hosts')->onDelete('cascade');
            $table->string('name'); // Service description
            $table->string('check_command'); // e.g., check_http, check_ping, check_load
            $table->json('check_args')->nullable(); // Optional arguments
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            // Unique constraint: one service name per host
            $table->unique(['host_id', 'name'], 'observe_targets_services_unique');
            
            // Index for filtering
            $table->index(['workspace_id', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('observe_targets_services');
    }
};
