<?php

namespace App\Contracts\QuenyxAI;

use App\DataTransferObjects\Ai\AiPrompt;
use App\DataTransferObjects\Compliance\Reasoning\ReasoningOutput;
use App\DataTransferObjects\QuenyxAI\AiModuleRequest;

/**
 * Contract for a Quenyx module's AI adapter (QCIF Sprint 19 — Quenyx AI Platform Foundation).
 *
 * The Quenyx AI Platform owns the GENERIC runtime (provider registry, skill registry/routing,
 * prompt orchestration, retrieval/reasoning/RAG/audit contracts). Each module plugs its OWN
 * intelligence into that runtime through an adapter — the adapter is the only seam between the
 * shared platform and a module's domain services. It REUSES existing module services; it never
 * duplicates business logic and never calls a provider directly.
 *
 * `ReasoningOutput` and `AiPrompt` are the platform's shared, provider-agnostic, pure-data
 * reasoning/prompt contracts — they contain no natural-language answer and no provider response.
 */
interface AiModuleAdapterInterface
{
    /**
     * Stable module identifier (e.g. "qynshield").
     */
    public function moduleKey(): string;

    /**
     * Skill keys this module exposes through the shared Skill Registry.
     *
     * @return list<string>
     */
    public function supportedSkills(): array;

    /**
     * Context types this module can produce (skill context types + retrieval modes).
     *
     * @return list<string>
     */
    public function supportedContexts(): array;

    /**
     * Stage 1 — build the deterministic module AI context (scope + skill responses + retrieval +
     * citations + guardrails). Reuses the module's existing services. No AI provider call.
     *
     * @return array<string, mixed>
     */
    public function buildContext(AiModuleRequest $request): array;

    /**
     * Stage 2 — run the deterministic reasoning over the context produced by {@see buildContext()}.
     * No AI provider call.
     *
     * @param  array<string, mixed>  $context
     */
    public function buildReasoning(AiModuleRequest $request, array $context): ReasoningOutput;

    /**
     * Stage 3 — compose the provider-ready prompt from the reasoning output. The platform's Provider
     * Registry (not the adapter) is responsible for actually calling a model.
     */
    public function buildPrompt(AiModuleRequest $request, ReasoningOutput $reasoning): AiPrompt;
}
