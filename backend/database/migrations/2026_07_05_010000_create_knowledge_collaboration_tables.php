<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 24 — Enterprise Knowledge & Collaboration Platform.
 *
 * Shared, registry-driven knowledge (documents indexed from registered sources), Service Desk tickets,
 * intelligent notifications, and a reusable collaboration layer (comments/mentions/watchers/assignments)
 * usable by EVERY module. All workspace-scoped (project_id), UUID-addressed, and audited. The Knowledge
 * Graph v2, Enterprise Search, and Global Timeline are deterministic READ-MODELS over these + existing
 * tables (incidents, automation_*) — no data is duplicated or fabricated.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Knowledge documents — the Internal Knowledge Base + the indexed projection of every external
        // source registered in the Knowledge Source Registry. `source_key` ties a row to its registered
        // provider; `external_ref` is the provider-native id (UUID/url/path). Real indexed data only.
        if (! Schema::hasTable('knowledge_documents')) {
            Schema::create('knowledge_documents', function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
                $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('source_key', 64)->default('internal'); // registered Knowledge Source key
                $table->string('external_ref', 500)->nullable();        // provider-native id/url/path
                $table->string('title', 250);
                $table->string('slug', 250)->nullable();
                $table->string('format', 16)->default('markdown');      // markdown|html|pdf|text
                $table->string('category', 96)->nullable();
                $table->string('status', 16)->default('published');     // draft|published|archived
                $table->longText('body')->nullable();
                $table->json('tags')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('indexed_at')->nullable();
                $table->timestamps();
                $table->index(['project_id', 'source_key']);
                $table->index(['project_id', 'status']);
                $table->index(['project_id', 'category']);
            });
        }

        // Service Desk tickets (QynSupport). Cross-module links to incidents/assets are stored as
        // deterministic UUID soft-references (no module branching). Intelligence suggestions are
        // evidence-based and stored separately from operator-confirmed fields.
        if (! Schema::hasTable('tickets')) {
            Schema::create('tickets', function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
                $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
                $table->string('reference', 32)->nullable();            // human ref e.g. TCK-AB12CD
                $table->string('subject', 250);
                $table->text('description')->nullable();
                $table->string('category', 64)->nullable();
                $table->string('priority', 16)->default('medium');      // critical|high|medium|low
                $table->string('impact', 16)->nullable();               // org|service|team|individual
                $table->string('status', 24)->default('open');          // open|in_progress|pending|resolved|closed
                $table->string('source', 24)->default('manual');        // manual|email|api|alert
                $table->uuid('incident_uuid')->nullable();              // QynReact incident soft-ref
                $table->uuid('asset_uuid')->nullable();                 // QynAsset asset soft-ref
                $table->timestamp('sla_due_at')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->json('ai_suggestions')->nullable();             // last evidence-based suggestions
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->index(['project_id', 'status']);
                $table->index(['project_id', 'priority']);
                $table->index(['project_id', 'assigned_to']);
            });
        }

        // Intelligent notifications (QynNotify). Deduplication + correlation are deterministic and
        // auditable: `dedup_key` collapses duplicates, `correlation_id` groups related signals.
        if (! Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
                $table->string('type', 64)->default('event');           // event|alert|incident|ticket|automation|digest
                $table->string('severity', 16)->default('info');        // critical|high|medium|low|info
                $table->string('title', 250);
                $table->text('body')->nullable();
                $table->string('source', 64)->default('platform');      // originating module/source
                $table->string('dedup_key', 191)->nullable();           // deterministic duplicate key
                $table->string('correlation_id', 191)->nullable();      // deterministic correlation group
                $table->unsignedSmallInteger('urgency_score')->default(0); // 0-100, deterministic
                $table->unsignedInteger('dedup_count')->default(1);     // duplicates collapsed into this one
                $table->string('channel', 24)->nullable();              // in_app|email|sms|webhook (selected)
                $table->string('status', 16)->default('new');           // new|read|escalated|suppressed
                $table->json('recipients')->nullable();                 // selected recipient user uuids
                $table->json('escalation')->nullable();                 // computed escalation path
                $table->json('metadata')->nullable();
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
                $table->index(['project_id', 'status']);
                $table->index(['project_id', 'correlation_id']);
                $table->index(['project_id', 'dedup_key']);
            });
        }

        // Reusable Collaboration layer — comments/mentions on ANY entity (incident, ticket, document,
        // execution, asset…) addressed polymorphically by (entity_type, entity_uuid). No per-module
        // comment system: every module reuses this.
        if (! Schema::hasTable('collaboration_comments')) {
            Schema::create('collaboration_comments', function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
                $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('entity_type', 64);   // incident|ticket|document|execution|asset|alert|workflow|runbook
                $table->uuid('entity_uuid');
                $table->text('body');
                $table->json('mentions')->nullable(); // user uuids mentioned
                $table->timestamps();
                $table->index(['project_id', 'entity_type', 'entity_uuid']);
            });
        }

        // Collaboration participants — watchers / assignees / owners on any entity. Reusable everywhere.
        if (! Schema::hasTable('collaboration_participants')) {
            Schema::create('collaboration_participants', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('entity_type', 64);
                $table->uuid('entity_uuid');
                $table->string('role', 16)->default('watcher'); // watcher|assignee|owner
                $table->timestamps();
                $table->unique(['project_id', 'entity_type', 'entity_uuid', 'user_id', 'role'], 'collab_participant_unique');
                $table->index(['project_id', 'entity_type', 'entity_uuid']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('collaboration_participants');
        Schema::dropIfExists('collaboration_comments');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('tickets');
        Schema::dropIfExists('knowledge_documents');
    }
};
