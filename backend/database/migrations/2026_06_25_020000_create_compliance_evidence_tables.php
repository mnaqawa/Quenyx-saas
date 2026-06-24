<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QCIF Sprint 11 — Evidence Intelligence Foundation.
 *
 * Defines tenant evidence as a first-class object: the evidence record itself, its
 * relationships to corpus entities (a single evidence can satisfy MANY requirements), and an
 * append-only lifecycle log. No file/blob/OCR columns — those are future sprints. Reuses the
 * existing corpus `compliance_evidence_types` catalog for typing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compliance_evidence', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('evidence_type_id')->nullable()
                ->constrained('compliance_evidence_types')->nullOnDelete();

            // Optional corpus scope + primary anchor (additional links live in the relationships table).
            $table->foreignId('framework_id')->nullable()->constrained('compliance_frameworks')->nullOnDelete();
            $table->foreignId('framework_release_id')->nullable()->constrained('compliance_framework_releases')->nullOnDelete();
            $table->foreignId('corpus_revision_id')->nullable()->constrained('compliance_corpus_revisions')->nullOnDelete();
            $table->foreignId('control_id')->nullable()->constrained('compliance_controls')->nullOnDelete();
            $table->foreignId('requirement_id')->nullable()->constrained('compliance_requirements')->nullOnDelete();

            $table->string('title');
            $table->text('description')->nullable();
            $table->string('source', 128)->nullable();
            $table->string('source_reference')->nullable();

            $table->string('status', 32)->default('registered');

            $table->timestamp('collected_at')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'status'], 'comp_evidence_project_status_idx');
            $table->index('framework_release_id', 'comp_evidence_release_idx');
            $table->index('evidence_type_id', 'comp_evidence_type_idx');
            $table->index('expires_at', 'comp_evidence_expires_idx');
        });

        Schema::create('compliance_evidence_relationships', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('evidence_id')->constrained('compliance_evidence')->cascadeOnDelete();

            // Target corpus entity: requirement | control | domain | framework.
            $table->string('entity_type', 32);
            $table->unsignedBigInteger('entity_id');
            $table->uuid('entity_uuid');
            $table->string('relationship_type', 32)->default('satisfies');

            $table->foreignId('framework_release_id')->nullable()->constrained('compliance_framework_releases')->nullOnDelete();
            $table->foreignId('corpus_revision_id')->nullable()->constrained('compliance_corpus_revisions')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['evidence_id', 'entity_type', 'entity_id', 'relationship_type'], 'comp_evidence_rel_unique');
            $table->index(['entity_type', 'entity_id'], 'comp_evidence_rel_entity_idx');
            $table->index('entity_uuid', 'comp_evidence_rel_uuid_idx');
        });

        Schema::create('compliance_evidence_lifecycle_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('evidence_id')->constrained('compliance_evidence')->cascadeOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->string('reason')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('evidence_id', 'comp_evidence_lifecycle_evidence_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_evidence_lifecycle_events');
        Schema::dropIfExists('compliance_evidence_relationships');
        Schema::dropIfExists('compliance_evidence');
    }
};
