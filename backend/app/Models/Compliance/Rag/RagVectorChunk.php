<?php

namespace App\Models\Compliance\Rag;

use App\Models\Compliance\ComplianceCorpusRevision;
use App\Models\Compliance\ComplianceFrameworkRelease;
use App\Models\Compliance\Concerns\HasComplianceUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * QCIF Sprint 17 — a single indexed corpus chunk's metadata + provenance. NO tenant data, NO raw
 * embedding vector (only an optional external `vector_id`). Idempotent per (revision, entity, type).
 */
class RagVectorChunk extends Model
{
    use HasComplianceUuid;

    protected $table = 'rag_vector_chunks';

    protected $fillable = [
        'uuid',
        'rag_vector_index_id',
        'provider',
        'framework_release_id',
        'corpus_revision_id',
        'entity_type',
        'entity_uuid',
        'entity_code',
        'chunk_type',
        'content_hash',
        'text_en',
        'text_ar',
        'embedding_model',
        'vector_id',
        'source_document_key',
        'official_reference',
        'source_page',
        'metadata',
        'indexed_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'indexed_at' => 'datetime',
    ];

    public function index(): BelongsTo
    {
        return $this->belongsTo(RagVectorIndex::class, 'rag_vector_index_id');
    }

    public function frameworkRelease(): BelongsTo
    {
        return $this->belongsTo(ComplianceFrameworkRelease::class, 'framework_release_id');
    }

    public function corpusRevision(): BelongsTo
    {
        return $this->belongsTo(ComplianceCorpusRevision::class, 'corpus_revision_id');
    }
}
