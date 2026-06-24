<?php

namespace App\Services\Ai\Skills;

use App\DataTransferObjects\Ai\AiSkillRequest;
use App\DataTransferObjects\Ai\AiSkillResult;
use App\Exceptions\Ai\AiSkillException;
use App\Services\Compliance\Evidence\EvidenceLifecycleService;
use App\Services\Compliance\Evidence\EvidenceNormalizationService;
use App\Services\Compliance\Evidence\EvidenceRelationshipService;
use App\Services\Compliance\Evidence\EvidenceValidationService;

/**
 * EvidenceSkill (QCIF Sprint 11) — returns an Evidence Context for a workspace by composing the
 * evidence services: normalized evidence nodes, their corpus relationship chains, validation
 * results, and the status catalog. Reuses evidence services only — NO AI, NO prompts, NO
 * OpenAI, and no DB access of its own.
 */
class EvidenceSkill extends AbstractAiSkill
{
    public function __construct(
        private readonly EvidenceNormalizationService $normalization = new EvidenceNormalizationService(),
        private readonly EvidenceRelationshipService $relationships = new EvidenceRelationshipService(),
        private readonly EvidenceValidationService $validation = new EvidenceValidationService(),
        private readonly EvidenceLifecycleService $lifecycle = new EvidenceLifecycleService(),
    ) {}

    public function key(): string
    {
        return 'evidence';
    }

    public function displayName(): string
    {
        return 'Evidence Context';
    }

    public function description(): string
    {
        return 'Returns workspace evidence with corpus relationship chains, validation, and the lifecycle status catalog.';
    }

    public function supportedContextTypes(): array
    {
        return ['evidence_context'];
    }

    public function execute(AiSkillRequest $request): AiSkillResult
    {
        $projectId = (int) ($request->param('project_id') ?? 0);
        if ($projectId <= 0) {
            throw new AiSkillException('Evidence context requires a workspace (project_id).', 'skill_missing_scope');
        }

        $evidence = $this->normalization->workspaceEvidence($projectId, $request->parameters);

        $nodes = [];
        $warnings = [];
        $statusCounts = [];

        foreach ($evidence as $record) {
            $validation = $this->validation->validate($record);
            $statusValue = $record->status?->value ?? 'unknown';
            $statusCounts[$statusValue] = ($statusCounts[$statusValue] ?? 0) + 1;

            $nodes[] = [
                'evidence' => $this->normalization->evidenceNode($record),
                'relationships' => $this->relationships->relationshipsFor($record),
                'validation' => $validation,
            ];

            foreach ($validation['issues'] as $issue) {
                $warnings[] = $record->uuid.': '.$issue['message'];
            }
        }

        $payload = [
            'context_type' => 'evidence_context',
            'evidence' => $nodes,
            'statuses' => $this->lifecycle->statusCatalog(),
            'counts' => [
                'evidence' => count($nodes),
                'by_status' => $statusCounts,
            ],
            'generated_at' => now()->toIso8601String(),
        ];

        return new AiSkillResult(
            skillKey: $this->key(),
            contextType: 'evidence_context',
            payload: $payload,
            citations: [],
            guardrails: $this->evidenceGuardrails(),
            warnings: $warnings,
        );
    }

    /**
     * Evidence is tenant data (unlike corpus context), so the guardrails differ: downstream
     * consumers must treat it as workspace-specific and must not invent evidence.
     *
     * @return array<string, bool>
     */
    private function evidenceGuardrails(): array
    {
        return [
            'use_only_provided_context' => true,
            'do_not_invent_evidence' => true,
            'tenant_data_included' => true,
            'no_legal_advice_disclaimer_required' => true,
        ];
    }

    protected function tags(): array
    {
        return ['evidence', 'lifecycle', 'relationships'];
    }
}
