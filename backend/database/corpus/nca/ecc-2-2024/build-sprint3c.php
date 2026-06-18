<?php

/**
 * Sprint 3C — build remaining NCA ECC-2:2024 domain batches and review artifacts.
 *
 * Run from repo root:
 *   php backend/database/corpus/nca/ecc-2-2024/build-sprint3c.php
 */
declare(strict_types=1);

$baseDir = __DIR__;
require_once $baseDir.'/_shared/CorpusBatchBuilder.php';

use Qcif\Ecc2024\CorpusBatchBuilder;

/**
 * @param list<array{0:string,1:string,2:string,3:string,4:string,5:string,6:int,7:string}> $rows
 * @return list<array<string, mixed>>
 */
function mapControls(array $rows, int $domainNumber): array
{
    $controls = [];
    foreach ($rows as $row) {
        $controls[] = CorpusBatchBuilder::control(
            $row[0],
            $row[1],
            $row[2],
            $row[3],
            $row[4],
            $row[5],
            $row[6],
            $row[7],
            $domainNumber,
        );
    }

    return $controls;
}

/** @return list<array{0:string,1:string,2:string,3:string,4:string,5:string,6:int,7:string}> */
function loadData(string $path): array
{
    /** @var list<array{0:string,1:string,2:string,3:string,4:string,5:string,6:int,7:string}> $rows */
    $rows = require $path;

    return $rows;
}

$domains = [
    [
        'slug' => 'cybersecurity-defense',
        'file' => $baseDir.'/cybersecurity-defense/domain.json',
        'review' => dirname($baseDir, 5).'/docs/reviews/NCA_ECC_2_2024_CYBERSECURITY_DEFENSE_REVIEW.md',
        'reviewTitle' => 'NCA ECC-2:2024 — Cybersecurity Defense Domain Review',
        'domainNumber' => 2,
        'domainCode' => '2',
        'displayCode' => 'ECC-2',
        'titleEn' => 'Cybersecurity Defense',
        'titleAr' => 'الدفاع السيبراني',
        'descriptionEn' => 'Main Domain 2 of NCA Essential Cybersecurity Controls (ECC-2:2024).',
        'descriptionAr' => 'المجال الرئيسي الثاني من الضوابط الأساسية للأمن السيبراني (ECC-2:2024).',
        'sourcePage' => '19',
        'subdomains' => ['2-1', '2-2', '2-3', '2-4', '2-5', '2-6', '2-7', '2-8', '2-9', '2-10', '2-11', '2-12', '2-13', '2-14', '2-15'],
        'dataFile' => $baseDir.'/_data/cybersecurity-defense-controls.php',
        'extraMetadata' => [
            'pending_manual_review' => [
                '2-6-4: Arabic requirement text preserves istitlaa typo "متكلبات" (expected "متطلبات") — verify against AR PDF.',
                '2-14-1: Arabic sourced from istitlaa row code 1-14-12 (display typo); mapped to official control 2-14-1.',
            ],
        ],
    ],
    [
        'slug' => 'cybersecurity-resilience',
        'file' => $baseDir.'/cybersecurity-resilience/domain.json',
        'review' => dirname($baseDir, 5).'/docs/reviews/NCA_ECC_2_2024_CYBERSECURITY_RESILIENCE_REVIEW.md',
        'reviewTitle' => 'NCA ECC-2:2024 — Cybersecurity Resilience Domain Review',
        'domainNumber' => 3,
        'domainCode' => '3',
        'displayCode' => 'ECC-3',
        'titleEn' => 'Cybersecurity Resilience',
        'titleAr' => 'صمود الأمن السيبراني',
        'descriptionEn' => 'Main Domain 3 of NCA Essential Cybersecurity Controls (ECC-2:2024).',
        'descriptionAr' => 'المجال الرئيسي الثالث من الضوابط الأساسية للأمن السيبراني (ECC-2:2024).',
        'sourcePage' => '29',
        'subdomains' => ['3-1'],
        'dataFile' => $baseDir.'/_data/cybersecurity-resilience-controls.php',
        'extraMetadata' => [],
    ],
    [
        'slug' => 'third-party',
        'file' => $baseDir.'/third-party/domain.json',
        'review' => dirname($baseDir, 5).'/docs/reviews/NCA_ECC_2_2024_THIRD_PARTY_REVIEW.md',
        'reviewTitle' => 'NCA ECC-2:2024 — Third-Party Cybersecurity Domain Review',
        'domainNumber' => 4,
        'domainCode' => '4-1',
        'displayCode' => 'ECC-4-1',
        'titleEn' => 'Third-Party Cybersecurity',
        'titleAr' => 'الأمن السيبراني المتعلق بالأطراف الخارجية',
        'descriptionEn' => 'Subdomain 4-1 of NCA Essential Cybersecurity Controls (ECC-2:2024).',
        'descriptionAr' => 'المكون الفرعي 4-1 من الضوابط الأساسية للأمن السيبراني (ECC-2:2024).',
        'sourcePage' => '30',
        'subdomains' => ['4-1'],
        'dataFile' => $baseDir.'/_data/third-party-controls.php',
        'extraMetadata' => ['parent_domain' => '4'],
    ],
    [
        'slug' => 'cloud-security',
        'file' => $baseDir.'/cloud-security/domain.json',
        'review' => dirname($baseDir, 5).'/docs/reviews/NCA_ECC_2_2024_CLOUD_SECURITY_REVIEW.md',
        'reviewTitle' => 'NCA ECC-2:2024 — Cloud Computing and Hosting Cybersecurity Domain Review',
        'domainNumber' => 4,
        'domainCode' => '4-2',
        'displayCode' => 'ECC-4-2',
        'titleEn' => 'Cloud Computing and Hosting Cybersecurity',
        'titleAr' => 'الأمن السيبراني المتعلق بالحوسبة السحابية والاستضافة',
        'descriptionEn' => 'Subdomain 4-2 of NCA Essential Cybersecurity Controls (ECC-2:2024).',
        'descriptionAr' => 'المكون الفرعي 4-2 من الضوابط الأساسية للأمن السيberاني (ECC-2:2024).',
        'sourcePage' => '31',
        'subdomains' => ['4-2'],
        'dataFile' => $baseDir.'/_data/cloud-security-controls.php',
        'extraMetadata' => [
            'parent_domain' => '4',
            'pending_manual_review' => [
                '4-2-3: EN clause 4.2.3.1 uses "Protection of entity\'s data ... in accordance with its classification level"; official AR (istitlaa) uses "تصنيف البيانات قبل استضافتها" — verify EN/AR pairing against AR PDF before approval.',
            ],
        ],
    ],
];

