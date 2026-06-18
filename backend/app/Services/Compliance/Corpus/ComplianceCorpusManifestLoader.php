<?php

namespace App\Services\Compliance\Corpus;

use InvalidArgumentException;

/**
 * Loads a corpus manifest and merges per-domain batch JSON files into a single import payload.
 */
class ComplianceCorpusManifestLoader
{
    /**
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    public function resolve(string $manifestPath, array $manifest): array
    {
        $baseDir = dirname($manifestPath);

        if (! isset($manifest['domains']) || ! is_array($manifest['domains'])) {
            throw new InvalidArgumentException('Manifest must contain a domains array.');
        }

        $framework = $this->normalizeFramework($manifest);
        $payload = [
            '$schema' => $manifest['$schema'] ?? 'quenyx/qcif/corpus-import/v2.0-domain-manifest',
            'framework' => $framework,
            'source_document_keys' => $manifest['source_document_keys'] ?? [],
            'control_objectives' => $manifest['control_objectives'] ?? [],
            'objective_mappings' => $manifest['objective_mappings'] ?? [],
            'domains' => [],
            '_domain_batches' => [],
        ];

        $seenSlugs = [];
        $seenDomainCodes = [];

        foreach ($manifest['domains'] as $index => $entry) {
            $prefix = "manifest.domains[{$index}]";

            if (! is_array($entry)) {
                throw new InvalidArgumentException("{$prefix} must be an object.");
            }

            $slug = (string) ($entry['slug'] ?? '');
            $file = (string) ($entry['file'] ?? '');

            if ($slug === '') {
                throw new InvalidArgumentException("{$prefix}.slug is required.");
            }

            if ($file === '') {
                throw new InvalidArgumentException("{$prefix}.file is required.");
            }

            if (isset($seenSlugs[$slug])) {
                throw new InvalidArgumentException("Manifest lists duplicate domain slug: {$slug}");
            }
            $seenSlugs[$slug] = true;

            $domainPath = $this->resolveDomainPath($baseDir, $file);
            if (! is_readable($domainPath)) {
                throw new InvalidArgumentException("Domain batch file not readable: {$domainPath}");
            }

            $batch = $this->loadDomainBatch($domainPath);
            $domain = $this->extractDomainEntity($batch);
            $hasEntities = $this->batchHasCorpusEntities($domain);

            if ($domain !== null) {
                $domainCode = (string) ($domain['code'] ?? '');
                if ($domainCode !== '') {
                    if (isset($seenDomainCodes[$domainCode])) {
                        throw new InvalidArgumentException(
                            "Domain code '{$domainCode}' appears in more than one batch (slug: {$slug}, file: {$file})."
                        );
                    }
                    $seenDomainCodes[$domainCode] = $slug;
                }

                $payload['domains'][] = $domain;
            }

            $payload['_domain_batches'][] = [
                'slug' => $slug,
                'file' => $file,
                'path' => $domainPath,
                'status' => (string) ($batch['status'] ?? 'draft'),
                'reviewed_by' => $batch['reviewed_by'] ?? null,
                'reviewed_at' => $batch['reviewed_at'] ?? null,
                'notes' => $batch['notes'] ?? null,
                'metadata' => is_array($batch['metadata'] ?? null) ? $batch['metadata'] : [],
                'has_entities' => $hasEntities,
                'domain_code' => $domain !== null ? ($domain['code'] ?? null) : null,
            ];
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    private function normalizeFramework(array $manifest): array
    {
        $framework = $manifest['framework'] ?? null;
        $release = (string) ($manifest['release'] ?? '');

        if (is_string($framework)) {
            return [
                'key' => $framework,
                'version_code' => $release !== '' ? $release : '2:2024',
            ];
        }

        if (is_array($framework)) {
            if ($release !== '' && ! filled($framework['version_code'] ?? null)) {
                $framework['version_code'] = $release;
            }

            return $framework;
        }

        throw new InvalidArgumentException('Manifest framework must be a string key or framework object.');
    }

    private function resolveDomainPath(string $baseDir, string $file): string
    {
        if (str_starts_with($file, '/') || preg_match('/^[A-Za-z]:\\\\/', $file) === 1) {
            return $file;
        }

        return $baseDir.DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $file);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadDomainBatch(string $path): array
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new InvalidArgumentException("Unable to read domain batch: {$path}");
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            throw new InvalidArgumentException("Invalid JSON in domain batch: {$path}");
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $batch
     * @return array<string, mixed>|null
     */
    private function extractDomainEntity(array $batch): ?array
    {
        $domain = $batch['domain'] ?? null;

        if (is_array($domain)) {
            return $domain;
        }

        if (isset($batch['code'])) {
            return $batch;
        }

        return null;
    }

    /**
     * @param array<string, mixed>|null $domain
     */
    private function batchHasCorpusEntities(?array $domain): bool
    {
        if ($domain === null) {
            return false;
        }

        if (($domain['controls'] ?? []) !== []) {
            return true;
        }

        return filled($domain['code'] ?? null)
            && (filled($domain['title_en'] ?? null) || filled($domain['title_ar'] ?? null));
    }

    /**
     * @param array<string, mixed> $decoded
     */
    public function isManifest(array $decoded): bool
    {
        $schema = (string) ($decoded['$schema'] ?? '');
        if (str_contains($schema, 'corpus-manifest')) {
            return true;
        }

        if (! isset($decoded['domains']) || ! is_array($decoded['domains']) || $decoded['domains'] === []) {
            return false;
        }

        $first = $decoded['domains'][0];

        return is_array($first) && isset($first['file']);
    }
}
