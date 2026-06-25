<?php

namespace App\Services\Compliance\Retrieval;

use App\DataTransferObjects\Compliance\Retrieval\RetrievalChunk;
use App\DataTransferObjects\Compliance\Retrieval\RetrievalCitation;
use App\DataTransferObjects\Compliance\Retrieval\RetrievalQuery;
use App\DataTransferObjects\Compliance\Retrieval\RetrievalScoreExplanation;
use Ramsey\Uuid\Uuid;

/**
 * Deterministic, EXPLAINABLE ranking (QCIF Sprint 15).
 *
 * Ranking uses named rank REASONS — never a numeric/probabilistic score, never AI. A chunk's
 * position is decided by its strongest reason (a fixed precedence), then by entity code, then by
 * uuid, so the same inputs always produce the same order. Chunk UUIDs are deterministic (uuid5).
 */
class ComplianceRetrievalRanker
{
    public const REASON_EXACT_CODE = 'exact_code_match';

    public const REASON_NORMALIZED_CODE = 'normalized_code_match';

    public const REASON_TITLE = 'title_match';

    public const REASON_REQUIREMENT_TEXT = 'requirement_text_match';

    public const REASON_GRAPH = 'graph_neighbor';

    public const REASON_EVIDENCE = 'evidence_related';

    public const REASON_GAP = 'gap_related';

    public const REASON_RECOMMENDATION = 'recommendation_related';

    public const REASON_MANUAL = 'manual_priority';

    public const REASON_FALLBACK = 'fallback';

    /** Strongest → weakest. */
    private const PRECEDENCE = [
        self::REASON_EXACT_CODE,
        self::REASON_NORMALIZED_CODE,
        self::REASON_TITLE,
        self::REASON_REQUIREMENT_TEXT,
        self::REASON_GRAPH,
        self::REASON_EVIDENCE,
        self::REASON_GAP,
        self::REASON_RECOMMENDATION,
        self::REASON_MANUAL,
        self::REASON_FALLBACK,
    ];

    private const UUID_NAMESPACE = '7c1d2e3f-15aa-5b6c-8d9e-quenyxqcif15';