foreach ($domains as $config) {
    $controls = mapControls(loadData($config['dataFile']), $config['domainNumber']);

    $batch = CorpusBatchBuilder::domainBatch(
        $config['slug'],
        '3C',
        $config['domainNumber'],
        $config['domainCode'],
        $config['displayCode'],
        $config['titleEn'],
        $config['titleAr'],
        $config['descriptionEn'],
        $config['descriptionAr'],
        $config['sourcePage'],
        $controls,
        $config['subdomains'],
        $config['extraMetadata'],
    );

    CorpusBatchBuilder::writeDomainBatch($config['file'], $batch);
    fwrite(STDOUT, 'Wrote '.$config['file'].' ('.count($controls)." controls)\n");
}

// OT Security — not present in ECC-2:2024 (Main Domain 5 removed; see Appendix C / OTCC).
$otBatch = [
    '$schema' => 'quenyx/qcif/domain-batch/v1.0',
    'status' => 'draft',
    'reviewed_by' => null,
    'reviewed_at' => null,
    'notes' => 'Sprint 3C — OT Security (Main Domain 5) is not part of ECC-2:2024. Operational Technology Cybersecurity Controls (OTCC) is a separate publication per Appendix C. No controls to populate.',
    'metadata' => [
        'curation_sprint' => '3C',
        'domain_slug' => 'ot-security',
        'source_publication' => 'ECC-2:2024',
        'control_count' => 0,
        'requirement_count' => 0,
        'pending_manual_review' => [
            'ECC-2:2024 does not include Main Domain 5 (OT Security). Removed in 2024 edition; refer to OTCC framework.',
            'Confirm manifest slug ot-security should remain as placeholder batch or be retired before production import.',
        ],
    ],
];
CorpusBatchBuilder::writeDomainBatch($baseDir.'/ot-security/domain.json', $otBatch);
fwrite(STDOUT, "Wrote ot-security/domain.json (draft, no domain entity)\n");

// Generate review markdown for populated domains + OT placeholder review.
$reviewScript = $baseDir.'/_shared/build-review.php';
foreach ($domains as $config) {
    $php = defined('PHP_BINARY') ? PHP_BINARY : 'php';
    $cmd = escapeshellarg($php).' '.escapeshellarg($reviewScript)
        .' '.escapeshellarg($config['file'])
        .' '.escapeshellarg($config['review'])
        .' '.escapeshellarg($config['reviewTitle']);
    passthru($cmd, $exitCode);
    if ($exitCode !== 0) {
        exit($exitCode);
    }
}

$otReviewPath = dirname($baseDir, 5).'/docs/reviews/NCA_ECC_2_2024_OT_SECURITY_REVIEW.md';
$otReviewDir = dirname($otReviewPath);
if (! is_dir($otReviewDir)) {
    mkdir($otReviewDir, 0775, true);
}
$otLines = [
    '# NCA ECC-2:2024 — OT Security Domain Review',
    '',
    'Human-readable review artifact for NCA ECC-2:2024 OT Security domain batch placeholder.',
    '',
    'Generated from: `backend/database/corpus/nca/ecc-2-2024/ot-security/domain.json`',
    '',
    'Batch status: `draft`',
    '',
    '## Pending manual review',
    '',
    '- ECC-2:2024 does not include Main Domain 5 (OT Security). Removed in 2024 edition; refer to OTCC framework.',
    '- Confirm manifest slug `ot-security` should remain as placeholder batch or be retired before production import.',
    '',
    '## Summary',
    '',
    '| Item | Value |',
    '|------|-------|',
    '| Domain code | (none — not in ECC-2:2024) |',
    '| Domain title (EN) | OT Security (removed from ECC-2:2024) |',
    '| Domain title (AR) | أمن التقنيات التشغيلية (منفصل — OTCC) |',
    '| Controls count | 0 |',
    '| Requirements count | 0 |',
    '| Source documents used | nca-ecc-2-2024-en, nca-ecc-2-2024-ar |',
    '',
    '## Review checklist',
    '',
    '- [ ] Code matches official PDF',
    '- [ ] English wording exact',
    '- [ ] Arabic wording exact',
    '- [ ] EN/AR pairing correct',
    '- [ ] Source references present',
    '- [ ] No invented text',
    '- [ ] Approved for import',
    '',
    '## Notes',
    '',
    'ECC-2:2024 Appendix C states that OT-specific controls were moved to the Operational Technology Cybersecurity Controls (OTCC) publication. This batch intentionally contains no domain entity or controls.',
];
file_put_contents($otReviewPath, implode("\n", $otLines)."\n");
fwrite(STDOUT, 'Wrote '.$otReviewPath."\n");

fwrite(STDOUT, "Sprint 3C build complete.\n");
