<?php

namespace App\Services\Compliance\Retrieval;

use App\DataTransferObjects\Ai\AiSkillResponse;

/**
 * Converts already-executed skill responses into deterministic "raw chunks" + relation sets
 * (QCIF Sprint 15). It walks the skill payloads, extracts corpus/graph/engine entity nodes
 * (control / requirement / domain / objective) with their bilingual text + provenance, and records
 * which skill surfaced each entity. It also gathers the entity UUIDs referenced by tenant skills
 * (evidence / gap / recommendation) so the ranker can tag chunks as *_related.
 *
 * No AI, no DB, no vectors, no embeddings — pure transformation of data the skills already produced.
 */
class ComplianceRetrievalContextBuilder
{
    /** Entity types that may become retrieval chunks (framework/release/revision/source_document excluded). */
    private const CHUNKABLE_TYPES = ['control', 'requirement', 'domain', 'objective', 'control_objective'];

    private const RELATION_SKILLS = [
        'evidence' => 'evidence',
        'gap_assessment' => 'gap',
        'recommendation' => 'recommendation',
    ];

    /**
     * @param  list<AiSkillResponse>  $responses
     * @return array{chunks: list<array<string, mixed>>, relations: array<string, list<string>>}
     */
    public function build(array $responses): array
    {
        $chunks = [];
        $relations = ['evidence' => [], 'gap' => [], 'recommendation' => []];

        foreach ($responses as $response) {
            if (! $response->success || $response->result === null) {
                continue;
            }

            $payload = $response->result->payload;
            $revisionUuid = $this->responseRevision($payload);

            foreach ($this->extractNodes($payload) as $node) {
                $this->absorbChunk($chunks, $node, $response->skillKey, $revisionUuid);
            }

            if (isset(self::RELATION_SKILLS[$response->skillKey])) {
                $family = self::RELATION_SKILLS[$response->skillKey];
                $relations[$family] = array_values(array_unique([
                    ...$relations[$family],
                    ...$this->collectUuids($payload),
                ]));
            }
        }

        return ['chunks' => array_values($chunks), 'relations' => $relations];
    }

    /**
     * @param  array<string, mixed>  $chunks  keyed by entity_uuid (by reference)
     * @param  array<string, mixed>  $node
     */
    private function absorbChunk(array &$chunks, array $node, string $skillKey, ?string $revisionUuid): void
    {
        $uuid = (string) $node['__entity_uuid'];

        if (! isset($chunks[$uuid])) {
            $chunks[$uuid] = array_merge($node, [
                'origins' => [$skillKey],
                'revision_uuid' => $node['revision_uuid'] ?? $revisionUuid,
            ]);

            return;
        }

        // Merge: union origins, prefer richer text, keep first non-null provenance/revision.
        $existing = $chunks[$uuid];
        $existing['origins'] = array_values(array_unique([...$existing['origins'], $skillKey]));
        $existing['text_en'] = $this->preferText($existing['text_en'] ?? null, $node['text_en'] ?? null);
        $existing['text_ar'] = $this->preferText($existing['text_ar'] ?? null, $node['text_ar'] ?? null);
        $existing['source_document_key'] ??= $node['source_document_key'] ?? null;
        $existing['official_reference'] ??= $node['official_reference'] ?? null;
        $existing['source_page'] ??= $node['source_page'] ?? null;
        $existing['revision_uuid'] ??= $node['revision_uuid'] ?? $revisionUuid;
        $chunks[$uuid] = $existing;
    }

