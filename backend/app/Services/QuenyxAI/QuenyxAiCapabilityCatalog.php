<?php

namespace App\Services\QuenyxAI;

use App\Enums\Compliance\Reasoning\ComplianceReasoningDecisionType;
use App\Enums\Compliance\Retrieval\ComplianceRetrievalMode;
use App\Services\Compliance\Reasoning\ComplianceReasoningRuleSet;

/**
 * Builds the Quenyx AI Platform capability catalog (QCIF Sprint 19) — entirely DYNAMIC.
 *
 * Nothing here is hard-coded: modules come from the registered adapters, skills from the Skill
 * Registry, providers from the Provider Registry, reasoning from the rule catalog + decision types,
 * retrieval from the retrieval modes, and RAG from the vector provider registry. Adding a module,
 * skill, or provider automatically changes this output with no edit here.
 */
class QuenyxAiCapabilityCatalog
{
    /**
     * @return array<string, mixed>
     */
    public function build(QuenyxAiPlatform $platform): array
    {
        return [
            'platform' => 'quenyx-ai',
            'modules' => $this->modules($platform),
            'module_catalog' => $platform->moduleCatalog(),
            'skills' => $platform->resolveSkills()->describe(),
            'providers' => $this->providers($platform),
            'reasoning' => $this->reasoning(),
            'retrieval' => $this->retrieval(),
            'rag' => $this->rag($platform),
            'supported_contexts' => $this->supportedContexts($platform),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function modules(QuenyxAiPlatform $platform): array
    {
        $modules = [];
        foreach ($platform->adapters() as $adapter) {
            $modules[] = [
                'key' => $adapter->moduleKey(),
                'supported_skills' => $adapter->supportedSkills(),
                'supported_contexts' => $adapter->supportedContexts(),
            ];
        }

        return $modules;
    }

    /**
     * @return array<string, mixed>
     */
    private function providers(QuenyxAiPlatform $platform): array
    {
        $registry = $platform->providerRegistry();
        $available = $registry->available();

        $implemented = array_values(array_filter($available, static fn ($key) => $registry->has($key)));

        return [
            'default' => $registry->defaultKey(),
            'available' => $available,
            'implemented' => $implemented,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reasoning(): array
    {
        return [
            'rules' => ComplianceReasoningRuleSet::catalog(),
            'decision_types' => array_map(static fn ($c) => $c->value, ComplianceReasoningDecisionType::cases()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function retrieval(): array
    {
        return [
            'modes' => array_map(static fn ($c) => $c->value, ComplianceRetrievalMode::cases()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rag(QuenyxAiPlatform $platform): array
    {
        $registry = $platform->vectorProviderRegistry();

        return [
            'enabled' => $registry->enabled(),
            'vector_provider' => $registry->providerKey(),
            'provider_resolved' => $platform->resolveVectorProvider() !== null,
        ];
    }

    /**
     * @return list<string>
     */
    private function supportedContexts(QuenyxAiPlatform $platform): array
    {
        $contexts = [];
        foreach ($platform->adapters() as $adapter) {
            foreach ($adapter->supportedContexts() as $context) {
                $contexts[$context] = true;
            }
        }

        return array_keys($contexts);
    }
}
