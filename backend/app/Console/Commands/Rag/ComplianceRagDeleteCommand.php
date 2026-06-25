<?php

namespace App\Console\Commands\Rag;

use App\Exceptions\ComplianceCorpusNotFoundException;
use App\Services\Compliance\Rag\RagIndexService;
use Illuminate\Console\Command;

/**
 * QCIF Sprint 17 — delete the RAG vector index for a framework release's active revision.
 *
 *   php artisan compliance:rag:delete --framework=nca-ecc --release=2:2024
 */
class ComplianceRagDeleteCommand extends Command
{
    protected $signature = 'compliance:rag:delete
        {--framework=nca-ecc : Framework family key}
        {--release=2:2024 : Framework release version code}';

    protected $description = 'Delete the RAG vector index (metadata + any external vectors) for a release.';

    public function handle(RagIndexService $indexer): int
    {
        try {
            $report = $indexer->deleteRevision((string) $this->option('framework'), (string) $this->option('release'));
        } catch (ComplianceCorpusNotFoundException $e) {
            $this->error($e->getMessage());

            return Command::FAILURE;
        }

        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return Command::SUCCESS;
    }
}
