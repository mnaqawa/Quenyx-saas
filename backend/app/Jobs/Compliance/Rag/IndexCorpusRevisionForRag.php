<?php

namespace App\Jobs\Compliance\Rag;

use App\Services\Compliance\Rag\RagIndexService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * QCIF Sprint 17 — index the APPROVED ACTIVE corpus revision into the RAG vector index.
 *
 * Idempotent (chunks are upserted per revision+entity+chunk_type). NEVER indexes tenant evidence by
 * default. Supports dry-run (plan only). Embeddings are computed only when enabled, via the vector
 * provider (no direct OpenAI calls).
 */
class IndexCorpusRevisionForRag implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(
        public string $framework,
        public string $release,
        public bool $dryRun = false,
    ) {}

    public function handle(RagIndexService $indexer): void
    {
        $report = $indexer->indexRevision($this->framework, $this->release, $this->dryRun);

        Log::info('rag.index.revision', [
            'framework' => $this->framework,
            'release' => $this->release,
            'dry_run' => $this->dryRun,
            'report' => $report,
        ]);
    }
}
