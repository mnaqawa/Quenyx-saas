<?php
declare(strict_types=1);

$base = __DIR__;
$slugs = ['governance', 'cybersecurity-defense', 'cybersecurity-resilience', 'third-party', 'cloud-security'];

foreach ($slugs as $slug) {
    $path = $base.'/'.$slug.'/domain.json';
    $batch = json_decode(file_get_contents($path) ?: '', true, 512, JSON_THROW_ON_ERROR);
    $batch['status'] = 'approved';
    $batch['notes'] = preg_replace('/Pending human approval before production import\.?/', 'Approved for Sprint 4 production import (Revision v1).', (string) ($batch['notes'] ?? '')) ?? $batch['notes'];
    file_put_contents($path, json_encode($batch, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
    echo "approved: {$slug}\n";
}
