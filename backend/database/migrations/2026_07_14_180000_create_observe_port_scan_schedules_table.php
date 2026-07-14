<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('observe_port_scan_schedules')) {
            return;
        }

        Schema::create('observe_port_scan_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('projects')->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->unsignedInteger('interval_minutes')->default(1440); // 24h
            $table->string('target_mode', 16)->default('public'); // public|private|auto
            $table->string('ports', 16)->default('top100'); // top100|all|range
            $table->string('ports_range', 500)->nullable();
            $table->string('protocol', 8)->default('tcp'); // tcp|udp
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->timestamps();

            $table->unique('workspace_id');
            $table->index(['enabled', 'next_run_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('observe_port_scan_schedules');
    }
};
