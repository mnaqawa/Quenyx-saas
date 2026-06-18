<?php

namespace App\Console\Commands;

use App\Services\Compliance\Corpus\ComplianceCorpusImportException;
use App\Services\Compliance\Corpus\ComplianceFrameworkReleaseResolver;
use App\Services\Compliance\Corpus\ComplianceSourceDocumentRegistrar;
use Illuminate\Console\Command;

class SeedComplianceSourceDocumentsCommand extends Command
{
    protected $signature = 'compliance:seed-source-documents
        {file : Path to source-documents.json}
        {--framework=nca-ecc : Framework family key}
        {--release=2:2024 : Framework release version code}
        {--release-version= : Deprecated alias for --release}';

    protected $description = 'Register official NCA ECC source document metadata (no file upload)';

    public function handle(
        ComplianceSourceDocumentRegistrar $registrar,
        ComplianceFrameworkReleaseResolver $resolver,
    ): int {
        $releaseCode = (string) ($this->option('release') ?: $this->option('release-version') ?: '2:2024');

        try {
            $release = $resolver->resolveOrFail((string) $this->option('framework'), $releaseCode);
            $result = $registrar->registerFromFile((string) $this->argument('file'), $release);
        } catch (ComplianceCorpusImportException $e) {
            $this->error($e->getMessage());

            return Command::FAILURE;
        }

        $this->info("Source documents registered for {$release->stableRef()}");
        $this->line("Created: {$result['created']}, Updated: {$result['updated']}");
        $this->line('Keys: '.implode(', ', $result['keys']));

        return Command::SUCCESS;
    }
}
