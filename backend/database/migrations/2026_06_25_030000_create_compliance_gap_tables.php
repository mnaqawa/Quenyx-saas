<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QCIF Sprint 12 — Gap Assessment & Evidence Correlation Engine.
 *
 * Immutable, append-only assessment history: an assessment run, its per-requirement findings
 * (with full explainability), and coverage snapshots at every scope. Every row records the exact
 * framework release + corpus revision evaluated so any assessment is reproducible. Deterministic
 * only — no AI columns, no scores, no probabilities.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compliance_gap_assessments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('framework_id')->nullable()->constrained('compliance_frameworks')->nullOnDelete();
            $table->foreignId('framework_release_id')->nullable()->constrained('compliance_framework_releases')->nullOnDelete();
            $table->foreignId('corpus_revision_id')->nullable()->constrained('compliance_corpus_revisions')->nullOnDelete();

            $table->timestamp('assessed_at');
            $table->json('summary')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['project_id', 'framework_release_id'], 'comp_gap_assess_project_release_idx');
            $table->index('assessed_at', 'comp_gap_assess_assessed_idx');
        });

        Schema::create('compliance_gap_findings', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('gap_assessment_id')->constrained('compliance_gap_assessments')->cascadeOnDelete();

            $table->unsignedBigInteger('requirement_id');
            $table->uuid('requirement_uuid');
            $table->unsignedBigInteger('control_id')->nullable();
            $table->uuid('control_uuid')->nullable();
            $table->unsignedBigInteger('domain_id')->nullable();
            $table->uuid('domain_uuid')->nullable();

            $table->unsignedBigInteger('framework_release_id')->nullable();
            $table->unsignedBigInteger('corpus_revision_id')->nullable();
            $table->uuid('corpus_revision_uuid')->nullable();

            $table->string('status', 40);
            $table->string('severity', 16);
            $table->string('evaluation_rule', 64);
            $table->text('reason')->nullable();
            $table->json('evidence_considered')->nullable();
            $table->json('evidence_ignored')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['gap_assessment_id', 'status'], 'comp_gap_find_assess_status_idx');
            $table->index('requirement_id', 'comp_gap_find_requirement_idx');
            $table->index('control_id', 'comp_gap_find_control_idx');
        });

        Schema::create('compliance_coverage_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('gap_assessment_id')->constrained('compliance_gap_assessments')->cascadeOnDelete();

            $table->string('scope_type', 24); // requirement | control | domain | framework | workspace
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->uuid('scope_uuid')->nullable();
            $table->string('scope_code')->nullable();

            $table->string('status', 40);
            $table->json('totals')->nullable();

            $table->unsignedBigInteger('framework_release_id')->nullable();
            $table->unsignedBigInteger('corpus_revision_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['gap_assessment_id', 'scope_type'], 'comp_cov_snap_assess_scope_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_coverage_snapshots');
        Schema::dropIfExists('compliance_gap_findings');
        Schema::dropIfExists('compliance_gap_assessments');
    }
};
