<?php

namespace App\Services\AI\Skills;

use App\DataTransferObjects\Ai\AiSkillRequest;
use App\DataTransferObjects\Ai\AiSkillResult;
use App\Exceptions\Ai\AiSkillException;
use App\Exceptions\ComplianceCorpusNotFoundException;
use App\Services\Compliance\Recommendation\RecommendationContextService;

/**
 * RecommendationSkill (QCIF Sprint 13) — returns a deterministic Recommendation Context for a
 * workspace by reusing the recommendation services (which reuse the Gap Assessment and Evidence
 * Correlation engines). It exposes the summary and per-requirement recommendations with full
 * explainability.
 *
 * Reuse only — NO prompts, NO AI execution, NO provider/OpenAI calls, NO DB access of its own.
 */
class RecommendationSkill extends AbstractAiSkill
{
    public function __construct(
        private readonly RecommendationContextService $context = new RecommendationContextService(),
    ) {}

    public function key(): string
    {
        return 'recommendation';
    }

    public function displayName(): string
    {
        return 'Recommendation Context';
    }

    public function description(): string
    {
        return 'Returns deterministic, rule-based remediation recommendations grounded in gap findings — with priority, rationale, action items, and explainability. No AI.';
    }

    public function supportedContextTypes(): array
    {
        return ['recommendation_context', 'recommendations'];
    }

    public function execute(AiSkillRequest $request): AiSkillResult
    {
        $projectId = (int) ($request->param('project_id') ?? 0);
        if ($projectId <= 0) {
            throw new AiSkillException('Recommendation context requires a workspace (project_id).', 'skill_missing_scope');
        }

        $options = [
            'include_compliant' => (bool) ($request->param('include_compliant') ?? false),
        ];

        try {
            $payload = $this->context->build($request->frameworkKey, $request->releaseCode, $projectId, $options);
        } catch (ComplianceCorpusNotFoundException $e) {
            throw new AiSkillException($e->getMessage(), 'recommendation_context_unresolved', 404);
        }

        return new AiSkillResult(
            skillKey: $this->key(),
            contextType: 'recommendation_context',
            payload: $payload,
            citations: [],
            guardrails: $this->recommendationGuardrails(),
            warnings: [],
        );
    }

    /**
     * Recommendations are deterministic and rule-based. Downstream consumers must not invent
     * recommendations/evidence, must not present them as legal advice, and must treat them as
     * workspace-specific.
     *
     * @return array<string, bool>
     */
    private function recommendationGuardrails(): array
    {
        return [
            'use_only_provided_context' => true,
            'do_not_invent_recommendations' => true,
            'do_not_invent_evidence' => true,
            'deterministic_rules_only' => true,
            'tenant_data_included' => true,
            'no_legal_advice_disclaimer_required' => true,
        ];
    }

    protected function tags(): array
    {
        return ['recommendation', 'remediation', 'gap', 'deterministic'];
    }
}
