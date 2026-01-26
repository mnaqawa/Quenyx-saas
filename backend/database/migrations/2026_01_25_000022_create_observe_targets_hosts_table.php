<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('observe_targets_hosts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('projects')->onDelete('cascade');
            $table->string('name'); // Unique per workspace
            $table->string('address'); // IP or hostname
            $table->string('check_command')->default('check-host-alive');
            $table->json('tags')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            // Unique constraint: one host name per workspace
            $table->unique(['workspace_id', 'name'], 'observe_targets_hosts_unique');
            
            // Index for filtering
            $table->index(['workspace_id', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('observe_targets_hosts');
    }
};
