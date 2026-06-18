<?php

namespace App\Services\Compliance;

use Illuminate\Support\Facades\File;

/**
 * Reads curator metadata (e.g. pending_manual_review) from on-disk domain batch files.
 * Batch metadata is not persisted on corpus entities during import.
 */
class ComplianceCorpusBatchMetadataReader
{
    /** @var array<string, string> */
    private array $corpusRoots = [
        'nca-ecc:2:2024' => 'database/corpus/nca/ecc-2-2024',
        'nca-ecc:ECC-2:2024' => 'database/corpus/nca/ecc-2-2024',
    ];

    /**
     * @return list<string>
     */
    public function pendingManualReview(string $frameworkKey, string $releaseCode): array
    {
        $root = $this->resolveCorpusRoot($frameworkKey, $releaseCode);
        if ($root === null) {
            return [];
        }

        $basePath = base_path($root);
        $manifestPath = $basePath.DIRECTORY_SEPARATOR.'manifest.json';
        if (! is_readable($manifestPath)) {
            return [];
        }

        /** @var array<string, mixed>|null $manifest */
        $manifest = json_decode(file_get_contents($manifestPath) ?: '', true);
        if (! is_array($manifest)) {
            return [];
        }

        $items = [];

        foreach ($manifest['excluded_domains'] ?? [] as $excluded) {
            if (! is_array($excluded)) {
                continue;
            }
            $reason = (string) ($excluded['reason'] ?? '');
            if ($reason !== '') {
                $items[] = $reason;
            }
        }

        foreach ($manifest['domains'] ?? [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $file = (string) ($entry['file'] ?? '');
            if ($file === '') {
                continue;
            }
            $batchPath = $basePath.DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $file);
            if (! is_readable($batchPath)) {
                continue;
            }
            /** @var array<string, mixed>|null $batch */
            $batch = json_decode(file_get_contents($batchPath) ?: '', true);
            if (! is_array($batch)) {
                continue;
            }
            $pending = $batch['metadata']['pending_manual_review'] ?? [];
            if (! is_array($pending)) {
                continue;
            }
            foreach ($pending as $item) {
                if (is_string($item) && $item !== '') {
                    $items[] = $item;
                }
            }
        }

        return array_values(array_unique($items));
    }

    private function resolveCorpusRoot(string $frameworkKey, string $releaseCode): ?string
    {
        $key = "{$frameworkKey}:{$releaseCode}";

        return $this->corpusRoots[$key] ?? null;
    }
}
