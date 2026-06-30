<?php

namespace App\Services\QuenyxAI\Adapters;

use App\Contracts\QuenyxAI\AiModuleAdapter;

/**
 * Sprint 22 — base class for AI module adapters.
 *
 * Supplies backward-compatible DEFAULTS for the metadata + discovery methods so a concrete adapter
 * only has to implement the essentials (moduleKey, moduleName, capabilities, availableActions,
 * buildContext) and override the rest when it has something specific to declare. This is what keeps
 * the {@see AiModuleAdapter} contract extensible without breaking existing adapters.
 *
 * By default an adapter:
 *  - declares no entity types, no pinned skills, and no pinned providers (it uses whatever the shared
 *    Quenyx AI platform resolves — the standard case);
 *  - reports a generic description/category/version/icon derived from its name.
 */
abstract class AbstractAiModuleAdapter implements AiModuleAdapter
{
    public function moduleDescription(): string
    {
        return $this->moduleName().' AI capabilities on the shared Quenyx AI platform.';
    }

    public function moduleCategory(): string
    {
        return 'General';
    }

    public function moduleVersion(): string
    {
        return '1.0.0';
    }

    public function moduleIcon(): string
    {
        return 'sparkles';
    }

    /**
     * @return list<string>
     */
    public function supportedEntities(): array
    {
        return [];
    }

    /**
     * @return list<string>
     */
    public function supportedSkills(): array
    {
        return [];
    }

    /**
     * @return list<string>
     */
    public function supportedProviders(): array
    {
        return [];
    }
}
