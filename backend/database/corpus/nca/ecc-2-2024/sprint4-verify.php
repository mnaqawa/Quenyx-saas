<?php

declare(strict_types=1);

require __DIR__.'/../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Compliance\ComplianceCorpusImportRun;
use App\Models\Compliance\ComplianceCorpusRevision;
use Illuminate\Support\Facades\DB;

echo "=== Entity counts ===\n";
echo 'domains: '.DB::table('compliance_domains')->count()."\n";
echo 'controls: '.DB::table('compliance_controls')->count()."\n";
echo 'requirements: '.DB::table('compliance_requirements')->count()."\n";
echo 'guidance_items: '.DB::table('compliance_guidance_items')->count()."\n";
echo 'revisions: '.ComplianceCorpusRevision::count()."\n";

$revision = ComplianceCorpusRevision::query()->orderBy('revision_number')->first();
if ($revision) {
    echo "\n=== Revision v{$revision->revision_number} ===\n";
    echo 'uuid: '.$revision->uuid."\n";
    echo 'status: '.$revision->status->value."\n";
    echo 'checksum_sha256: '.($revision->checksum_sha256 ?: '(empty)')."\n";
    echo 'import_run_id: '.$revision->import_run_id."\n";
    echo 'entity_counts: '.json_encode($revision->entity_counts, JSON_PRETTY_PRINT)."\n";
    $run = $revision->importRun;
    if ($run) {
        echo 'import_run_uuid: '.$run->uuid."\n";
        echo 'import_run_status: '.$run->status->value."\n";
    }
}

echo "\n=== Domains imported ===\n";
foreach (DB::table('compliance_domains')->orderBy('sort_order')->get(['display_code', 'title_en', 'code']) as $d) {
    echo "- {$d->display_code} ({$d->code}): {$d->title_en}\n";
}

echo "\n=== Validation ===\n";
$dupNormControls = DB::select('SELECT normalized_code, COUNT(*) c FROM compliance_controls GROUP BY normalized_code HAVING c > 1');
echo 'duplicate control normalized_codes: '.count($dupNormControls)."\n";
$dupNormReqs = DB::select('SELECT normalized_code, COUNT(*) c FROM compliance_requirements GROUP BY normalized_code HAVING c > 1');
echo 'duplicate requirement normalized_codes: '.count($dupNormReqs)."\n";
$orphanReqs = DB::select('SELECT r.id FROM compliance_requirements r LEFT JOIN compliance_controls c ON c.id = r.control_id WHERE c.id IS NULL');
echo 'orphan requirements: '.count($orphanReqs)."\n";
$missingSourceDocs = DB::select('SELECT c.id FROM compliance_controls c LEFT JOIN compliance_source_documents sd ON sd.id = c.source_document_id WHERE c.source_document_id IS NOT NULL AND sd.id IS NULL');
echo 'controls with missing source doc FK: '.count($missingSourceDocs)."\n";
$missingReqSourceDocs = DB::select('SELECT r.id FROM compliance_requirements r LEFT JOIN compliance_source_documents sd ON sd.id = r.source_document_id WHERE r.source_document_id IS NOT NULL AND sd.id IS NULL');
echo 'requirements with missing source doc FK: '.count($missingReqSourceDocs)."\n";
$importLogs = DB::table('compliance_corpus_import_logs')->count();
$failedLogs = DB::table('compliance_corpus_import_logs')->where('level', 'error')->count();
echo 'import log entries: '.$importLogs.' (errors: '.$failedLogs.")\n";
$failedRuns = ComplianceCorpusImportRun::query()->where('status', 'failed')->count();
echo 'failed import runs: '.$failedRuns."\n";
$rollbackRuns = ComplianceCorpusImportRun::query()->where('import_type', 'rollback')->count();
echo 'rollback runs: '.$rollbackRuns."\n";
