<?php

declare(strict_types=1);

namespace App\Services\Incident;

use App\Contracts\QuenyxAI\AiModuleAdapter;
use App\Models\Project;
use App\Services\EntitlementService;
use App\Services\QuenyxAI\AiModuleAdapterRegistry;
use Throwable;

/**
 * Sprint 23 — Cross-module Intelligence orchestration.
 *
 * The unified incident flow (Alert → Asset → Incident → Automation → Knowledge → Resolution) reuses
 * the AI Adapter Registry: this orchestrator iterates the adapters the WORKSPACE is entitled to and
 * asks each to build its deterministic context. There is NO module branching anywhere — a future
 * module contributes to incidents automatically once it registers an adapter.
 */
class CrossModuleOrchestrator
{
    public function __construct(
        private readonly AiModuleAdapterRegistry $registry,
        private readonly EntitlementService $entitlements,
    ) {}

    /**
     * Gather deterministic context from every entitled module (except the excluded ones).
     *
     * @param  list<string>  $exclude  Module keys to skip (e.g. the calling module, to avoid recursion).
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function gather(Project $project, array $exclude = [], array $options = []): array
    {
        $modules = [];
        foreach ($this->registry->all() as $adapter) {
            $key = $adapter->moduleKey();
            if (in_array($key, $exclude, true)) {
                continue;
            }
            if (! $this->entitlements->hasEffectiveModuleAccess($project, $key)) {
                continue;
            }

            $modules[] = [
                'module' => $key,
                'name' => $adapter->moduleName(),
                'category' => $adapter->moduleCategory(),
                'capabilities' => $adapter->capabilities(),
                'context' => $this->safeContext($adapter, $project, $options),
            ];
        }

        return [
            'modules' => $modules,
            'module_count' => count($modules),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function safeContext(AiModuleAdapter $adapter, Project $project, array $options): array
    {
        try {
            return $adapter->buildContext($project, $options);
        } catch (Throwable $e) {
            return ['available' => false, 'error' => $e->getMessage()];
        }
    }
}
