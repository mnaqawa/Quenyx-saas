<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 23 — Enterprise Automation & Incident Intelligence.
 *
 * The shared Automation Platform (workflows, runbooks, registry-driven executions, approvals,
 * rollback, learning) plus the QynReact Incident Workspace (incidents + timeline). All workspace-
 * scoped, UUID-addressed, and audited. Executions are SAFE BY DEFAULT: they record a deterministic
 * plan and run in `dry_run` mode unless live execution is explicitly enabled and approved — no
 * destructive action is ever performed automatically.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('severity', 16)->default('medium'); // critical|high|medium|low
            $table->string('status', 24)->default('open');      // open|investigating|mitigated|resolved|closed
            $table->string('source', 16)->default('manual');    // manual|alert
            $table->uuid('alert_uuid')->nullable();             // QynSight alert (deterministic UUIDv5)
            $table->uuid('asset_uuid')->nullable();             // QynAsset asset (deterministic UUIDv5)
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution')->nullable();
            $table->json('postmortem')->nullable();
            $table->timestamps();
            $table->index(['project_id', 'status']);
            $table->index(['project_id', 'severity']);
        });

        Schema::create('incident_timeline_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('incident_id')->constrained('incidents')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('at');
            $table->string('type', 48);      // note|status_change|automation|alert|asset|recommendation|evidence
            $table->string('category', 48)->nullable();
            $table->text('description');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['incident_id', 'at']);
        });

        Schema::create('automation_workflows', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 180);
            $table->string('description', 500)->nullable();
            $table->string('trigger_type', 24)->default('manual'); // manual|scheduled|event|api
            $table->string('schedule', 120)->nullable();           // cron-like, when trigger_type=scheduled
            $table->boolean('enabled')->default(true);
            $table->boolean('requires_approval')->default(true);
            $table->json('definition'); // {trigger, conditions[], actions[], verification, notification}
            $table->timestamps();
            $table->index(['project_id', 'trigger_type']);
        });

        Schema::create('automation_runbooks', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 180);
            $table->string('category', 64)->nullable();
            $table->string('description', 500)->nullable();
            $table->string('source', 24)->default('manual');  // manual|ai_assisted
            $table->string('status', 16)->default('draft');   // draft|published
            $table->json('definition'); // {steps:[{name, action_key, adapter_key, parameters}]}
            $table->timestamps();
            $table->index(['project_id', 'category']);
        });

        Schema::create('automation_executions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('workflow_id')->nullable()->constrained('automation_workflows')->nullOnDelete();
            $table->foreignId('runbook_id')->nullable()->constrained('automation_runbooks')->nullOnDelete();
            $table->foreignId('incident_id')->nullable()->constrained('incidents')->nullOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('adapter_key', 64);
            $table->string('action_key', 96)->nullable();
            $table->string('status', 24)->default('pending'); // pending|awaiting_approval|approved|running|succeeded|failed|rolled_back|cancelled|dry_run
            $table->string('mode', 12)->default('dry_run');    // dry_run|live
            $table->unsignedInteger('timeout_seconds')->default(60);
            $table->unsignedTinyInteger('max_retries')->default(0);
            $table->json('parameters')->nullable();
            $table->json('context')->nullable();
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->boolean('rolled_back')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();
            $table->index(['project_id', 'status']);
            $table->index(['project_id', 'created_at']);
        });

        Schema::create('automation_execution_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('execution_id')->constrained('automation_executions')->cascadeOnDelete();
            $table->unsignedInteger('step_index');
            $table->string('name', 180);
            $table->string('status', 24)->default('pending');
            $table->longText('output')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index('execution_id');
        });

        Schema::create('automation_approvals', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('execution_id')->constrained('automation_executions')->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16)->default('pending'); // pending|approved|rejected
            $table->string('reason', 500)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['project_id', 'status']);
        });

        Schema::create('automation_learning_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('execution_id')->nullable()->constrained('automation_executions')->nullOnDelete();
            $table->string('recommendation_key', 120)->nullable();
            $table->string('action_key', 96)->nullable();
            $table->string('outcome', 24); // success|failure|rolled_back|dry_run
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('operator_feedback', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['project_id', 'recommendation_key']);
            $table->index(['project_id', 'action_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_learning_records');
        Schema::dropIfExists('automation_approvals');
        Schema::dropIfExists('automation_execution_steps');
        Schema::dropIfExists('automation_executions');
        Schema::dropIfExists('automation_runbooks');
        Schema::dropIfExists('automation_workflows');
        Schema::dropIfExists('incident_timeline_entries');
        Schema::dropIfExists('incidents');
    }
};
