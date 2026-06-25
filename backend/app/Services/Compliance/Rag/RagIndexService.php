<?php

namespace App\Services\Compliance\Rag;

use App\DataTransferObjects\Compliance\Retrieval\RetrievalChunk;
use App\DataTransferObjects\Compliance\Retrieval\RetrievalCitation;
use App\Models\Compliance\ComplianceControl;
use App\Models\Compliance\ComplianceCorpusRevision;
use App\Models\Compliance\ComplianceFrameworkRelease;
use App\Models\Compliance\ComplianceRequirement;
use App\Models\Compliance\Rag\RagVectorChunk;
use App\Models\Compliance\Rag\RagVectorIndex;
use App\Services\Compliance\ComplianceCorpusQueryService;
use Illuminate\Support\Facades\DB;

/**
 * The RAG indexer (QCIF Sprint 17) — the ONLY DB boundary for building the vector index.
 *
 * It enumerates the APPROVED ACTIVE corpus revision (controls + requirements) into deterministic,
 * cited chunk metadata and upserts it idempotently (unique per revision+entity+chunk_type, keyed by
 * a content hash). It NEVER indexes tenant evidence unless explicitly enabled. Embeddings are only
 * computed when enabled, via the vector provider (which uses the AI Provider Registry — no direct
 * OpenAI calls here). Supports dry-run (plan only, persist nothing).
 */
