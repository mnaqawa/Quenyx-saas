<?php

namespace App\Console\Commands\Rag;

use App\Exceptions\ComplianceCorpusNotFoundException;
use App\Services\Compliance\Rag\RagIndexService;
use Illuminate\Console\Command;

/**
 * QCIF Sprint 17 — report the RAG index status for a framework release's active revision.
 *
 *   php artisan compliance:rag:status --framework=nca-ecc --release=2:2024
 */
class ComplianceRagStatusCommand extends Command
{
    protected $signature = 'compliance:rag:status
        {--framework=nca-ecc : Framework family key}
        {--release=2:2024 : Framework release version code}';

    protected $description = 'Show RAG index status (provider, chunk count, embedding model) for a release.';

    public function handle(RagIndexService $indexer): int
    {
        try {
            $report = $indexer->status((string) $this->option('framework'), (string) $this->option('release'));
        } catch (ComplianceCorpusNotFoundException $e) {
            $this->error($e->getMessage());

            return Command::FAILURE;
        }

        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return Command::SUCCESS;
    }
}
