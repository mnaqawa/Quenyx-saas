<?php

/**
 * Regenerates the Governance human-review artifact from domain.json.
 *
 * Run from repository root or this directory:
 *   php backend/database/corpus/nca/ecc-2-2024/governance/build-review.php
 */
declare(strict_types=1);

$domainJsonPath = __DIR__.'/domain.json';
$reviewOutPath = dirname(__DIR__, 5).'/docs/reviews/NCA_ECC_2_2024_GOVERNANCE_REVIEW.md';
$reviewScript = dirname(__DIR__).'/_shared/build-review.php';

$php = defined('PHP_BINARY') ? PHP_BINARY : 'php';
$cmd = escapeshellarg($php).' '.escapeshellarg($reviewScript)
    .' '.escapeshellarg($domainJsonPath)
    .' '.escapeshellarg($reviewOutPath)
    .' '.escapeshellarg('NCA ECC-2:2024 — Governance Domain Review');

passthru($cmd, $exitCode);
exit($exitCode);