class RagIndexService
{
    public function __construct(
        private readonly ComplianceCorpusQueryService $corpus,
        private readonly VectorRetrievalProviderRegistry $vectorRegistry,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function indexRevision(string $frameworkKey, string $releaseCode, bool $dryRun = false): array
    {
        $release = $this->corpus->resolveRelease($frameworkKey, $releaseCode);
        $revision = $this->corpus->getActiveRevision($release);
        $descriptors = $this->buildDescriptors($release, $revision);

        $providerKey = $this->vectorRegistry->providerKey() ?? 'none';

        if ($dryRun) {
            return [
                'dry_run' => true,
                'provider' => $providerKey,
                'framework_release_uuid' => $release->uuid,
                'corpus_revision_uuid' => $revision->uuid,
                'planned_chunks' => count($descriptors),
                'by_type' => $this->countByType($descriptors),
                'tenant_evidence_indexed' => false,
                'persisted' => false,
            ];
        }

        $index = RagVectorIndex::query()->updateOrCreate(
            ['provider' => $providerKey, 'corpus_revision_id' => $revision->id],
            [
                'framework_release_id' => $release->id,
                'status' => 'indexing',
                'embedding_model' => null,
                'metadata' => ['framework_key' => $frameworkKey, 'release_code' => $release->version_code],
            ],
        );

        $persisted = 0;
        foreach ($descriptors as $descriptor) {
            $this->upsertChunk($index, $release, $revision, $descriptor, $providerKey);
            $persisted++;
        }

        $embedReport = $this->maybeEmbed($descriptors);

        $index->update([
            'status' => 'indexed',
            'chunk_count' => $persisted,
            'embedding_model' => $embedReport['embedding_model'] ?? null,
            'dimensions' => $embedReport['dimensions'] ?? null,
            'metadata' => array_merge((array) $index->metadata, ['embed' => $embedReport]),
            'indexed_at' => now(),
        ]);

        return [
            'dry_run' => false,
            'provider' => $providerKey,
            'index_uuid' => $index->uuid,
            'framework_release_uuid' => $release->uuid,
            'corpus_revision_uuid' => $revision->uuid,
            'persisted_chunks' => $persisted,
            'by_type' => $this->countByType($descriptors),
            'embed' => $embedReport,
            'tenant_evidence_indexed' => false,
            'persisted' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function status(string $frameworkKey, string $releaseCode): array
    {
        $release = $this->corpus->resolveRelease($frameworkKey, $releaseCode);
        $revision = $this->corpus->getActiveRevision($release);
        $providerKey = $this->vectorRegistry->providerKey() ?? 'none';

        $index = RagVectorIndex::query()
            ->where('provider', $providerKey)
            ->where('corpus_revision_id', $revision->id)
            ->first();

        $chunkCount = RagVectorChunk::query()
            ->where('corpus_revision_id', $revision->id)
            ->where('provider', $providerKey)
            ->count();

        return [
            'rag_enabled' => (bool) config('ai.rag.enabled', false),
            'provider' => $providerKey,
            'framework_release_uuid' => $release->uuid,
            'corpus_revision_uuid' => $revision->uuid,
            'indexed' => $index !== null,
            'index_uuid' => $index?->uuid,
            'status' => $index?->status,
            'chunk_count' => $chunkCount,
            'embedding_model' => $index?->embedding_model,
            'indexed_at' => $index?->indexed_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteRevision(string $frameworkKey, string $releaseCode): array
    {
        $release = $this->corpus->resolveRelease($frameworkKey, $releaseCode);
        $revision = $this->corpus->getActiveRevision($release);
        $providerKey = $this->vectorRegistry->providerKey() ?? 'none';

        $chunkUuids = RagVectorChunk::query()
            ->where('corpus_revision_id', $revision->id)
            ->where('provider', $providerKey)
            ->pluck('uuid')
            ->all();

        $provider = $this->vectorRegistry->resolve();
        $externalReport = null;
        if ($provider !== null && $chunkUuids !== []) {
            try {
                $externalReport = $provider->delete(array_map('strval', $chunkUuids));
            } catch (\Throwable $e) {
                $externalReport = ['error' => $e->getMessage()];
            }
        }

        $deleted = RagVectorChunk::query()
            ->where('corpus_revision_id', $revision->id)
            ->where('provider', $providerKey)
            ->delete();

        RagVectorIndex::query()
            ->where('provider', $providerKey)
            ->where('corpus_revision_id', $revision->id)
            ->delete();

        return [
            'provider' => $providerKey,
            'corpus_revision_uuid' => $revision->uuid,
            'deleted_chunks' => $deleted,
            'external' => $externalReport,
        ];
    }

    /**
     * Upsert a single chunk descriptor (idempotent). Used by IndexRetrievalChunk too.
     *
     * @param  array<string, mixed>  $descriptor
     */
    public function upsertChunk(RagVectorIndex $index, ComplianceFrameworkRelease $release, ComplianceCorpusRevision $revision, array $descriptor, string $providerKey): RagVectorChunk
    {
        return RagVectorChunk::query()->updateOrCreate(
            [
                'corpus_revision_id' => $revision->id,
                'entity_uuid' => $descriptor['entity_uuid'],
                'chunk_type' => $descriptor['chunk_type'],
            ],
            [
                'rag_vector_index_id' => $index->id,
                'provider' => $providerKey,
                'framework_release_id' => $release->id,
                'entity_type' => $descriptor['entity_type'],
                'entity_code' => $descriptor['entity_code'],
                'content_hash' => $descriptor['content_hash'],
                'text_en' => $descriptor['text_en'],
                'text_ar' => $descriptor['text_ar'],
                'source_document_key' => $descriptor['source_document_key'],
                'official_reference' => $descriptor['official_reference'],
                'source_page' => $descriptor['source_page'],
                'metadata' => ['citations' => $descriptor['citations']],
                'indexed_at' => now(),
            ],
        );
    }

    // -------------------------------------------------------------------------
    // Descriptor building
    // -------------------------------------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    public function buildDescriptors(ComplianceFrameworkRelease $release, ComplianceCorpusRevision $revision): array
    {
        $descriptors = [];

        ComplianceControl::query()
            ->where('framework_release_id', $release->id)
            ->with('sourceDocument')
            ->orderBy('id')
            ->each(function (ComplianceControl $control) use (&$descriptors): void {
                $descriptors[] = $this->descriptor(
                    'control',
                    $control->uuid,
                    $control->display_code ?? $control->code,
                    $control->title_en,
                    $control->description_en ?? $control->title_en,
                    $control->title_ar,
                    $control->description_ar ?? $control->title_ar,
                    $control->sourceDocument?->key,
                    $control->official_reference,
                    $control->source_page,
                    $control->source_reference,
                );
            });

        ComplianceRequirement::query()
            ->where('framework_release_id', $release->id)
            ->with('sourceDocument')
            ->orderBy('id')
            ->each(function (ComplianceRequirement $req) use (&$descriptors): void {
                $descriptors[] = $this->descriptor(
                    'requirement',
                    $req->uuid,
                    $req->display_code ?? $req->code,
                    $req->title_en,
                    $req->requirement_text_en ?? $req->description_en ?? $req->title_en,
                    $req->title_ar,
                    $req->requirement_text_ar ?? $req->description_ar ?? $req->title_ar,
                    $req->sourceDocument?->key,
                    $req->official_reference,
                    $req->source_page,
                    $req->source_reference,
                );
            });

        return $descriptors;
    }

    /**
     * @return array<string, mixed>
     */
    private function descriptor(
        string $entityType,
        string $entityUuid,
        ?string $entityCode,
        ?string $titleEn,
        ?string $textEn,
        ?string $titleAr,
        ?string $textAr,
        ?string $sourceDocumentKey,
        ?string $officialReference,
        ?string $sourcePage,
        ?string $sourceReference,
    ): array {
        $citation = new RetrievalCitation(
            sourceDocumentKey: $sourceDocumentKey,
            sourceTitleEn: null,
            sourceTitleAr: null,
            officialReference: $officialReference,
            sourceReference: $sourceReference,
            sourcePage: $sourcePage,
            entityUuid: $entityUuid,
            entityType: $entityType,
            entityCode: $entityCode,
        );

        $textEn = $this->clean($textEn ?? $titleEn);
        $textAr = $this->clean($textAr ?? $titleAr);

        return [
            'entity_type' => $entityType,
            'entity_uuid' => $entityUuid,
            'entity_code' => $entityCode,
            'chunk_type' => $entityType,
            'text_en' => $textEn,
            'text_ar' => $textAr,
            'source_document_key' => $sourceDocumentKey,
            'official_reference' => $officialReference,
            'source_page' => $sourcePage,
            'content_hash' => hash('sha256', implode('|', [$entityType, $entityCode, $textEn, $textAr, $officialReference])),
            'citations' => [$citation->toArray()],
        ];
    }

    /**
     * Embeddings are optional and only run when enabled — via the vector provider (no direct calls).
     *
     * @param  list<array<string, mixed>>  $descriptors
     * @return array<string, mixed>
     */
    private function maybeEmbed(array $descriptors): array
    {
        $provider = $this->vectorRegistry->resolve();
        if ($provider === null || ! (bool) config('ai.rag.embeddings_enabled', false)) {
            return ['mode' => 'metadata_only', 'embedded' => 0];
        }

        $chunks = [];
        foreach ($descriptors as $d) {
            $chunks[] = new RetrievalChunk(
                uuid: (string) $d['entity_uuid'],
                chunkType: (string) $d['chunk_type'],
                entityType: $d['entity_type'],
                entityUuid: $d['entity_uuid'],
                entityCode: $d['entity_code'],
                textEn: $d['text_en'],
                textAr: $d['text_ar'],
                sourceDocumentKey: $d['source_document_key'],
                officialReference: $d['official_reference'],
                sourcePage: $d['source_page'],
                revisionUuid: null,
            );
        }

        try {
            return $provider->index($chunks);
        } catch (\Throwable $e) {
            return ['mode' => 'metadata_only', 'embedded' => 0, 'error' => $e->getMessage()];
        }
    }

    /**
     * @param  list<array<string, mixed>>  $descriptors
     * @return array<string, int>
     */
    private function countByType(array $descriptors): array
    {
        $counts = [];
        foreach ($descriptors as $d) {
            $type = (string) $d['entity_type'];
            $counts[$type] = ($counts[$type] ?? 0) + 1;
        }

        return $counts;
    }

    private function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
