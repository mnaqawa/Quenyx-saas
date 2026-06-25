<?php

namespace App\Console\Commands\Rag;

use App\Exceptions\ComplianceCorpusNotFoundException;
use App\Services\Compliance\Rag\RagIndexService;
use Illuminate\Console\Command;

/**
 * QCIF Sprint 17 — index the approved active corpus revision into the RAG vector index.
 *
 *   php artisan compliance:rag:index --framework=nca-ecc --release=2:2024 --dry-run
 *   php artisan compliance:rag:index --framework=nca-ecc --release=2:2024
 */
class ComplianceRagIndexCommand extends Command
{
    protected $signature = 'compliance:rag:index
        {--framework=nca-ecc : Framework family key}
        {--release=2:2024 : Framework release version code}
        {--dry-run : Plan only; persist nothing}';

    protected $description = 'Index the approved active corpus revision into the RAG vector index (metadata-only, feature-flagged).';

    public function handle(RagIndexService $indexer): int
    {
        $framework = (string) $this->option('framework');
        $release = (string) $this->option('release');
        $dryRun = (bool) $this->option('dry-run');

        if (! (bool) config('ai.rag.enabled', false)) {
            $this->warn('RAG is disabled (RAG_ENABLED=false). Indexing metadata only; no vector backend will be consulted.');
        }

        try {
            $report = $indexer->indexRevision($framework, $release, $dryRun);
        } catch (ComplianceCorpusNotFoundException $e) {
            $this->error($e->getMessage());

            return Command::FAILURE;
        }

        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return Command::SUCCESS;
    }
}
