<?php

namespace App\Http\Controllers\Compliance;

use App\DataTransferObjects\Ai\AiSkillResponse;
use App\DataTransferObjects\Compliance\Reasoning\ComplianceReasoningContext;
use App\DataTransferObjects\Compliance\Retrieval\RetrievalCitation;
use App\DataTransferObjects\Compliance\Retrieval\RetrievalQuery;
use App\Enums\Compliance\Retrieval\ComplianceRetrievalMode;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\Compliance\ComplianceCorpusAccessAuditLogger;
use App\Services\Compliance\Copilot\ComplianceCopilotPlanner;
use App\Services\Compliance\Rag\ComplianceHybridRetrievalService;
use App\Services\Compliance\Rag\ComplianceRagContextBuilder;
use App\Services\Compliance\Reasoning\ComplianceReasoningEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Workspace-scoped RAG context API (QCIF Sprint 17).
 *
 * Returns the bounded, cited RAG CONTEXT package only — NOT a final AI answer. The flow is
 * Intent → Skills → Hybrid Retrieval (deterministic + optional vector) → Reasoning → RAG Context.
 * It NEVER bypasses framework release / active revision / workspace entitlement / citations, and
 * falls back to deterministic retrieval (with a warning) when the vector provider is unavailable.
 * Access: sanctum + project membership + QynShield entitlement + audit + rate limit.
 */
class ComplianceRagController extends Controller
{
    public function __construct(
        private readonly ComplianceCopilotPlanner $planner,
        private readonly ComplianceHybridRetrievalService $hybridRetrieval,
        private readonly ComplianceReasoningEngine $reasoningEngine,
        private readonly ComplianceRagContextBuilder $ragContextBuilder,
        private readonly ComplianceCorpusAccessAuditLogger $auditLogger,
    ) {}

    public function query(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $validated = $request->validate([
            'query' => ['required', 'string', 'max:2000'],
            'framework' => ['sometimes', 'nullable', 'string', 'max:64'],
            'release' => ['sometimes', 'nullable', 'string', 'max:64'],
            'limit' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = (string) $validated['query'];
        $framework = $this->str($validated['framework'] ?? null);
        $release = $this->str($validated['release'] ?? null);

        $plan = $this->planner->plan($query, $framework, $release);
        $intent = $plan['intent'];
        $scope = $plan['scope'];

        $user = $request->user();
        if ($user !== null) {
            $this->auditLogger->logRetrieval($user, $project, 'rag.query', $intent->value, $scope['framework_key'] ?? null, $scope['release_code'] ?? null);
        }

        // Explicit framework/release that does not exist fails clearly (422).
        if (($scope['error_code'] ?? null) === 'scope_unresolved') {
            return response()->json([
                'success' => false,
                'error_code' => 'scope_unresolved',
                'data' => ['scope' => $this->scopeBlock($scope)],
            ], 422);
        }

        $retrievalQuery = new RetrievalQuery(
            query: $query,
            mode: ComplianceRetrievalMode::CopilotContext,
            projectId: $project->id,
            framework: $scope['framework_key'] ?? null,
            release: $scope['release_code'] ?? null,
            limit: (int) ($validated['limit'] ?? 20),
            code: $plan['code'] ?? null,
        );

        $detailed = $this->hybridRetrieval->query($retrievalQuery);
        $result = $detailed['result'];
        $responses = $detailed['responses'];
        $resolvedScope = $detailed['scope'];

        $reasoning = $this->reasoningEngine->reason(new ComplianceReasoningContext(
            intent: $intent,
            query: $query,
            code: $plan['code'] ?? null,
            scope: $resolvedScope,
            skillPayloads: $this->collectPayloads($responses),
            corpusCitations: array_map(static fn (RetrievalCitation $c) => $c->toArray(), $result->citations),
            groundingRefs: [],
            retrievalChunks: array_map(static fn ($c) => $c->toArray(), $result->chunks),
            guardrails: $result->guardrails,
        ));

        $ragContext = $this->ragContextBuilder->build($result, $reasoning, $responses);

        return response()->json([
            'success' => true,
            'data' => [
                'rag_enabled' => (bool) config('ai.rag.enabled', false),
                'intent' => $intent->value,
                'scope' => $this->scopeBlock($resolvedScope),
                'warnings' => $result->warnings,
                'rag_context' => $ragContext,
            ],
        ]);
    }

    /**
     * @param  list<AiSkillResponse>  $responses
     * @return array<string, array<string, mixed>>
     */
    private function collectPayloads(array $responses): array
    {
        $payloads = [];
        foreach ($responses as $response) {
            if ($response->success && $response->result !== null) {
                $payloads[$response->skillKey] = $response->result->payload;
            }
        }

        return $payloads;
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

    private function str(?string $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
