<?php

namespace App\Console\Commands;

use App\Enums\Compliance\ImportRunStatus;
use App\Models\Compliance\ComplianceCorpusImportRun;
use App\Models\Compliance\ComplianceFramework;
use App\Services\Compliance\Corpus\ComplianceCorpusImportException;
use App\Services\Compliance\Corpus\ComplianceCorpusImporter;
use App\Services\Compliance\Corpus\ComplianceCorpusPayloadLoader;
use Illuminate\Console\Command;

class ImportComplianceCorpusCommand extends Command
{
    protected $signature = 'compliance:import-corpus
        {file : Path to JSON or CSV corpus file}
        {--framework=nca-ecc : Framework family key}
        {--version=2:2024 : Framework version code}
        {--format= : json or csv (inferred from extension when omitted)}
        {--dry-run : Validate and simulate without persisting}
        {--rollback= : UUID of a completed import run to roll back}';

    protected $description = 'Import human-curated NCA ECC corpus data (QCIF Sprint 1)';

    public function handle(
        ComplianceCorpusPayloadLoader $loader,
        ComplianceCorpusImporter $importer,
    ): int {
        $rollbackUuid = $this->option('rollback');
        if (is_string($rollbackUuid) && $rollbackUuid !== '') {
            return $this->rollbackRun($importer, $rollbackUuid);
        }

        $file = (string) $this->argument('file');
        $format = (string) ($this->option('format') ?: pathinfo($file, PATHINFO_EXTENSION));
        if ($format === '') {
            $this->error('Unable to infer format; pass --format=json or --format=csv');

            return Command::FAILURE;
        }

        $framework = ComplianceFramework::query()
            ->where('key', (string) $this->option('framework'))
            ->where('version_code', (string) $this->option('version'))
            ->first();

        if ($framework === null) {
            $this->error('Target framework not found. Run ComplianceCorpusSeeder first.');

            return Command::FAILURE;
        }

        $run = ComplianceCorpusImportRun::query()->create([
            'format' => strtolower($format),
            'source_path' => $file,
            'status' => ImportRunStatus::Pending,
            'dry_run' => (bool) $this->option('dry-run'),
            'initiated_by' => null,
        ]);

        try {
            $payload = $loader->load($file, $format);
            $importer->importFromArray($payload, $framework, $run, (bool) $this->option('dry-run'));
        } catch (ComplianceCorpusImportException $e) {
            $this->error($e->getMessage());

            return Command::FAILURE;
        }

        $run->refresh();
        $this->info("Import run {$run->uuid} completed with status: {$run->status->value}");
        if (is_array($run->stats)) {
            $this->line(json_encode($run->stats, JSON_PRETTY_PRINT));
        }

        return Command::SUCCESS;
    }

    private function rollbackRun(ComplianceCorpusImporter $importer, string $uuid): int
    {
        $run = ComplianceCorpusImportRun::query()->where('uuid', $uuid)->first();
        if ($run === null) {
            $this->error('Import run not found.');

            return Command::FAILURE;
        }

        try {
            $importer->rollback($run);
        } catch (ComplianceCorpusImportException $e) {
            $this->error($e->getMessage());

            return Command::FAILURE;
        }

        $this->info("Import run {$uuid} rolled back.");

        return Command::SUCCESS;
    }
}
