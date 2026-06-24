<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QCIF Sprint 13 — Recommendation Engine.
 *
 * Deterministic, explainable remediation recommendations derived from gap findings. Append-only:
 * each recommendation records the requirement, gap status, evidence considered, rule, corpus
 * revision, and framework release it came from so it is reproducible. UUID is deterministic
 * (uuid5) which makes regeneration idempotent. NO AI columns, NO scores, NO probabilities.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compliance_recommendations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('framework_release_id')->nullable()->constrained('compliance_framework_releases')->nullOnDelete();
            $table->foreignId('corpus_revision_id')->nullable()->constrained('compliance_corpus_revisions')->nullOnDelete();
            $table->foreignId('gap_assessment_id')->nullable()->constrained('compliance_gap_assessments')->nullOnDelete();
            $table->foreignId('gap_finding_id')->nullable()->constrained('compliance_gap_findings')->nullOnDelete();

            $table->unsignedBigInteger('requirement_id');
            $table->unsignedBigInteger('control_id')->nullable();
            $table->unsignedBigInteger('domain_id')->nullable();

            $table->string('recommendation_type', 48);
            $table->string('priority', 16);
            $table->string('status', 24)->default('proposed');

            $table->string('title_en')->nullable();
            $table->string('title_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->text('description_ar')->nullable();
            $table->text('rationale_en')->nullable();
            $table->text('rationale_ar')->nullable();

            $table->json('action_items')->nullable();
            $table->string('source_rule', 64);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'framework_release_id'], 'comp_reco_project_release_idx');
            $table->index(['project_id', 'priority'], 'comp_reco_project_priority_idx');
            $table->index('requirement_id', 'comp_reco_requirement_idx');
            $table->index('control_id', 'comp_reco_control_idx');
            $table->index('gap_finding_id', 'comp_reco_finding_idx');
        });

        Schema::create('compliance_recommendation_actions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('recommendation_id')->constrained('compliance_recommendations')->cascadeOnDelete();

            $table->string('action_key', 64);
            $table->string('label_en')->nullable();
            $table->string('label_ar')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['recommendation_id', 'sort_order'], 'comp_reco_action_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_recommendation_actions');
        Schema::dropIfExists('compliance_recommendations');
    }
};
