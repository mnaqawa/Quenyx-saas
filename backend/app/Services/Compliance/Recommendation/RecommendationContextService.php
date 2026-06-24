<?php

namespace App\Services\Compliance\Recommendation;

/**
 * Assembles the deterministic Recommendation Context (QCIF Sprint 13) consumed by the
 * RecommendationSkill and the `/context` endpoint. It reuses the generation service and returns a
 * UUID-only payload (head + summary + recommendations). No persistence, no AI, no provider calls.
 */
class RecommendationContextService
{
    public function __construct(
        private readonly RecommendationGenerationService $generation = new RecommendationGenerationService(),
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function build(?string $frameworkKey, ?string $releaseCode, int $projectId, array $options = []): array
    {
        $result = $this->generation->toPublic(
            $this->generation->generate($frameworkKey, $releaseCode, $projectId, $options)
        );
        $result['context_type'] = 'recommendation_context';

        return $result;
    }
}
