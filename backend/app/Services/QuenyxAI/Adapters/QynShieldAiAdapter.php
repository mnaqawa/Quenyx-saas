<?php

namespace App\Services\QuenyxAI\Adapters;

use App\Contracts\QuenyxAI\AiModuleAdapterInterface;
use App\DataTransferObjects\Ai\AiPrompt;
use App\DataTransferObjects\Compliance\Reasoning\ComplianceReasoningContext;
use App\DataTransferObjects\Compliance\Reasoning\ReasoningOutput;
use App\DataTransferObjects\Compliance\Retrieval\RetrievalQuery;
use App\DataTransferObjects\QuenyxAI\AiModuleRequest;
use App\Enums\Compliance\Retrieval\ComplianceRetrievalMode;
use App\Services\AI\CompliancePromptOrchestrator;
use App\Services\AI\Skills\AiSkillRegistry;
use App\Services\Compliance\Copilot\ComplianceCopilotPlanner;
use App\Services\Compliance\Reasoning\ComplianceReasoningEngine;
use App\Services\Compliance\Retrieval\ComplianceRetrievalService;

/**
 * QynShield's adapter into the Quenyx AI Platform (QCIF Sprint 19) — the FIRST production adapter.
 *
 * It is a thin seam that WRAPS QynShield's existing services (Copilot Planner, Retrieval Service,
 * Reasoning Engine, Prompt Orchestrator, Skill Registry — and through them Gap, Recommendation,
 * Evidence, Knowledge Graph, Mapping). It moves NO business logic and duplicates NO service: every
 * method delegates to the canonical service that already owns that logic. No AI provider is called
 * here — the platform's Provider Registry owns that step.
 */
class QynShieldAiAdapter implements AiModuleAdapterInterface
{
    public function __construct(
        private readonly ComplianceCopilotPlanner $planner,
        private readonly ComplianceRetrievalService $retrieval,
        private readonly ComplianceReasoningEngine $reasoningEngine,
        private readonly CompliancePromptOrchestrator $orchestrator,
        private readonly AiSkillRegistry $skills,
    ) {}

    public function moduleKey(): string
    {
        return 'qynshield';
    }

    /**
     * @return list<string>
     */
    public function supportedSkills(): array
    {
        return $this->skills->keys();
    }

    /**
     * @return list<string>
     */
    public function supportedContexts(): array
    {
        $contexts = [];
        foreach ($this->skills->all() as $skill) {
            foreach ($skill->supportedContextTypes() as $type) {
                $contexts[$type] = true;
            }
        }
        foreach (ComplianceRetrievalMode::cases() as $mode) {
            $contexts[$mode->value] = true;
        }

        return array_keys($contexts);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildContext(AiModuleRequest $request): array
    {
        $plan = $this->planner->classify($request->query);

        $query = new RetrievalQuery(
            query: $request->query,
            mode: ComplianceRetrievalMode::CopilotContext,
            projectId: $request->projectId,
            framework: $request->framework,
            release: $request->release,
            limit: (int) ($request->options['limit'] ?? 20),
            code: $request->code ?? $plan['code'],
        );

        $detailed = $this->retrieval->queryDetailed($query);
        $result = $detailed['result'];
        $responses = $detailed['responses'];
        $scope = $detailed['scope'];

        $skillPrompt = $this->orchestrator->composeFromSkills($responses, $request->query);

        return [
            'module' => $this->moduleKey(),
            'intent' => $plan['intent']->value,
            'intent_enum' => $plan['intent'],
            'code' => $request->code ?? $plan['code'],
            'scope' => $scope,
            'responses' => $responses,
            'retrieval' => $result,
            'retrieval_context' => $result->toCopilotContext(),
            'citations' => $skillPrompt->citations,
            'guardrails' => $skillPrompt->guardrails,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function buildReasoning(AiModuleRequest $request, array $context): ReasoningOutput
    {
        $intent = $context['intent_enum'] ?? $this->planner->classify($request->query)['intent'];

        $payloads = [];
        foreach (($context['responses'] ?? []) as $response) {
            if ($response->success && $response->result !== null) {
                $payloads[$response->skillKey] = $response->result->payload;
            }
        }

        $result = $context['retrieval'] ?? null;
        $chunks = $result !== null ? array_map(static fn ($c) => $c->toArray(), $result->chunks) : [];

        return $this->reasoningEngine->reason(new ComplianceReasoningContext(
            intent: $intent,
            query: $request->query,
            code: $context['code'] ?? $request->code,
            scope: $context['scope'] ?? [],
            skillPayloads: $payloads,
            corpusCitations: $context['citations'] ?? [],
            groundingRefs: [],
            retrievalChunks: $chunks,
            guardrails: $context['guardrails'] ?? [],
        ));
    }

    public function buildPrompt(AiModuleRequest $request, ReasoningOutput $reasoning): AiPrompt
    {
        return $this->orchestrator->composeFromReasoning($reasoning, $request->query, $request->options);
    }
}
