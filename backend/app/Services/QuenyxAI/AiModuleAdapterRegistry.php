<?php

namespace App\Services\QuenyxAI;

use App\Contracts\QuenyxAI\AiModuleAdapter;
use App\Models\Project;
use InvalidArgumentException;

/**
 * Sprint 22 — the AI Adapter Registry: the heart of the reusable AI Adapter Platform.
 *
 * Quenyx AI never branches on module names. Instead, every module implements {@see AiModuleAdapter}
 * and registers here; the platform then DISCOVERS modules, capabilities, actions, entities, skills,
 * and providers dynamically. A future module (QynRun, QynNotify, QynReact, QynKnow, QynSupport,
 * QynBalance, QynVA) becomes AI-enabled by doing exactly two things: implement an adapter, register
 * it. No platform change, no `if (module == ...)` anywhere.
 *
 * The registry is a process-wide singleton (bound in {@see \App\Providers\AppServiceProvider}); it
 * holds NO business logic and calls NO provider — it is pure discovery/metadata over adapters.
 */
class AiModuleAdapterRegistry
{
    /** @var array<string, AiModuleAdapter> */
    private array $adapters = [];

    public function register(AiModuleAdapter $adapter): void
    {
        $this->adapters[$adapter->moduleKey()] = $adapter;
    }

    public function has(string $moduleKey): bool
    {
        return isset($this->adapters[$moduleKey]);
    }

    public function get(string $moduleKey): AiModuleAdapter
    {
        if (! isset($this->adapters[$moduleKey])) {
            throw new InvalidArgumentException("No AI adapter registered for module: {$moduleKey}.");
        }

        return $this->adapters[$moduleKey];
    }

    /**
     * @return array<string, AiModuleAdapter>
     */
    public function all(): array
    {
        return $this->adapters;
    }

    /**
     * @return list<string>
     */
    public function modules(): array
    {
        return array_keys($this->adapters);
    }

    /**
     * Metadata descriptor for a single adapter (no context build — pure discovery).
     *
     * @return array<string, mixed>
     */
    public function describe(AiModuleAdapter $adapter): array
    {
        return [
            'module_key' => $adapter->moduleKey(),
            'module_name' => $adapter->moduleName(),
            'module_description' => $adapter->moduleDescription(),
            'module_category' => $adapter->moduleCategory(),
            'module_version' => $adapter->moduleVersion(),
            'module_icon' => $adapter->moduleIcon(),
            'capabilities' => $adapter->capabilities(),
            'supported_entities' => $adapter->supportedEntities(),
            'supported_skills' => $adapter->supportedSkills(),
            'supported_providers' => $adapter->supportedProviders(),
            'available_actions' => $adapter->availableActions(),
        ];
    }

    /**
     * Metadata descriptors for every registered adapter.
     *
     * @return list<array<string, mixed>>
     */
    public function describeAll(): array
    {
        return array_values(array_map(fn (AiModuleAdapter $a): array => $this->describe($a), $this->adapters));
    }

    /**
     * Aggregated capabilities across all adapters (each tagged with its owning module).
     *
     * @return list<array<string, string>>
     */
    public function capabilities(): array
    {
        $out = [];
        foreach ($this->adapters as $adapter) {
            foreach ($adapter->capabilities() as $capability) {
                $out[] = ['module' => $adapter->moduleKey(), 'capability' => $capability];
            }
        }

        return $out;
    }

    /**
     * Aggregated contextual actions across all adapters (each tagged with its owning module).
     *
     * @return list<array<string, mixed>>
     */
    public function actions(): array
    {
        $out = [];
        foreach ($this->adapters as $adapter) {
            foreach ($adapter->availableActions() as $action) {
                $out[] = array_merge(['module' => $adapter->moduleKey()], $action);
            }
        }

        return $out;
    }

    /**
     * Aggregated entity types supported across all adapters.
     *
     * @return list<string>
     */
    public function entities(): array
    {
        $entities = [];
        foreach ($this->adapters as $adapter) {
            foreach ($adapter->supportedEntities() as $entity) {
                $entities[$entity] = true;
            }
        }

        return array_keys($entities);
    }

    /**
     * Build a module's deterministic context (delegates to the adapter — no logic here).
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function buildContext(string $moduleKey, Project $project, array $options = []): array
    {
        return $this->get($moduleKey)->buildContext($project, $options);
    }
}
