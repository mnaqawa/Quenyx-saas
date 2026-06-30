<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Contracts\Ai\AiProviderInterface;
use App\DataTransferObjects\Ai\AiCompletionRequest;
use App\Exceptions\Ai\AiProviderException;
use App\Models\Project;
use App\Models\User;

/**
 * Sprint 22 — the SINGLE, shared module narration service for the whole AI Adapter Platform.
 *
 * Any module (QynSight, QynAsset, every future module) narrates its deterministic, real-data evidence
 * through this ONE method. It owns NO AI logic of its own beyond wiring: it reuses the existing
 * provider registry, the existing prompt orchestrator (grounding guardrails + citations), and the
 * existing audit logger. There is therefore exactly one place in the codebase that talks to a
 * provider — no duplicated provider/prompt/reasoning engines anywhere.
 *
 * Safety: when the master AI flag is off (default) the mock provider answers, so every module surface
 * is production-safe; the deterministic evidence is always returned by the feature services
 * regardless of whether a real provider is configured.
 *
 * This generalises the Sprint 21 Operations narrator: {@see \App\Services\Observe\Intelligence\OperationsAiAnalyst}
 * now delegates here, preserving its exact behavior.
 */
class ModuleAiNarrator
{
    /** Default grounding guardrails (orchestrator directive vocabulary). */
    public const DEFAULT_GUARDRAILS = [
        'use_only_provided_context' => true,
        'do_not_invent_controls' => true,
        'cite_every_claim' => true,
    ];

    public function __construct(
        private readonly AiProviderRegistry $registry,
        private readonly CompliancePromptOrchestrator $orchestrator,
        private readonly AiAccessAuditLogger $auditLogger,
    ) {}

    /**
     * Narrate deterministic module evidence with the shared AI runtime.
     *
     * @param  array<string, mixed>  $evidence  The real domain data (the only source of truth).
     * @param  string  $auditAction  Fully-qualified audit action (e.g. "ops_intelligence_copilot").
     * @param  array<string, bool>  $guardrails
     * @param  list<array<string, mixed>>  $citations  Evidence references the model must cite.
     * @param  array<string, mixed>  $auditMetadata  Extra (non-sensitive) audit metadata.
     * @return array<string, mixed>
     */
    public function narrate(
        Project $project,
        ?User $user,
        string $contextType,
        array $evidence,
        string $question,
        string $rolePreamble,
        string $auditAction,
        string $endpoint,
        array $guardrails = self::DEFAULT_GUARDRAILS,
        string $responseFormat = 'text',
        array $citations = [],
        array $auditMetadata = [],
    ): array {
        $aiEnabled = (bool) config('ai.feature_flags.enabled', false);

        try {
            $provider = $this->resolveProvider();

            $aiContext = [
                'context_type' => $contextType,
                'payload' => $evidence,
                'citations' => $citations,
                'guardrails' => $guardrails,
            ];

            $prompt = $this->orchestrator->buildPrompt($aiContext, $question, [
                'role_preamble' => $rolePreamble,
            ]);

            $request = new AiCompletionRequest(
                messages: $prompt->toMessages(),
                model: null,
                temperature: (float) config('ai.defaults.temperature', 0.0),
                maxTokens: (int) config('ai.defaults.max_tokens', 1024),
                responseFormat: $responseFormat === 'json' ? 'json' : 'text',
                stream: false,
            );

            $this->auditLogger->log($user, $project, $auditAction, $endpoint, $provider->key(), array_merge([
                'context_type' => $contextType,
            ], $auditMetadata));

            $response = $provider->responses($request);

            return [
                'available' => true,
                'ai_enabled' => $aiEnabled,
                'provider' => $provider->key(),
                'model' => $response->model,
                'mocked' => $response->mocked,
                'content' => $response->content,
                'structured' => $response->structured,
                'usage' => $response->usage->toArray(),
                'generated_at' => now()->toIso8601String(),
            ];
        } catch (AiProviderException $e) {
            // Never fail the whole request — the deterministic evidence is still useful.
            return [
                'available' => false,
                'ai_enabled' => $aiEnabled,
                'error' => $e->getMessage(),
                'error_code' => $e->errorCode(),
                'generated_at' => now()->toIso8601String(),
            ];
        }
    }

    private function resolveProvider(): AiProviderInterface
    {
        if (! (bool) config('ai.feature_flags.enabled', false)) {
            return $this->registry->get('mock');
        }

        return $this->registry->get(null);
    }
}
