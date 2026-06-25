<?php

namespace App\DataTransferObjects\Compliance\Retrieval;

/**
 * The deterministic result of a retrieval request (QCIF Sprint 15): the ranked chunks, the merged
 * citations, the per-chunk rank explanations, the unioned guardrails, and warnings. Pure data.
 */
final readonly class RetrievalResult
{
    /**
     * @param  list<RetrievalChunk>  $chunks
     * @param  list<RetrievalCitation>  $citations
     * @param  list<RetrievalScoreExplanation>  $rankExplanations
     * @param  array<string, bool>  $guardrails
     * @param  list<string>  $warnings
     * @param  array<string, mixed>  $scope
     */
    public function __construct(
        public string $mode,
        public array $chunks,
        public array $citations,
        public array $rankExplanations,
        public array $guardrails,
        public array $warnings,
        public array $scope,
        public string $generatedAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode,
            'scope' => $this->scope,
            'chunks' => array_map(fn (RetrievalChunk $c) => $c->toArray(), $this->chunks),
            'citations' => array_map(fn (RetrievalCitation $c) => $c->toArray(), $this->citations),
            'rank_explanations' => array_map(fn (RetrievalScoreExplanation $e) => $e->toArray(), $this->rankExplanations),
            'guardrails' => $this->guardrails,
            'warnings' => $this->warnings,
            'generated_at' => $this->generatedAt,
        ];
    }

    /**
     * Compact form for embedding inside the Copilot response (chunks trimmed, no nested metadata).
     *
     * @return array<string, mixed>
     */
    public function toCopilotContext(): array
    {
        return [
            'mode' => $this->mode,
            'scope' => $this->scope,
            'chunk_count' => count($this->chunks),
            'chunks' => array_map(static fn (RetrievalChunk $c) => [
                'uuid' => $c->uuid,
                'chunk_type' => $c->chunkType,
                'entity_type' => $c->entityType,
                'entity_uuid' => $c->entityUuid,
                'entity_code' => $c->entityCode,
                'text_en' => $c->textEn,
                'source_document_key' => $c->sourceDocumentKey,
                'official_reference' => $c->officialReference,
                'revision_uuid' => $c->revisionUuid,
            ], $this->chunks),
            'citations' => array_map(fn (RetrievalCitation $c) => $c->toArray(), $this->citations),
            'rank_explanations' => array_map(fn (RetrievalScoreExplanation $e) => $e->toArray(), $this->rankExplanations),
            'warnings' => $this->warnings,
        ];
    }
}
