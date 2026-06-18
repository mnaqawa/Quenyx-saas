<?php

/**
 * Regenerates a human-review markdown artifact from a domain batch JSON file.
 *
 * Usage:
 *   php build-review.php <domain-json-path> <review-md-path> [review-title]
 */
declare(strict_types=1);

if ($argc < 3) {
    fwrite(STDERR, "Usage: php build-review.php <domain.json> <review.md> [title]\n");
    exit(1);
}

$domainJsonPath = $argv[1];
$reviewOutPath = $argv[2];
$reviewTitle = $argv[3] ?? 'Domain Review';

if (! is_readable($domainJsonPath)) {
    fwrite(STDERR, "Domain batch not readable: {$domainJsonPath}\n");
    exit(1);
}

/** @var array<string, mixed> $batch */
$batch = json_decode(file_get_contents($domainJsonPath) ?: '', true, 512, JSON_THROW_ON_ERROR);

/** @var array<string, mixed> $domain */
$domain = $batch['domain'] ?? [];
/** @var list<array<string, mixed>> $controls */
$controls = is_array($domain['controls'] ?? null) ? $domain['controls'] : [];

$requirementCount = 0;
$sourceDocumentKeys = [];

foreach ($controls as $control) {
    $key = (string) ($control['source_document_key'] ?? '');
    if ($key !== '') {
        $sourceDocumentKeys[$key] = true;
    }
    $requirements = $control['requirements'] ?? [];
    if (! is_array($requirements)) {
        continue;
    }
    $requirementCount += count($requirements);
    foreach ($requirements as $requirement) {
        if (! is_array($requirement)) {
            continue;
        }
        $reqKey = (string) ($requirement['source_document_key'] ?? '');
        if ($reqKey !== '') {
            $sourceDocumentKeys[$reqKey] = true;
        }
    }
}

$domainKey = (string) ($domain['source_document_key'] ?? '');
if ($domainKey !== '') {
    $sourceDocumentKeys[$domainKey] = true;
}

$sourceDocuments = array_keys($sourceDocumentKeys);
sort($sourceDocuments);

$relativeJson = str_replace('\\', '/', $domainJsonPath);
if (str_contains($relativeJson, 'backend/database/corpus/')) {
    $relativeJson = substr($relativeJson, strpos($relativeJson, 'backend/database/corpus/'));
}

$lines = [];
$lines[] = '# '.$reviewTitle;
$lines[] = '';
$lines[] = 'Human-readable review artifact for NCA ECC-2:2024 domain batch.';
$lines[] = '';
$lines[] = 'Generated from: `'.$relativeJson.'`';
$lines[] = '';
$lines[] = 'Batch status: `'.(string) ($batch['status'] ?? 'unknown').'`';
$lines[] = '';

if (isset($batch['metadata']['pending_manual_review']) && is_array($batch['metadata']['pending_manual_review'])) {
    $lines[] = '## Pending manual review';
    $lines[] = '';
    foreach ($batch['metadata']['pending_manual_review'] as $item) {
        $lines[] = '- '.(string) $item;
    }
    $lines[] = '';
}

$lines[] = '## Summary';
$lines[] = '';
$lines[] = '| Item | Value |';
$lines[] = '|------|-------|';
$lines[] = '| Domain code | '.mdCell((string) ($domain['display_code'] ?? $domain['code'] ?? '')).' |';
$lines[] = '| Domain title (EN) | '.mdCell((string) ($domain['title_en'] ?? '')).' |';
$lines[] = '| Domain title (AR) | '.mdCell((string) ($domain['title_ar'] ?? '')).' |';
$lines[] = '| Controls count | '.count($controls).' |';
$lines[] = '| Requirements count | '.$requirementCount.' |';
$lines[] = '| Source documents used | '.mdCell(implode(', ', $sourceDocuments)).' |';
$lines[] = '';
$lines[] = '## Review checklist';
$lines[] = '';
$lines[] = '- [ ] Code matches official PDF';
$lines[] = '- [ ] English wording exact';
$lines[] = '- [ ] Arabic wording exact';
$lines[] = '- [ ] EN/AR pairing correct';
$lines[] = '- [ ] Source references present';
$lines[] = '- [ ] No invented text';
$lines[] = '- [ ] Approved for import';
$lines[] = '';
$lines[] = '---';
$lines[] = '';

foreach ($controls as $index => $control) {
    if ($index > 0) {
        $lines[] = '---';
        $lines[] = '';
    }

    $code = (string) ($control['code'] ?? '');
    $requirement = [];
    $requirements = $control['requirements'] ?? [];
    if (is_array($requirements) && isset($requirements[0]) && is_array($requirements[0])) {
        $requirement = $requirements[0];
    }

    $lines[] = '# Control '.$code;
    $lines[] = '';
    $lines[] = 'Display Code:';
    $lines[] = blockText((string) ($control['display_code'] ?? $code));
    $lines[] = '';
    $lines[] = 'English Title:';
    $lines[] = blockText((string) ($control['title_en'] ?? ''));
    $lines[] = '';
    $lines[] = 'Arabic Title:';
    $lines[] = blockText((string) ($control['title_ar'] ?? ''));
    $lines[] = '';
    $lines[] = 'English Requirement Text:';
    $lines[] = blockText((string) ($requirement['requirement_text_en'] ?? $control['description_en'] ?? ''));
    $lines[] = '';
    $lines[] = 'Arabic Requirement Text:';
    $lines[] = blockText((string) ($requirement['requirement_text_ar'] ?? $control['description_ar'] ?? ''));
    $lines[] = '';
    $lines[] = 'Source Document:';
    $lines[] = blockText((string) ($requirement['source_document_key'] ?? $control['source_document_key'] ?? ''));
    $lines[] = '';
    $lines[] = 'Source Page:';
    $lines[] = blockText((string) ($requirement['source_page'] ?? $control['source_page'] ?? ''));
    $lines[] = '';
    $lines[] = 'Official Reference:';
    $lines[] = blockText((string) ($requirement['official_reference'] ?? $control['official_reference'] ?? ''));
    $lines[] = '';
}

$reviewDir = dirname($reviewOutPath);
if (! is_dir($reviewDir) && ! mkdir($reviewDir, 0775, true) && ! is_dir($reviewDir)) {
    fwrite(STDERR, "Unable to create review directory: {$reviewDir}\n");
    exit(1);
}

file_put_contents($reviewOutPath, implode("\n", $lines)."\n");
fwrite(STDOUT, 'Wrote '.$reviewOutPath.' ('.count($controls)." controls, {$requirementCount} requirements)\n");

function blockText(string $value): string
{
    return $value === '' ? '(empty)' : $value;
}

function mdCell(string $value): string
{
    return str_replace('|', '\\|', $value);
}
