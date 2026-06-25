<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QCIF Sprint 17 — RAG Runtime & Vector Provider Foundation.
 *
 * Metadata-only tables for the RAG vector index. These store the CORPUS chunk metadata + provenance
 * that a vector backend would index — NOT tenant data and NOT raw embedding vectors (a real vector
 * store / pgvector would hold those; `vector_id` references the external vector when one exists).
 * Idempotent: a chunk is unique per (corpus_revision_id, entity_uuid, chunk_type). UUID-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rag_vector_indexes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('provider', 48);
            $table->foreignId('framework_release_id')->nullable()->constrained('compliance_framework_releases')->nullOnDelete();
            $table->foreignId('corpus_revision_id')->nullable()->constrained('compliance_corpus_revisions')->nullOnDelete();

            $table->string('status', 24)->default('pending');
            $table->string('embedding_model')->nullable();
            $table->unsignedInteger('chunk_count')->default(0);
            $table->unsignedInteger('dimensions')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('indexed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'corpus_revision_id'], 'rag_index_provider_revision_uq');
            $table->index(['framework_release_id', 'provider'], 'rag_index_release_provider_idx');
        });

        Schema::create('rag_vector_chunks', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('rag_vector_index_id')->nullable()->constrained('rag_vector_indexes')->cascadeOnDelete();
            $table->string('provider', 48);
            $table->foreignId('framework_release_id')->nullable()->constrained('compliance_framework_releases')->nullOnDelete();
            $table->foreignId('corpus_revision_id')->nullable()->constrained('compliance_corpus_revisions')->nullOnDelete();

            $table->string('entity_type', 48);
            $table->uuid('entity_uuid');
            $table->string('entity_code', 64)->nullable();
            $table->string('chunk_type', 48);
            $table->string('content_hash', 64);

            $table->text('text_en')->nullable();
            $table->text('text_ar')->nullable();

            $table->string('embedding_model')->nullable();
            $table->string('vector_id')->nullable();

            $table->string('source_document_key')->nullable();
            $table->string('official_reference')->nullable();
            $table->string('source_page')->nullable();

            $table->json('metadata')->nullable();
            $table->timestamp('indexed_at')->nullable();
            $table->timestamps();

            $table->unique(['corpus_revision_id', 'entity_uuid', 'chunk_type'], 'rag_chunk_revision_entity_uq');
            $table->index(['framework_release_id', 'provider'], 'rag_chunk_release_provider_idx');
            $table->index('entity_uuid', 'rag_chunk_entity_idx');
            $table->index('content_hash', 'rag_chunk_hash_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rag_vector_chunks');
        Schema::dropIfExists('rag_vector_indexes');
    }
};
