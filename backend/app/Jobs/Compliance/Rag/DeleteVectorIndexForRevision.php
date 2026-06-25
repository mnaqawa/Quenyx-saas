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
 * QCIF Sprint 17 — delete the RAG vector index (metadata + any external vectors) for the active
 * revision of a framework release. Idempotent.
 */
class DeleteVectorIndexForRevision implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public string $framework,
        public string $release,
    ) {}

    public function handle(RagIndexService $indexer): void
    {
        $report = $indexer->deleteRevision($this->framework, $this->release);

        Log::info('rag.index.delete', ['framework' => $this->framework, 'release' => $this->release, 'report' => $report]);
    }
}
