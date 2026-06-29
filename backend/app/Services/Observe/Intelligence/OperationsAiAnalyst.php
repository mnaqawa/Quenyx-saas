<?php

declare(strict_types=1);

namespace App\Services\Observe\Intelligence;

use App\Contracts\Ai\AiProviderInterface;
use App\DataTransferObjects\Ai\AiCompletionRequest;
use App\Exceptions\Ai\AiProviderException;
use App\Models\Project;
use App\Models\User;
use App\Services\Ai\AiAccessAuditLogger;
use App\Services\Ai\AiProviderRegistry;
use App\Services\Ai\CompliancePromptOrchestrator;

/**
 * Sprint 21 — the SINGLE Operations Intelligence reuse point for the shared Quenyx AI runtime.
 *
 * This class deliberately owns NO AI logic of its own: it reuses the existing provider registry,
 * the existing prompt orchestrator (which embeds grounding guardrails + citations), and the existing
 * audit logger. Every Operations Intelligence feature service narrates its deterministic evidence
 * through this one method, so there is exactly ONE place that talks to a provider — no duplicated
 * provider/prompt/reasoning engines.
 *
 * Safety: when the master AI flag is off (default) the mock provider answers, so the surface is
 * always production-safe; the deterministic evidence is still returned by the feature services
 * regardless of whether a real provider is configured.
 */
class OperationsAiAnalyst
{
    /** Operations-grounding guardrails mapped to the orchestrator's directive vocabulary. */
    private const GUARDRAILS = [
        'use_only_provided_context' => true,
        'do_not_invent_controls' => true,
        'cite_every_claim' => true,
    ];

    private const ROLE_PREAMBLE = 'You are Quenyx AI operating as an Operations Intelligence analyst for the '
        .'QynSight monitoring platform. You explain real operational data (hosts, services, alerts, metrics, '
        .'capacity, topology). Use ONLY the operational evidence provided below — never invent hosts, metrics, '
        .'causes, or numbers. If the evidence is insufficient to answer, say so explicitly and state what is '
        .'missing. Be concise, specific, and actionable.';

    public function __construct(
        private readonly AiProviderRegistry $registry,
        private readonly CompliancePromptOrchestrator $orchestrator,
        private readonly AiAccessAuditLogger $auditLogger,
    ) {}

    /**
     * Narrate deterministic operational evidence with the shared AI runtime.
     *
     * @param  array<string, mixed>  $evidence  The real operational data (the only source of truth).
     * @param  list<array<string, mixed>>  $citations  Evidence references the model must cite.
     * @return array<string, mixed>
     */
    public function narrate(
        Project $project,
        ?User $user,
        string $contextType,
        array $evidence,
        string $question,
        string $action,
        string $endpoint,
        string $responseFormat = 'text',
        array $citations = [],
        ?string $rolePreamble = null,
    ): array {
        $aiEnabled = (bool) config('ai.feature_flags.enabled', false);

        try {
            $provider = $this->resolveProvider();

            $aiContext = [
                'context_type' => $contextType,
                'payload' => $evidence,
                'citations' => $citations,
                'guardrails' => self::GUARDRAILS,
            ];

            $prompt = $this->orchestrator->buildPrompt($aiContext, $question, [
                'role_preamble' => $rolePreamble ?? self::ROLE_PREAMBLE,
            ]);

            $request = new AiCompletionRequest(
                messages: $prompt->toMessages(),
                model: null,
                temperature: (float) config('ai.defaults.temperature', 0.0),
                maxTokens: (int) config('ai.defaults.max_tokens', 1024),
                responseFormat: $responseFormat === 'json' ? 'json' : 'text',
                stream: false,
            );

            $this->auditLogger->log($user, $project, 'ops_intelligence_'.$action, $endpoint, $provider->key(), [
                'context_type' => $contextType,
            ]);

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
            // Never fail the whole request — the deterministic evidence is still useful. Report the
            // narrative as unavailable with the honest reason.
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
