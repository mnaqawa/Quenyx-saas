<?php

namespace App\Services\Compliance\Rag;

use App\DataTransferObjects\Ai\AiSkillResponse;
use App\DataTransferObjects\Compliance\Reasoning\ReasoningOutput;
use App\DataTransferObjects\Compliance\Retrieval\RetrievalChunk;
use App\DataTransferObjects\Compliance\Retrieval\RetrievalResult;

/**
 * Builds a bounded, cited RAG context package (QCIF Sprint 17). NO AI call — pure data assembly.
 *
 * It takes the (hybrid) RetrievalResult, the deterministic ReasoningOutput, and the skill results,
 * and produces a token-bounded context package. Safety invariants enforced here:
 *  - RAG NEVER returns uncited chunks (uncited chunks are excluded with a reason).
 *  - Chunks beyond the token budget are excluded with a reason (never silently dropped).
 *  - Citations + guardrails from reasoning are preserved.
 */
class ComplianceRagContextBuilder
{
    public function __construct() {}

    /**
     * @param  list<AiSkillResponse>  $skillResults
     * @return array<string, mixed>
     */
    public function build(RetrievalResult $retrieval, ReasoningOutput $reasoning, array $skillResults = []): array
    {
        $budget = max(256, (int) config('ai.rag.token_budget', 6000));

        $included = [];
        $excluded = [];
        $usedTokens = 0;

        foreach ($retrieval->chunks as $chunk) {
            if ($this->isUncited($chunk)) {
                $excluded[] = ['uuid' => $chunk->uuid, 'entity_uuid' => $chunk->entityUuid, 'reason' => 'missing_citation'];

                continue;
            }

            $tokens = $this->estimateTokens($chunk);
            if ($usedTokens + $tokens > $budget) {
                $excluded[] = ['uuid' => $chunk->uuid, 'entity_uuid' => $chunk->entityUuid, 'reason' => 'token_budget_exceeded'];

                continue;
            }

            $usedTokens += $tokens;
            $included[] = [
                'uuid' => $chunk->uuid,
                'entity_type' => $chunk->entityType,
                'entity_uuid' => $chunk->entityUuid,
                'entity_code' => $chunk->entityCode,
                'chunk_type' => $chunk->chunkType,
                'text_en' => $chunk->textEn,
                'text_ar' => $chunk->textAr,
                'citations' => array_map(fn ($c) => $c->toArray(), $chunk->citations),
                'estimated_tokens' => $tokens,
            ];
        }

        return [
            'context_package' => $included,
            'citations' => $this->citations($retrieval, $included),
            'guardrails' => $this->guardrails($retrieval, $reasoning),
            'token_budget' => [
                'budget' => $budget,
                'estimated_tokens_used' => $usedTokens,
                'remaining' => max(0, $budget - $usedTokens),
                'included_chunks' => count($included),
                'excluded_chunks' => count($excluded),
            ],
            'excluded_chunks' => $excluded,
            'reasoning' => [
                'decision_type' => $reasoning->decision->type->value,
                'answer_strategy' => $reasoning->answerStrategy(),
                'missing_information_count' => count($reasoning->missingInformation),
                'finding_count' => count($reasoning->findings),
                'recommendation_count' => count($reasoning->recommendations),
            ],
            'skills' => $this->skillsSummary($skillResults),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $included
     * @return list<array<string, mixed>>
     */
    private function citations(RetrievalResult $retrieval, array $included): array
    {
        $merged = [];
        $seen = [];

        $add = function (array $citation) use (&$merged, &$seen): void {
            $key = implode('|', [
                $citation['entity_uuid'] ?? '',
                $citation['official_reference'] ?? '',
                $citation['source_document_key'] ?? '',
            ]);
            if (trim($key, '|') === '' || isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $merged[] = $citation;
        };

        foreach ($retrieval->citations as $citation) {
            $add($citation->toArray());
        }
        foreach ($included as $item) {
            foreach ($item['citations'] as $citation) {
                $add($citation);
            }
        }

        return $merged;
    }

    /**
     * @return array<string, bool>
     */
    private function guardrails(RetrievalResult $retrieval, ReasoningOutput $reasoning): array
    {
        $guardrails = $reasoning->guardrails;
        foreach ($retrieval->guardrails as $name => $enabled) {
            $guardrails[$name] = ($guardrails[$name] ?? false) || (bool) $enabled;
        }

        // RAG-specific guardrails — always enforced.
        $guardrails['rag_cited_chunks_only'] = true;
        $guardrails['rag_no_uncited_context'] = true;
        $guardrails['rag_corpus_grounded'] = true;

        return $guardrails;
    }

    /**
     * @param  list<AiSkillResponse>  $skillResults
     * @return list<array<string, mixed>>
     */
    private function skillsSummary(array $skillResults): array
    {
        $summary = [];
        foreach ($skillResults as $response) {
            $summary[] = [
                'skill' => $response->skillKey,
                'success' => $response->success,
            ];
        }

        return $summary;
    }

    private function estimateTokens(RetrievalChunk $chunk): int
    {
        $len = mb_strlen((string) $chunk->textEn) + mb_strlen((string) $chunk->textAr);

        return (int) max(1, ceil($len / 4));
    }

    private function isUncited(RetrievalChunk $chunk): bool
    {
        if ($chunk->citations !== []) {
            return false;
        }

        return $chunk->sourceDocumentKey === null && $chunk->officialReference === null;
    }
}
