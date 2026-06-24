<?php

namespace App\Contracts\Ai;

use App\DataTransferObjects\Ai\AiSkillMetadata;
use App\DataTransferObjects\Ai\AiSkillRequest;
use App\DataTransferObjects\Ai\AiSkillResult;

/**
 * Contract for an AI Skill — a discrete, reusable unit of compliance context production that
 * sits between the AI Orchestrator and the Compliance Intelligence services.
 *
 * STRICT boundaries: a skill contains NO provider logic and NO HTTP. It reuses existing
 * compliance services and returns an AI Context payload (corpus-derived data + citations +
 * guardrails). It never calls a model and never builds a prompt.
 */
interface AiSkillInterface
{
    /**
     * Stable skill key (e.g. "corpus_search").
     */
    public function key(): string;

    public function displayName(): string;

    public function description(): string;

    /**
     * Context types this skill can produce.
     *
     * @return list<string>
     */
    public function supportedContextTypes(): array;

    /**
     * Whether this skill can handle the given request (used by the router for auto-selection).
     */
    public function supports(AiSkillRequest $request): bool;

    /**
     * Execute the skill and return its result. Implementations reuse existing services only.
     */
    public function execute(AiSkillRequest $request): AiSkillResult;

    public function metadata(): AiSkillMetadata;

    /**
     * Lightweight readiness check. Must never throw.
     */
    public function health(): bool;
}
