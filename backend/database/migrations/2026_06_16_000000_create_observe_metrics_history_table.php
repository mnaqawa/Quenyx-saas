<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('observe_metrics_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('projects')->onDelete('cascade');
            $table->string('host_name');
            $table->string('service_name');
            $table->string('metric', 20); // cpu|memory|disk|network
            $table->float('value'); // percentage 0..100
            $table->dateTime('recorded_at')->index();

            // Aggregation queries filter by workspace + metric over a time window.
            $table->index(['workspace_id', 'metric', 'recorded_at'], 'omh_ws_metric_time');
            $table->index(['workspace_id', 'host_name', 'metric', 'recorded_at'], 'omh_ws_host_metric_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('observe_metrics_history');
    }
};
