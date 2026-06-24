<?php

namespace App\Services\Ai\Skills;

use App\DataTransferObjects\Ai\AiSkillRequest;
use App\DataTransferObjects\Ai\AiSkillResult;
use App\Exceptions\Ai\AiSkillException;
use App\Exceptions\ComplianceCorpusNotFoundException;
use App\Services\Compliance\Gap\GapAssessmentService;

/**
 * GapAssessmentSkill (QCIF Sprint 12) — returns a deterministic Gap Context for a workspace by
 * reusing the GapAssessmentService (which itself composes the Evidence Correlation Engine and the
 * evidence services). It exposes the summary, coverage tree, and per-requirement findings with
 * full explainability.
 *
 * Reuse only — NO prompts, NO AI execution, NO provider/OpenAI calls, NO DB access of its own.
 */
class GapAssessmentSkill extends AbstractAiSkill
{
    public function __construct(
        private readonly GapAssessmentService $gap = new GapAssessmentService(),
    ) {}

    public function key(): string
    {
        return 'gap_assessment';
    }

    public function displayName(): string
    {
        return 'Gap Context';
    }

    public function description(): string
    {
        return 'Returns a deterministic workspace gap assessment: requirement findings, coverage aggregation, and explainability — correlated from evidence with no AI.';
    }

    public function supportedContextTypes(): array
    {
        return ['gap_context', 'gap_assessment'];
    }

    public function execute(AiSkillRequest $request): AiSkillResult
    {
        $projectId = (int) ($request->param('project_id') ?? 0);
        if ($projectId <= 0) {
            throw new AiSkillException('Gap context requires a workspace (project_id).', 'skill_missing_scope');
        }

        try {
            $result = $this->gap->assess($request->frameworkKey, $request->releaseCode, $projectId);
        } catch (ComplianceCorpusNotFoundException $e) {
            throw new AiSkillException($e->getMessage(), 'gap_context_unresolved', 404);
        }

        $payload = array_merge($this->gap->toPublic($result), ['context_type' => 'gap_context']);

        return new AiSkillResult(
            skillKey: $this->key(),
            contextType: 'gap_context',
            payload: $payload,
            citations: [],
            guardrails: $this->gapGuardrails(),
            warnings: [],
        );
    }

    /**
     * Gap context is derived from tenant evidence + corpus and is fully deterministic. Downstream
     * consumers must not invent gaps/evidence and must treat findings as workspace-specific.
     *
     * @return array<string, bool>
     */
    private function gapGuardrails(): array
    {
        return [
            'use_only_provided_context' => true,
            'do_not_invent_evidence' => true,
            'do_not_invent_gaps' => true,
            'deterministic_findings_only' => true,
            'tenant_data_included' => true,
            'no_legal_advice_disclaimer_required' => true,
        ];
    }

    protected function tags(): array
    {
        return ['gap', 'coverage', 'evidence', 'deterministic'];
    }
}
