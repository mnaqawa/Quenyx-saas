<?php

namespace App\Services\Compliance\Corpus;

use App\Enums\Compliance\SourceDocumentLanguage;
use App\Enums\Compliance\SourceDocumentStatus;
use App\Enums\Compliance\SourceDocumentType;
use App\Models\Compliance\ComplianceFrameworkRelease;
use App\Models\Compliance\ComplianceSourceDocument;
use Illuminate\Support\Facades\File;

/**
 * Idempotently registers official source document metadata from JSON (no file upload).
 */
class ComplianceSourceDocumentRegistrar
{
    /**
     * @return array{created: int, updated: int, keys: list<string>}
     */
    public function registerFromFile(string $path, ComplianceFrameworkRelease $release): array
    {
        if (! File::exists($path)) {
            throw ComplianceCorpusImportException::invalidPayload("Source document file not found: {$path}");
        }

        $payload = json_decode(File::get($path), true);
        if (! is_array($payload)) {
            throw ComplianceCorpusImportException::invalidPayload('Source document file must contain valid JSON.');
        }

        return $this->registerFromArray($payload, $release);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{created: int, updated: int, keys: list<string>}
     */
    public function registerFromArray(array $payload, ComplianceFrameworkRelease $release): array
    {
        if (! isset($payload['source_documents']) || ! is_array($payload['source_documents'])) {
            throw ComplianceCorpusImportException::invalidPayload('Missing required "source_documents" array.');
        }

        $created = 0;
        $updated = 0;
        $keys = [];

        foreach ($payload['source_documents'] as $index => $row) {
            if (! is_array($row)) {
                throw ComplianceCorpusImportException::invalidPayload("source_documents[{$index}] must be an object.");
            }

            $key = (string) ($row['key'] ?? '');
            if ($key === '') {
                throw ComplianceCorpusImportException::invalidPayload("source_documents[{$index}].key is required.");
            }

            $attributes = [
                'framework_release_id' => $release->id,
                'title_en' => (string) ($row['title_en'] ?? ''),
                'title_ar' => (string) ($row['title_ar'] ?? ''),
                'document_type' => SourceDocumentType::tryFrom((string) ($row['document_type'] ?? 'framework'))
                    ?? SourceDocumentType::Framework,
                'language' => SourceDocumentLanguage::tryFrom((string) ($row['language'] ?? 'en'))
                    ?? SourceDocumentLanguage::En,
                'source_url' => $row['source_url'] ?? null,
                'official_file_name' => $row['official_file_name'] ?? null,
                'official_file_mime' => $row['official_file_mime'] ?? null,
                'official_file_size' => isset($row['official_file_size']) ? (int) $row['official_file_size'] : null,
                'checksum_sha256' => $row['checksum_sha256'] ?? null,
                'source_reference' => $row['source_reference'] ?? null,
                'publication_date' => $row['publication_date'] ?? null,
                'effective_date' => $row['effective_date'] ?? null,
                'status' => SourceDocumentStatus::tryFrom((string) ($row['status'] ?? 'active'))
                    ?? SourceDocumentStatus::Active,
                'metadata' => $row['metadata'] ?? null,
            ];

            $existing = ComplianceSourceDocument::query()
                ->where('framework_release_id', $release->id)
                ->where('key', $key)
                ->first();

            if ($existing !== null) {
                $existing->fill($attributes);
                $existing->save();
                $updated++;
            } else {
                ComplianceSourceDocument::query()->create(array_merge(['key' => $key], $attributes));
                $created++;
            }

            $keys[] = $key;
        }

        return compact('created', 'updated', 'keys');
    }

    /**
     * @return array<string, int> key => id
     */
    public function keyMapForRelease(ComplianceFrameworkRelease $release): array
    {
        return ComplianceSourceDocument::query()
            ->where('framework_release_id', $release->id)
            ->whereNotNull('key')
            ->pluck('id', 'key')
            ->all();
    }
}
