<?php

namespace App\Services\QuenyxAI;

use App\Contracts\Ai\AiProviderInterface;
use App\Contracts\Compliance\Retrieval\VectorRetrievalProviderInterface;
use App\Contracts\QuenyxAI\AiModuleAdapterInterface;
use App\Services\Ai\AiProviderRegistry;
use App\Services\Ai\Skills\AiSkillRegistry;
use App\Services\Compliance\Rag\VectorRetrievalProviderRegistry;
use App\Services\Compliance\Reasoning\ComplianceReasoningEngine;
use App\Services\Compliance\Retrieval\ComplianceRetrievalService;
use InvalidArgumentException;

/**
 * The Quenyx AI Platform (QCIF Sprint 19 — Quenyx AI Platform Foundation).
 *
 * A single, shared entrypoint that OWNS the generic AI runtime and lets every Quenyx module plug in
 * its own intelligence through an adapter. It does not contain business logic — it registers and
 * resolves: module adapters, the AI provider (via the Provider Registry), AI skills (via the Skill
 * Registry), retrieval (deterministic + optional vector provider), and reasoning. QynShield is the
 * first registered adapter; QynSight (and future modules) register the same way with no change here.
 */
class QuenyxAiPlatform
{
    /** @var array<string, AiModuleAdapterInterface> */
    private array $adapters = [];

    public function __construct(
        private readonly AiProviderRegistry $providers,
        private readonly AiSkillRegistry $skills,
        private readonly VectorRetrievalProviderRegistry $vectorProviders,
        private readonly ComplianceRetrievalService $retrieval,
        private readonly ComplianceReasoningEngine $reasoning,
    ) {}

    // -------------------------------------------------------------------------
    // Adapters
    // -------------------------------------------------------------------------

    public function registerAdapter(AiModuleAdapterInterface $adapter): void
    {
        $this->adapters[$adapter->moduleKey()] = $adapter;
    }

    /**
     * @return list<string>
     */
    public function modules(): array
    {
        return array_keys($this->adapters);
    }

    public function hasAdapter(string $moduleKey): bool
    {
        return isset($this->adapters[$moduleKey]);
    }

    public function adapter(string $moduleKey): AiModuleAdapterInterface
    {
        if (! isset($this->adapters[$moduleKey])) {
            throw new InvalidArgumentException("No Quenyx AI adapter registered for module: {$moduleKey}.");
        }

        return $this->adapters[$moduleKey];
    }

    /**
     * @return array<string, AiModuleAdapterInterface>
     */
    public function adapters(): array
    {
        return $this->adapters;
    }

    // -------------------------------------------------------------------------
    // Generic runtime resolution
    // -------------------------------------------------------------------------

    public function resolveProvider(?string $key = null): AiProviderInterface
    {
        return $this->providers->get($key);
    }

    public function providerRegistry(): AiProviderRegistry
    {
        return $this->providers;
    }

    public function resolveSkills(): AiSkillRegistry
    {
        return $this->skills;
    }

    public function retrievalService(): ComplianceRetrievalService
    {
        return $this->retrieval;
    }

    public function resolveVectorProvider(): ?VectorRetrievalProviderInterface
    {
        return $this->vectorProviders->resolve();
    }

    public function vectorProviderRegistry(): VectorRetrievalProviderRegistry
    {
        return $this->vectorProviders;
    }

    public function resolveReasoning(): ComplianceReasoningEngine
    {
        return $this->reasoning;
    }

    // -------------------------------------------------------------------------
    // Module awareness (UI-independent)
    // -------------------------------------------------------------------------

    /**
     * The full Quenyx vOPS HUB module catalog with live AI readiness — independent of frontend
     * sidebar visibility. The platform is aware of every module even when the UI hides it.
     *
     * @return list<array<string, mixed>>
     */
    public function moduleCatalog(): array
    {
        return (new QuenyxModuleCatalog())->describe($this);
    }

    // -------------------------------------------------------------------------
    // Capabilities
    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function capabilities(): array
    {
        return (new QuenyxAiCapabilityCatalog())->build($this);
    }
}
