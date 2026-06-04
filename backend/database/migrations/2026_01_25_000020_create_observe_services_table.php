<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('observe_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('projects')->onDelete('cascade');
            $table->string('engine_key', 50)->default('native');
            $table->string('engine_service_key'); // ${host_name}::${service_name}
            $table->string('host_name');
            $table->string('service_name');
            $table->string('state', 20); // ok|warning|critical|unknown|pending
            $table->dateTime('last_check_at')->nullable();
            $table->integer('duration_sec')->nullable();
            $table->string('attempt')->nullable(); // e.g., "1/3"
            $table->text('output')->nullable();
            $table->text('perfdata')->nullable();
            $table->timestamps();

            // Unique constraint: one service per workspace/engine combination
            $table->unique(['workspace_id', 'engine_key', 'engine_service_key'], 'observe_services_unique');
            
            // Indexes for filtering
            $table->index(['workspace_id', 'state']);
            $table->index(['workspace_id', 'host_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('observe_services');
    }
};
