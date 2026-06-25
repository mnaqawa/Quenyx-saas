<?php

namespace App\Jobs\Compliance\Rag;

use App\Models\Compliance\Rag\RagVectorIndex;
use App\Services\Compliance\ComplianceCorpusQueryService;
use App\Services\Compliance\Rag\RagIndexService;
use App\Services\Compliance\Rag\VectorRetrievalProviderRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * QCIF Sprint 17 — incrementally (re)index a single corpus chunk descriptor into the RAG index.
 *
 * Idempotent (upsert per revision+entity+chunk_type). The descriptor must already be corpus-derived
 * and cited; tenant data must never be passed here. Supports dry-run (no persistence).
 */
class IndexRetrievalChunk implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    /**
     * @param  array<string, mixed>  $descriptor
     */
    public function __construct(
        public string $framework,
        public string $release,
        public array $descriptor,
        public bool $dryRun = false,
    ) {}

    public function handle(
        RagIndexService $indexer,
        ComplianceCorpusQueryService $corpus,
        VectorRetrievalProviderRegistry $vectorRegistry,
    ): void {
        if ($this->dryRun) {
            Log::info('rag.index.chunk.dry_run', ['framework' => $this->framework, 'release' => $this->release, 'entity_uuid' => $this->descriptor['entity_uuid'] ?? null]);

            return;
        }

        $release = $corpus->resolveRelease($this->framework, $this->release);
        $revision = $corpus->getActiveRevision($release);
        $providerKey = $vectorRegistry->providerKey() ?? 'none';

        $index = RagVectorIndex::query()->firstOrCreate(
            ['provider' => $providerKey, 'corpus_revision_id' => $revision->id],
            ['framework_release_id' => $release->id, 'status' => 'indexing'],
        );

        $chunk = $indexer->upsertChunk($index, $release, $revision, $this->descriptor, $providerKey);

        Log::info('rag.index.chunk', ['chunk_uuid' => $chunk->uuid, 'entity_uuid' => $chunk->entity_uuid]);
    }
}
