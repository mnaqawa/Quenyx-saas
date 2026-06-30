<?php

declare(strict_types=1);

namespace App\Services\Observe\Intelligence;

use App\Models\Project;
use App\Models\User;
use App\Services\AI\ModuleAiNarrator;

/**
 * Sprint 21 — the Operations Intelligence reuse point for the shared Quenyx AI runtime.
 *
 * Sprint 22 generalised the provider/prompt/audit wiring into the shared {@see ModuleAiNarrator};
 * this class now DELEGATES to it, preserving its exact Sprint 21 behavior (the Operations role
 * preamble, grounding guardrails, the `ops_intelligence_*` audit action prefix, and the response
 * shape) while ensuring there is exactly ONE place in the codebase that talks to a provider — no
 * duplicated provider/prompt/reasoning engines. Operations Intelligence feature services continue to
 * call {@see narrate()} unchanged.
 */
class OperationsAiAnalyst
{
    /** Operations-grounding guardrails mapped to the orchestrator's directive vocabulary. */
    private const GUARDRAILS = ModuleAiNarrator::DEFAULT_GUARDRAILS;

    private const ROLE_PREAMBLE = 'You are Quenyx AI operating as an Operations Intelligence analyst for the '
        .'QynSight monitoring platform. You explain real operational data (hosts, services, alerts, metrics, '
        .'capacity, topology). Use ONLY the operational evidence provided below — never invent hosts, metrics, '
        .'causes, or numbers. If the evidence is insufficient to answer, say so explicitly and state what is '
        .'missing. Be concise, specific, and actionable.';

    public function __construct(
        private readonly ModuleAiNarrator $narrator,
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
        return $this->narrator->narrate(
            $project,
            $user,
            $contextType,
            $evidence,
            $question,
            $rolePreamble ?? self::ROLE_PREAMBLE,
            'ops_intelligence_'.$action,
            $endpoint,
            self::GUARDRAILS,
            $responseFormat,
            $citations,
        );
    }
}