    /**
     * Recursively find entity nodes in a payload.
     *
     * @param  mixed  $data
     * @return list<array<string, mixed>>
     */
    private function extractNodes(mixed $data): array
    {
        $found = [];
        if (! is_array($data)) {
            return $found;
        }

        if ($this->isEntityNode($data)) {
            $node = $this->normalizeNode($data);
            if ($node !== null) {
                $found[] = $node;
            }
        }

        foreach ($data as $value) {
            if (is_array($value)) {
                $found = [...$found, ...$this->extractNodes($value)];
            }
        }

        return $found;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function isEntityNode(array $data): bool
    {
        if (! isset($data['uuid']) || ! is_string($data['uuid'])) {
            return false;
        }

        // Exclude framework/release/revision/source-document blocks.
        foreach (['key', 'version_code', 'release_code', 'stable_ref', 'revision_number', 'checksum_sha256'] as $marker) {
            if (array_key_exists($marker, $data)) {
                return false;
            }
        }

        $type = $this->inferType($data);
        if (! in_array($type, self::CHUNKABLE_TYPES, true)) {
            return false;
        }

        $hasCode = isset($data['code']) || isset($data['display_code']) || isset($data['normalized_code']);
        $hasText = isset($data['title_en']) || isset($data['requirement_text_en']) || isset($data['description_en']);

        return ($hasCode && $hasText) || isset($data['provenance']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function inferType(array $data): ?string
    {
        if (isset($data['entity_type']) && is_string($data['entity_type'])) {
            return $data['entity_type'];
        }
        if (isset($data['requirement_text_en'])) {
            return 'requirement';
        }
        if (array_key_exists('control_type', $data)) {
            return 'control';
        }
        if (isset($data['provenance']) || (isset($data['code']) && isset($data['title_en']))) {
            return 'domain';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private function normalizeNode(array $data): ?array
    {
        $provenance = is_array($data['provenance'] ?? null) ? $data['provenance'] : [];

        $entityCode = $this->str($data, 'display_code') ?? $this->str($data, 'code') ?? $this->str($data, 'normalized_code');
        $textEn = $this->str($data, 'requirement_text_en') ?? $this->str($data, 'description_en') ?? $this->str($data, 'title_en');
        $textAr = $this->str($data, 'requirement_text_ar') ?? $this->str($data, 'description_ar') ?? $this->str($data, 'title_ar');

        return [
            '__entity_uuid' => (string) $data['uuid'],
            'entity_type' => $this->inferType($data),
            'entity_code' => $entityCode,
            'title_en' => $this->str($data, 'title_en'),
            'title_ar' => $this->str($data, 'title_ar'),
            'text_en' => $textEn,
            'text_ar' => $textAr,
            'source_document_key' => $this->str($provenance, 'source_document_key'),
            'official_reference' => $this->str($provenance, 'official_reference') ?? $this->str($data, 'official_reference'),
            'source_page' => $this->str($provenance, 'source_page') ?? $this->str($data, 'source_page'),
            'source_reference' => $this->str($provenance, 'source_reference') ?? $this->str($data, 'source_reference'),
            'revision_uuid' => $this->str($data, 'revision_uuid'),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function responseRevision(array $payload): ?string
    {
        return $this->digString($payload, ['provenance', 'revision_uuid'])
            ?? $this->digString($payload, ['active_revision', 'uuid'])
            ?? $this->digString($payload, ['revision', 'uuid'])
            ?? $this->digString($payload, ['revision_uuid']);
    }

    /**
     * @param  mixed  $data
     * @return list<string>
     */
    private function collectUuids(mixed $data): array
    {
        $uuids = [];
        if (! is_array($data)) {
            return $uuids;
        }

        foreach ($data as $key => $value) {
            if (($key === 'uuid' || $key === 'entity_uuid') && is_string($value) && $value !== '') {
                $uuids[] = $value;
            } elseif (is_array($value)) {
                $uuids = [...$uuids, ...$this->collectUuids($value)];
            }
        }

        return $uuids;
    }

    private function preferText(?string $a, ?string $b): ?string
    {
        if ($a !== null && $a !== '') {
            return $a;
        }

        return $b;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function str(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $path
     */
    private function digString(array $data, array $path): ?string
    {
        $current = $data;
        foreach ($path as $key) {
            if (! is_array($current) || ! array_key_exists($key, $current)) {
                return null;
            }
            $current = $current[$key];
        }

        return is_string($current) && $current !== '' ? $current : null;
    }
}
