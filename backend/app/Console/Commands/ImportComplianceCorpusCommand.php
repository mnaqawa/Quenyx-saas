<?php

namespace App\Console\Commands;

use App\Enums\Compliance\ImportRunStatus;
use App\Enums\Compliance\ImportType;
use App\Models\Compliance\ComplianceCorpusImportRun;
use App\Services\Compliance\Corpus\ComplianceCorpusImportException;
use App\Services\Compliance\Corpus\ComplianceCorpusImporter;
use App\Services\Compliance\Corpus\ComplianceCorpusPayloadLoader;
use App\Services\Compliance\Corpus\ComplianceFrameworkReleaseResolver;
use Illuminate\Console\Command;
use InvalidArgumentException;

class ImportComplianceCorpusCommand extends Command
{
    protected $signature = 'compliance:import-corpus
        {file : Path to JSON/CSV corpus file or manifest.json with domain batches}
        {--framework=nca-ecc : Framework family key}
        {--release=2:2024 : Framework release version code}
        {--release-version= : Deprecated alias for --release (do not use --version; reserved by Artisan)}
        {--format= : json or csv (inferred from extension when omitted)}
        {--dry-run : Validate and simulate without persisting}
        {--rollback= : UUID of a completed import run to roll back}';

    protected $description = 'Import human-curated NCA ECC corpus data (single JSON/CSV or manifest.json with domain batches)';

    public function handle(
        ComplianceCorpusPayloadLoader $loader,
        ComplianceCorpusImporter $importer,
        ComplianceFrameworkReleaseResolver $resolver,
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

        $releaseCode = (string) ($this->option('release') ?: $this->option('release-version') ?: '2:2024');
        if ($this->option('release-version') && ! $this->option('release')) {
            $this->warn('--release-version is deprecated; use --release instead.');
        }

        try {
            $release = $resolver->resolveOrFail((string) $this->option('framework'), $releaseCode);
        } catch (ComplianceCorpusImportException $e) {
            $this->error($e->getMessage());

            return Command::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        $run = ComplianceCorpusImportRun::query()->create([
            'format' => strtolower($format),
            'source_path' => $file,
            'status' => ImportRunStatus::Pending,
            'import_type' => $dryRun ? ImportType::DryRun : ImportType::Import,
            'dry_run' => $dryRun,
            'framework_id' => $release->framework_id,
            'framework_release_id' => $release->id,
            'initiated_by' => null,
        ]);

        try {
            $payload = $loader->load($file, $format);
            $importer->importFromArray($payload, $release, $run, $dryRun);
        } catch (ComplianceCorpusImportException|InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return Command::FAILURE;
        }

        $run->refresh();
        $this->info("Import run {$run->uuid} completed with status: {$run->status->value}");
        $summary = $run->summary ?? $run->stats;
        if (is_array($summary)) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT));
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
            $rollbackRun = $importer->rollback($run);
        } catch (ComplianceCorpusImportException $e) {
            $this->error($e->getMessage());

            return Command::FAILURE;
        }

        $this->info("Import run {$uuid} marked rolled_back.");
        $this->info("Rollback audit run {$rollbackRun->uuid} completed with status: {$rollbackRun->status->value}");

        return Command::SUCCESS;
    }
}
