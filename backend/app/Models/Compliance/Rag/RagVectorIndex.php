<?php

namespace App\Models\Compliance\Rag;

use App\Models\Compliance\ComplianceCorpusRevision;
use App\Models\Compliance\ComplianceFrameworkRelease;
use App\Models\Compliance\Concerns\HasComplianceUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * QCIF Sprint 17 — a RAG vector index for one (provider, corpus revision). Metadata only.
 */
class RagVectorIndex extends Model
{
    use HasComplianceUuid;

    protected $table = 'rag_vector_indexes';

    protected $fillable = [
        'uuid',
        'provider',
        'framework_release_id',
        'corpus_revision_id',
        'status',
        'embedding_model',
        'chunk_count',
        'dimensions',
        'metadata',
        'indexed_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'indexed_at' => 'datetime',
        'chunk_count' => 'integer',
        'dimensions' => 'integer',
    ];

    public function frameworkRelease(): BelongsTo
    {
        return $this->belongsTo(ComplianceFrameworkRelease::class, 'framework_release_id');
    }

    public function corpusRevision(): BelongsTo
    {
        return $this->belongsTo(ComplianceCorpusRevision::class, 'corpus_revision_id');
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(RagVectorChunk::class, 'rag_vector_index_id');
    }
}
