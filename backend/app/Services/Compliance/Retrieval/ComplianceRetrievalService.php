<?php

namespace App\Services\Compliance\Retrieval;

use App\DataTransferObjects\Ai\AiSkillResponse;
use App\DataTransferObjects\Compliance\Retrieval\RetrievalQuery;
use App\DataTransferObjects\Compliance\Retrieval\RetrievalResult;
use App\Services\AI\Skills\AiSkillRouter;
use App\Services\Compliance\Copilot\ComplianceCopilotScopeResolver;

/**
 * Deterministic retrieval orchestrator (QCIF Sprint 15) — the foundation for future RAG.
 *
 * Pipeline: resolve scope → plan skills (per mode) → execute via the Skill Router → build chunks →
 * rank with explainable reasons → merge citations. It makes NO AI provider calls, NO embedding
 * generation, and NO vector-store calls. The only DB touch is scope resolution, delegated to
 * {@see ComplianceCopilotScopeResolver} (the sanctioned boundary); everything else consumes the
 * deterministic output the existing skills already produce.
 */
class ComplianceRetrievalService
{
    public function __construct(
        private readonly ComplianceCopilotScopeResolver $scopeResolver,
        private readonly ComplianceRetrievalPlanner $planner,
        private readonly AiSkillRouter $router,
        private readonly ComplianceRetrievalContextBuilder $contextBuilder,
        private readonly ComplianceRetrievalRanker $ranker,
        private readonly ComplianceRetrievalCitationMerger $citationMerger,
    ) {}

    /**
     * Full retrieval for the API: resolves scope, executes skills, returns ranked chunks.
     */
    public function query(RetrievalQuery $query): RetrievalResult
    {
        return $this->queryDetailed($query)['result'];
    }

    /**
     * Like {@see query()} but also returns the resolved scope, the executed (deterministic) skill
     * responses, and the code-resolved query. Used by the RAG runtime (QCIF Sprint 17) which needs
     * the skill payloads for the Reasoning Engine without re-running the skills.
     *
     * @return array{result: RetrievalResult, responses: list<AiSkillResponse>, scope: array<string, mixed>, query: RetrievalQuery}
     */
    public function queryDetailed(RetrievalQuery $query): array
    {
        $scope = $this->scopeResolver->resolve($query->framework, $query->release);
        $query = $query->withCode($this->planner->extractCode($query->query));

        $requests = $this->planner->plan($query, $scope['framework_key'], $scope['release_code']);
        $responses = $this->router->executeMany($requests);

        return [
            'result' => $this->assemble($query, $responses, $scope),
            'responses' => $responses,
            'scope' => $scope,
            'query' => $query,
        ];
    }

    /**
     * Build a retrieval result from skill responses that were ALREADY executed (used by the Copilot
     * integration so skills are not run twice). No scope resolution / routing here.
     *
     * @param  list<AiSkillResponse>  $responses
     * @param  array<string, mixed>  $scope
     */
    public function fromResponses(RetrievalQuery $query, array $responses, array $scope): RetrievalResult
    {
        $query = $query->withCode($query->code ?? $this->planner->extractCode($query->query));

        return $this->assemble($query, $responses, $scope);
    }

    /**
     * @param  list<AiSkillResponse>  $responses
     * @param  array<string, mixed>  $scope
     */
    private function assemble(RetrievalQuery $query, array $responses, array $scope): RetrievalResult
    {
        $built = $this->contextBuilder->build($responses);
        $ranked = $this->ranker->rank($built['chunks'], $built['relations'], $query);
        $citations = $this->citationMerger->merge($responses, $ranked['chunks']);

        return new RetrievalResult(
            mode: $query->mode->value,
            chunks: $ranked['chunks'],
            citations: $citations,
            rankExplanations: $ranked['explanations'],
            guardrails: $this->unionGuardrails($responses),
            warnings: $this->warnings($responses, $ranked['chunks'], $scope),
            scope: $this->scopeBlock($scope),
            generatedAt: now()->toIso8601String(),
        );
    }

    /**
     * @param  list<AiSkillResponse>  $responses
     * @return array<string, bool>
     */
    private function unionGuardrails(array $responses): array
    {
        $guardrails = [];
        foreach ($responses as $response) {
            foreach ($response->result?->guardrails ?? [] as $name => $enabled) {
                $guardrails[$name] = ($guardrails[$name] ?? false) || (bool) $enabled;
            }
        }

        return $guardrails;
    }

    /**
     * @param  list<AiSkillResponse>  $responses
     * @param  list<\App\DataTransferObjects\Compliance\Retrieval\RetrievalChunk>  $chunks
     * @param  array<string, mixed>  $scope
     * @return list<string>
     */
    private function warnings(array $responses, array $chunks, array $scope): array
    {
        $warnings = array_values($scope['warnings'] ?? []);

        foreach ($responses as $response) {
            if (! $response->success && $response->errorCode !== null) {
                $warnings[] = 'skill_'.$response->skillKey.'_'.$response->errorCode;
            }
        }

        if ($chunks === []) {
            $warnings[] = 'no_chunks_retrieved';
        }

        return array_values(array_unique($warnings));
    }

    /**
     * @param  array<string, mixed>  $scope
     * @return array<string, mixed>
     */
    private function scopeBlock(array $scope): array
    {
        return [
            'framework_key' => $scope['framework_key'] ?? null,
            'release_code' => $scope['release_code'] ?? null,
            'revision_uuid' => $scope['revision_uuid'] ?? null,
            'source' => $scope['source'] ?? 'unresolved',
            'warnings' => array_values($scope['warnings'] ?? []),
        ];
    }
}