    /**
     * @param  list<array<string, mixed>>  $rawChunks
     * @param  array<string, list<string>>  $relations
     * @return array{chunks: list<RetrievalChunk>, explanations: list<RetrievalScoreExplanation>}
     */
    public function rank(array $rawChunks, array $relations, RetrievalQuery $query): array
    {
        $terms = $this->terms($query);
        $queryCode = $query->code;

        $ranked = [];
        foreach ($rawChunks as $raw) {
            $reasons = $this->reasonsFor($raw, $relations, $terms, $queryCode);
            $primary = $reasons[0];
            $ranked[] = [
                'raw' => $raw,
                'reasons' => $reasons,
                'primary' => $primary,
                'precedence' => array_search($primary, self::PRECEDENCE, true),
            ];
        }

        usort($ranked, function (array $a, array $b): int {
            return [$a['precedence'], (string) ($a['raw']['entity_code'] ?? '~'), (string) $a['raw']['__entity_uuid']]
                <=> [$b['precedence'], (string) ($b['raw']['entity_code'] ?? '~'), (string) $b['raw']['__entity_uuid']];
        });

        $ranked = array_slice($ranked, 0, max(1, $query->limit));

        $chunks = [];
        $explanations = [];
        $position = 1;
        foreach ($ranked as $entry) {
            $chunk = $this->toChunk($entry['raw']);
            $chunks[] = $chunk;
            $explanations[] = new RetrievalScoreExplanation(
                chunkUuid: $chunk->uuid,
                entityUuid: $chunk->entityUuid,
                entityCode: $chunk->entityCode,
                primaryReason: $entry['primary'],
                reasons: $entry['reasons'],
                position: $position++,
            );
        }

        return ['chunks' => $chunks, 'explanations' => $explanations];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array<string, list<string>>  $relations
     * @param  list<string>  $terms
     * @return list<string>
     */
    private function reasonsFor(array $raw, array $relations, array $terms, ?string $queryCode): array
    {
        $reasons = [];
        $uuid = (string) $raw['__entity_uuid'];
        $code = $raw['entity_code'] ?? null;
        $origins = $raw['origins'] ?? [];

        if ($queryCode !== null && is_string($code)) {
            if (strcasecmp($code, $queryCode) === 0) {
                $reasons[] = self::REASON_EXACT_CODE;
            } elseif ($this->normalize($code) === $this->normalize($queryCode) && $this->normalize($code) !== '') {
                $reasons[] = self::REASON_NORMALIZED_CODE;
            }
        }

        $titleHaystack = $this->lower(implode(' ', array_filter([$code, $raw['title_en'] ?? null, $raw['title_ar'] ?? null])));
        if ($this->matchesAny($titleHaystack, $terms)) {
            $reasons[] = self::REASON_TITLE;
        }

        if (($raw['entity_type'] ?? null) === 'requirement') {
            $textHaystack = $this->lower(implode(' ', array_filter([$raw['text_en'] ?? null, $raw['text_ar'] ?? null])));
            if ($this->matchesAny($textHaystack, $terms)) {
                $reasons[] = self::REASON_REQUIREMENT_TEXT;
            }
        }

        if (in_array('knowledge_graph', $origins, true)) {
            $reasons[] = self::REASON_GRAPH;
        }
        if (in_array($uuid, $relations['evidence'] ?? [], true) || in_array('evidence', $origins, true)) {
            $reasons[] = self::REASON_EVIDENCE;
        }
        if (in_array($uuid, $relations['gap'] ?? [], true) || in_array('gap_assessment', $origins, true)) {
            $reasons[] = self::REASON_GAP;
        }
        if (in_array($uuid, $relations['recommendation'] ?? [], true) || in_array('recommendation', $origins, true)) {
            $reasons[] = self::REASON_RECOMMENDATION;
        }

        if ($reasons === []) {
            $reasons[] = self::REASON_FALLBACK;
        }

        // Order by precedence + de-duplicate.
        $reasons = array_values(array_unique($reasons));
        usort($reasons, fn ($x, $y) => array_search($x, self::PRECEDENCE, true) <=> array_search($y, self::PRECEDENCE, true));

        return $reasons;
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function toChunk(array $raw): RetrievalChunk
    {
        $entityUuid = (string) $raw['__entity_uuid'];
        $entityType = $raw['entity_type'] ?? null;
        $revisionUuid = $raw['revision_uuid'] ?? null;
        $chunkType = is_string($entityType) ? $entityType : 'unknown';

        $citation = RetrievalCitation::fromArray([
            'source_document_key' => $raw['source_document_key'] ?? null,
            'official_reference' => $raw['official_reference'] ?? null,
            'source_reference' => $raw['source_reference'] ?? null,
            'source_page' => $raw['source_page'] ?? null,
            'entity_uuid' => $entityUuid,
            'entity_type' => $entityType,
            'entity_code' => $raw['entity_code'] ?? null,
        ]);

        return new RetrievalChunk(
            uuid: $this->chunkUuid($entityUuid, $chunkType, (string) $revisionUuid),
            chunkType: $chunkType,
            entityType: $entityType,
            entityUuid: $entityUuid,
            entityCode: $raw['entity_code'] ?? null,
            textEn: $raw['text_en'] ?? null,
            textAr: $raw['text_ar'] ?? null,
            sourceDocumentKey: $raw['source_document_key'] ?? null,
            officialReference: $raw['official_reference'] ?? null,
            sourcePage: $raw['source_page'] ?? null,
            revisionUuid: $revisionUuid,
            citations: [$citation],
            metadata: [
                'origins' => array_values($raw['origins'] ?? []),
                'source_reference' => $raw['source_reference'] ?? null,
            ],
        );
    }

    private function chunkUuid(string $entityUuid, string $chunkType, string $revisionUuid): string
    {
        return (string) Uuid::uuid5(
            (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'quenyx:qcif:retrieval:'.self::UUID_NAMESPACE),
            $entityUuid.'|'.$chunkType.'|'.$revisionUuid,
        );
    }

    /**
     * @return list<string>
     */
    private function terms(RetrievalQuery $query): array
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $this->lower($query->query)) ?: [];
        $code = $query->code !== null ? $this->lower($query->code) : null;

        $terms = [];
        foreach ($parts as $part) {
            if (mb_strlen($part) >= 3 && $part !== $code) {
                $terms[] = $part;
            }
        }

        return array_values(array_unique($terms));
    }

    /**
     * @param  list<string>  $terms
     */
    private function matchesAny(string $haystack, array $terms): bool
    {
        if ($haystack === '') {
            return false;
        }
        foreach ($terms as $term) {
            if ($term !== '' && str_contains($haystack, $term)) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', $this->lower($value)) ?? '';
    }

    private function lower(string $value): string
    {
        return mb_strtolower($value);
    }
}
