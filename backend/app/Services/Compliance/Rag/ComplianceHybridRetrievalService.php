<?php

namespace App\Services\Compliance\Rag;

use App\DataTransferObjects\Compliance\Retrieval\RetrievalChunk;
use App\DataTransferObjects\Compliance\Retrieval\RetrievalCitation;
use App\DataTransferObjects\Compliance\Retrieval\RetrievalQuery;
use App\DataTransferObjects\Compliance\Retrieval\RetrievalResult;
use App\DataTransferObjects\Compliance\Retrieval\RetrievalScoreExplanation;
use App\Services\Compliance\Retrieval\ComplianceRetrievalService;

/**
 * Hybrid retrieval (QCIF Sprint 17): deterministic retrieval FIRST, then optional vector retrieval.
 *
 * Flow: deterministic retrieval → (if RAG enabled) vector retrieval → merge → de-dupe by
 * entity_uuid+chunk_type → preserve citations → rank deterministic reasons first, then
 * `vector_semantic_match`. NO numeric confidence is ever exposed. If the vector provider fails or is
 * unavailable, it falls back to deterministic retrieval with a `vector_provider_unavailable` warning.
 * Uncited vector chunks are dropped (RAG never returns uncited chunks).
 */
class ComplianceHybridRetrievalService
{
    public const REASON_VECTOR = 'vector_semantic_match';

    public function __construct(
        private readonly ComplianceRetrievalService $retrieval,
        private readonly VectorRetrievalProviderRegistry $vectorRegistry,
    ) {}

    /**
     * Full hybrid retrieval for the API: returns the merged result + skill responses + scope.
     *
     * @return array{result: RetrievalResult, responses: list<\App\DataTransferObjects\Ai\AiSkillResponse>, scope: array<string, mixed>, query: RetrievalQuery}
     */
    public function query(RetrievalQuery $query): array
    {
        $detailed = $this->retrieval->queryDetailed($query);
        $detailed['result'] = $this->merge($detailed['result'], $detailed['query']);

        return $detailed;
    }

    /**
     * Augment an already-built deterministic result with vector retrieval (used by the Copilot path,
     * which has already executed the skills).
     */
    public function augment(RetrievalResult $deterministic, RetrievalQuery $query): RetrievalResult
    {
        return $this->merge($deterministic, $query);
    }

    private function merge(RetrievalResult $deterministic, RetrievalQuery $query): RetrievalResult
    {
        $provider = $this->vectorRegistry->resolve();
        if ($provider === null) {
            return $deterministic;
        }

        try {
            $vectorChunks = $provider->search($query);
        } catch (\Throwable $e) {
            return $this->withWarning($deterministic, 'vector_provider_unavailable');
        }

        if ($vectorChunks === []) {
            // Provider available but no semantic candidates (e.g. metadata-only mode): deterministic
            // result stands; record that the vector path ran for transparency.
            return $this->withMetadata($deterministic, ['vector_provider' => $provider->key(), 'vector_candidates' => 0]);
        }

        return $this->mergeChunks($deterministic, $vectorChunks, $provider->key(), $query);
    }

    /**
     * @param  list<RetrievalChunk>  $vectorChunks
     */
    private function mergeChunks(RetrievalResult $deterministic, array $vectorChunks, string $providerKey, RetrievalQuery $query): RetrievalResult
    {
        $seen = [];
        foreach ($deterministic->chunks as $chunk) {
            $seen[$this->dedupeKey($chunk)] = true;
        }

        $mergedChunks = $deterministic->chunks;
        $explanations = $deterministic->rankExplanations;
        $position = count($explanations);
        $added = 0;
        $droppedUncited = 0;

        foreach ($vectorChunks as $chunk) {
            $key = $this->dedupeKey($chunk);
            if (isset($seen[$key])) {
                continue; // deterministic reason already covers this entity — keep it ranked first.
            }
            // RAG never returns uncited chunks.
            if ($this->isUncited($chunk)) {
                $droppedUncited++;

                continue;
            }
            $seen[$key] = true;
            $mergedChunks[] = $chunk;
            $explanations[] = new RetrievalScoreExplanation(
                chunkUuid: $chunk->uuid,
                entityUuid: $chunk->entityUuid,
                entityCode: $chunk->entityCode,
                primaryReason: self::REASON_VECTOR,
                reasons: [self::REASON_VECTOR],
                position: ++$position,
            );
            $added++;
        }

        // Respect the original limit (deterministic chunks retained first).
        $limit = max(1, $query->limit);
        $mergedChunks = array_slice($mergedChunks, 0, $limit);
        $explanations = array_slice($explanations, 0, $limit);

        $citations = $this->mergeCitations($deterministic->citations, $mergedChunks);
        $warnings = $deterministic->warnings;
        if ($droppedUncited > 0) {
            $warnings[] = 'vector_uncited_chunks_dropped';
        }

        return new RetrievalResult(
            mode: $deterministic->mode,
            chunks: $mergedChunks,
            citations: $citations,
            rankExplanations: $explanations,
            guardrails: $deterministic->guardrails,
            warnings: array_values(array_unique($warnings)),
            scope: array_merge($deterministic->scope, ['vector_provider' => $providerKey, 'vector_candidates' => $added]),
            generatedAt: $deterministic->generatedAt,
        );
    }

    private function withWarning(RetrievalResult $result, string $warning): RetrievalResult
    {
        return new RetrievalResult(
            mode: $result->mode,
            chunks: $result->chunks,
            citations: $result->citations,
            rankExplanations: $result->rankExplanations,
            guardrails: $result->guardrails,
            warnings: array_values(array_unique([...$result->warnings, $warning])),
            scope: $result->scope,
            generatedAt: $result->generatedAt,
        );
    }

    /**
     * @param  array<string, mixed>  $extraScope
     */
    private function withMetadata(RetrievalResult $result, array $extraScope): RetrievalResult
    {
        return new RetrievalResult(
            mode: $result->mode,
            chunks: $result->chunks,
            citations: $result->citations,
            rankExplanations: $result->rankExplanations,
            guardrails: $result->guardrails,
            warnings: $result->warnings,
            scope: array_merge($result->scope, $extraScope),
            generatedAt: $result->generatedAt,
        );
    }

    /**
     * @param  list<RetrievalCitation>  $base
     * @param  list<RetrievalChunk>  $chunks
     * @return list<RetrievalCitation>
     */
    private function mergeCitations(array $base, array $chunks): array
    {
        $merged = [];
        $seen = [];
        $add = function (RetrievalCitation $c) use (&$merged, &$seen): void {
            $key = $c->dedupeKey();
            if ($key !== '|||' && ! isset($seen[$key])) {
                $seen[$key] = true;
                $merged[] = $c;
            }
        };
        foreach ($base as $c) {
            $add($c);
        }
        foreach ($chunks as $chunk) {
            foreach ($chunk->citations as $c) {
                $add($c);
            }
        }

        return $merged;
    }

    private function dedupeKey(RetrievalChunk $chunk): string
    {
        return (string) $chunk->entityUuid.'|'.$chunk->chunkType;
    }

    private function isUncited(RetrievalChunk $chunk): bool
    {
        if ($chunk->citations !== []) {
            return false;
        }

        return $chunk->sourceDocumentKey === null && $chunk->officialReference === null;
    }
}
