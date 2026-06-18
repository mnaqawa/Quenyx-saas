<?php

/**
 * Regenerates the Governance human-review artifact from domain.json.
 *
 * Run from repository root or this directory:
 *   php backend/database/corpus/nca/ecc-2-2024/governance/build-review.php
 *   php build-review.php
 */
declare(strict_types=1);

$domainJsonPath = __DIR__.'/domain.json';
$reviewOutPath = dirname(__DIR__, 6).'/docs/reviews/NCA_ECC_2_2024_GOVERNANCE_REVIEW.md';

if (! is_readable($domainJsonPath)) {
    fwrite(STDERR, "Domain batch not readable: {$domainJsonPath}\n");
    exit(1);
}

$raw = file_get_contents($domainJsonPath);
if ($raw === false) {
    fwrite(STDERR, "Unable to read domain batch: {$domainJsonPath}\n");
    exit(1);
}

/** @var array<string, mixed> $batch */
$batch = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

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
    if (is_array($requirements)) {
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
}

$domainKey = (string) ($domain['source_document_key'] ?? '');
if ($domainKey !== '') {
    $sourceDocumentKeys[$domainKey] = true;
}

$sourceDocuments = array_keys($sourceDocumentKeys);
sort($sourceDocuments);

$lines = [];
$lines[] = '# Governance Domain Review';
$lines[] = '';
$lines[] = 'Human-readable review artifact for NCA ECC-2:2024 Governance domain batch.';
$lines[] = '';
$lines[] = 'Generated from: `backend/database/corpus/nca/ecc-2-2024/governance/domain.json`';
$lines[] = '';
$lines[] = 'Batch status: `'.(string) ($batch['status'] ?? 'unknown').'`';
$lines[] = '';
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
    /** @var array<string, mixed> $requirement */
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

$content = implode("\n", $lines)."\n";
if (file_put_contents($reviewOutPath, $content) === false) {
    fwrite(STDERR, "Unable to write review document: {$reviewOutPath}\n");
    exit(1);
}

fwrite(STDOUT, 'Wrote '.$reviewOutPath.' ('.count($controls)." controls, {$requirementCount} requirements)\n");

function blockText(string $value): string
{
    return $value === '' ? '(empty)' : $value;
}

function mdCell(string $value): string
{
    return str_replace('|', '\\|', $value);
}
