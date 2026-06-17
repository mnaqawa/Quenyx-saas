<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('observe_alert_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('projects')->cascadeOnDelete();
            $table->string('name', 160);
            $table->enum('severity', ['critical', 'warning'])->default('warning');
            $table->enum('target_scope', ['all', 'selected_target', 'selected_service'])->default('all');
            $table->unsignedBigInteger('target_host_id')->nullable();
            $table->string('target_service_key', 64)->nullable();
            $table->string('metric_condition', 64);
            $table->string('operator', 16)->default('>');
            $table->decimal('threshold_value', 12, 4);
            $table->unsignedInteger('duration_seconds')->default(300);
            $table->string('notification_channel', 64)->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_triggered_at')->nullable();
            $table->unsignedInteger('trigger_count_7d')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['workspace_id', 'enabled']);
        });

        Schema::create('observe_alert_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('alert_rule_id')->nullable()->constrained('observe_alert_rules')->nullOnDelete();
            $table->enum('severity', ['critical', 'warning', 'info'])->default('warning');
            $table->string('title', 200);
            $table->text('message')->nullable();
            $table->enum('status', ['active', 'acknowledged', 'resolved'])->default('active');
            $table->timestamp('triggered_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'status', 'triggered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('observe_alert_events');
        Schema::dropIfExists('observe_alert_rules');
    }
};
