<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QCIF Sprint 1 — NCA ECC-2:2024 structured corpus foundation.
 *
 * Global, tenant-agnostic regulatory knowledge. No scoring, evidence artifacts,
 * or tenant assessments in this sprint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compliance_frameworks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('key', 64);
            $table->string('code', 32);
            $table->string('slug', 128);
            $table->string('version_code', 32);
            $table->string('title_en');
            $table->string('title_ar');
            $table->text('description_en')->nullable();
            $table->text('description_ar')->nullable();
            $table->string('authority');
            $table->string('authority_en')->nullable();
            $table->string('authority_ar')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->string('status', 32)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('deprecated_at')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->text('source_reference')->nullable();
            $table->json('tags')->nullable();
            $table->foreignId('superseded_by_framework_id')->nullable()
                ->constrained('compliance_frameworks')->nullOnDelete();
            $table->json('migration_reference')->nullable();
            $table->timestamps();

            $table->unique(['key', 'version_code']);
            $table->unique('slug');
            $table->index('status');
            $table->index('effective_from');
        });

        Schema::create('compliance_control_objectives', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('code', 64)->unique();
            $table->string('slug', 128)->unique();
            $table->string('title_en');
            $table->string('title_ar');
            $table->text('description_en')->nullable();
            $table->text('description_ar')->nullable();
            $table->string('category_en')->nullable();
            $table->string('category_ar')->nullable();
            $table->string('status', 32)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('deprecated_at')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->text('source_reference')->nullable();
            $table->json('tags')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('category_en');
        });

        Schema::create('compliance_domains', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('framework_id')->constrained('compliance_frameworks')->cascadeOnDelete();
            $table->foreignId('parent_domain_id')->nullable()
                ->constrained('compliance_domains')->nullOnDelete();
            $table->string('code', 64);
            $table->string('slug', 128);
            $table->string('title_en');
            $table->string('title_ar');
            $table->text('description_en')->nullable();
            $table->text('description_ar')->nullable();
            $table->string('status', 32)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('deprecated_at')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->text('source_reference')->nullable();
            $table->json('tags')->nullable();
            $table->foreignId('superseded_by_domain_id')->nullable()
                ->constrained('compliance_domains')->nullOnDelete();
            $table->json('migration_reference')->nullable();
            $table->timestamps();

            $table->unique(['framework_id', 'code']);
            $table->index(['framework_id', 'parent_domain_id']);
            $table->index('status');
        });

        Schema::create('compliance_controls', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('framework_id')->constrained('compliance_frameworks')->cascadeOnDelete();
            $table->foreignId('domain_id')->constrained('compliance_domains')->cascadeOnDelete();
            $table->foreignId('control_objective_id')->nullable()
                ->constrained('compliance_control_objectives')->nullOnDelete();
            $table->string('code', 64);
            $table->string('slug', 128);
            $table->string('title_en');
            $table->string('title_ar');
            $table->text('description_en')->nullable();
            $table->text('description_ar')->nullable();
            $table->string('control_type', 32)->nullable();
            $table->string('status', 32)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('deprecated_at')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->text('source_reference')->nullable();
            $table->json('tags')->nullable();
            $table->foreignId('superseded_by_control_id')->nullable()
                ->constrained('compliance_controls')->nullOnDelete();
            $table->json('migration_reference')->nullable();
            $table->timestamps();

            $table->unique(['framework_id', 'code']);
            $table->index(['framework_id', 'domain_id']);
            $table->index('control_objective_id');
            $table->index('status');
        });

        Schema::create('compliance_requirements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('control_id')->constrained('compliance_controls')->cascadeOnDelete();
            $table->string('code', 64);
            $table->string('slug', 128);
            $table->string('title_en');
            $table->string('title_ar');
            $table->text('description_en')->nullable();
            $table->text('description_ar')->nullable();
            $table->text('requirement_text_en');
            $table->text('requirement_text_ar');
            $table->string('status', 32)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('deprecated_at')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->text('source_reference')->nullable();
            $table->json('tags')->nullable();
            $table->foreignId('superseded_by_requirement_id')->nullable()
                ->constrained('compliance_requirements')->nullOnDelete();
            $table->json('migration_reference')->nullable();
            $table->timestamps();

            $table->unique(['control_id', 'code']);
            $table->index('status');
        });

        Schema::create('compliance_guidance_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('requirement_id')->constrained('compliance_requirements')->cascadeOnDelete();
            $table->string('code', 64);
            $table->string('slug', 128);
            $table->text('guidance_en');
            $table->text('guidance_ar');
            $table->string('guidance_type', 32)->default('implementation');
            $table->string('status', 32)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('deprecated_at')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->text('source_reference')->nullable();
            $table->json('tags')->nullable();
            $table->timestamps();

            $table->unique(['requirement_id', 'code']);
            $table->index('guidance_type');
            $table->index('status');
        });

        Schema::create('compliance_evidence_types', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('key', 64)->unique();
            $table->string('code', 32);
            $table->string('slug', 128)->unique();
            $table->string('title_en');
            $table->string('title_ar');
            $table->text('description_en')->nullable();
            $table->text('description_ar')->nullable();
            $table->string('status', 32)->default('published');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('deprecated_at')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->text('source_reference')->nullable();
            $table->json('tags')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('compliance_evidence_expectations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('requirement_id')->constrained('compliance_requirements')->cascadeOnDelete();
            $table->foreignId('evidence_type_id')->constrained('compliance_evidence_types')->restrictOnDelete();
            $table->string('code', 64);
            $table->string('slug', 128);
            $table->string('title_en')->nullable();
            $table->string('title_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->text('description_ar')->nullable();
            $table->boolean('is_required')->default(true);
            $table->unsignedSmallInteger('recency_days')->nullable();
            $table->string('status', 32)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('deprecated_at')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->text('source_reference')->nullable();
            $table->json('tags')->nullable();
            $table->timestamps();

            $table->unique(['requirement_id', 'code']);
            $table->index(['requirement_id', 'evidence_type_id']);
            $table->index('status');
        });

        Schema::create('compliance_control_objective_mappings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('control_objective_id')->constrained('compliance_control_objectives')->cascadeOnDelete();
            $table->foreignId('control_id')->constrained('compliance_controls')->cascadeOnDelete();
            $table->string('mapping_type', 32)->default('related');
            $table->string('confidence', 16)->default('high');
            $table->text('notes_en')->nullable();
            $table->text('notes_ar')->nullable();
            $table->string('status', 32)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->text('source_reference')->nullable();
            $table->timestamps();

            $table->unique(['control_objective_id', 'control_id'], 'cco_mapping_unique');
            $table->index('status');
        });

        Schema::create('compliance_corpus_import_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('framework_id')->nullable()->constrained('compliance_frameworks')->nullOnDelete();
            $table->string('format', 16);
            $table->string('source_path')->nullable();
            $table->string('content_hash', 64)->nullable();
            $table->string('status', 32)->default('pending');
            $table->boolean('dry_run')->default(false);
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('stats')->nullable();
            $table->json('rollback_data')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('content_hash');
            $table->index('framework_id');
        });

        Schema::create('compliance_corpus_import_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_run_id')->constrained('compliance_corpus_import_runs')->cascadeOnDelete();
            $table->string('level', 16);
            $table->string('entity_type', 64)->nullable();
            $table->string('entity_key', 128)->nullable();
            $table->text('message');
            $table->unsignedInteger('row_number')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['import_run_id', 'level']);
            $table->index('entity_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_corpus_import_logs');
        Schema::dropIfExists('compliance_corpus_import_runs');
        Schema::dropIfExists('compliance_control_objective_mappings');
        Schema::dropIfExists('compliance_evidence_expectations');
        Schema::dropIfExists('compliance_evidence_types');
        Schema::dropIfExists('compliance_guidance_items');
        Schema::dropIfExists('compliance_requirements');
        Schema::dropIfExists('compliance_controls');
        Schema::dropIfExists('compliance_domains');
        Schema::dropIfExists('compliance_control_objectives');
        Schema::dropIfExists('compliance_frameworks');
    }
};
