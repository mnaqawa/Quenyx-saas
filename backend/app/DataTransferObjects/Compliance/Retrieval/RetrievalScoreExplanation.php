<?php

namespace App\DataTransferObjects\Compliance\Retrieval;

/**
 * Explains WHY a chunk ranked where it did (QCIF Sprint 15).
 *
 * Ranking is explainable and deterministic: it uses named rank REASONS, never a numeric/
 * probabilistic score and never AI. `position` is the 1-based deterministic order, not a confidence.
 */
final readonly class RetrievalScoreExplanation
{
    /**
     * @param  list<string>  $reasons  ordered strongest → weakest
     */
    public function __construct(
        public string $chunkUuid,
        public ?string $entityUuid,
        public ?string $entityCode,
        public string $primaryReason,
        public array $reasons,
        public int $position,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'chunk_uuid' => $this->chunkUuid,
            'entity_uuid' => $this->entityUuid,
            'entity_code' => $this->entityCode,
            'primary_reason' => $this->primaryReason,
            'reasons' => $this->reasons,
            'position' => $this->position,
        ];
    }
}
