<?php

namespace App\Http\Controllers\Ai\Workspace;

use App\Contracts\QuenyxAI\AiModuleAdapter;
use App\Models\Project;
use App\Services\Ai\AiAccessAuditLogger;
use App\Services\Ai\Workspace\AiWorkspaceContextResolver;
use App\Services\EntitlementService;
use App\Services\QuenyxAI\AiModuleAdapterRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sprint 22 — AI Adapter Platform discovery API.
 *
 * Exposes the {@see AiModuleAdapterRegistry} so the AI Workspace discovers modules, capabilities, and
 * actions DYNAMICALLY — there is no per-module branching anywhere. Every response is filtered to the
 * adapters the WORKSPACE is actually entitled to (no data leakage), workspace-scoped by a required
 * `workspace` UUID, RBAC-gated via ProjectPolicy `accessAi`, and audited.
 */
class AiAdapterController extends AiWorkspaceBaseController
{
    public function __construct(
        AiWorkspaceContextResolver $context,
        private readonly AiModuleAdapterRegistry $registry,
        private readonly EntitlementService $entitlements,
        private readonly AiAccessAuditLogger $audit,
    ) {
        parent::__construct($context);
    }

    /** GET /api/ai/adapters — every adapter the workspace is entitled to. */
    public function index(Request $request): JsonResponse
    {
        $project = $this->workspace($request);

        $adapters = array_map(
            fn (AiModuleAdapter $a): array => $this->registry->describe($a),
            $this->entitledAdapters($project),
        );
        $this->log($request->user(), $project, 'list', 'ai.adapters.index');

        return $this->ok(['adapters' => array_values($adapters), 'count' => count($adapters)]);
    }

    /** GET /api/ai/adapters/{module} — one adapter descriptor. */
    public function show(Request $request, string $module): JsonResponse
    {
        $project = $this->workspace($request);

        abort_unless($this->registry->has($module), 404, 'No AI adapter is registered for this module.');
        abort_unless($this->entitlements->hasEffectiveModuleAccess($project, $module), 403, 'This module is not enabled for the workspace.');

        $this->log($request->user(), $project, 'show', 'ai.adapters.show', ['module' => $module]);

        return $this->ok($this->registry->describe($this->registry->get($module)));
    }

    /** GET /api/ai/adapters/capabilities — aggregated capabilities across entitled adapters. */
    public function capabilities(Request $request): JsonResponse
    {
        $project = $this->workspace($request);

        $capabilities = [];
        foreach ($this->entitledAdapters($project) as $adapter) {
            foreach ($adapter->capabilities() as $capability) {
                $capabilities[] = ['module' => $adapter->moduleKey(), 'capability' => $capability];
            }
        }
        $this->log($request->user(), $project, 'capabilities', 'ai.adapters.capabilities');

        return $this->ok(['capabilities' => $capabilities, 'count' => count($capabilities)]);
    }

    /** GET /api/ai/actions — aggregated contextual actions across entitled adapters. */
    public function actions(Request $request): JsonResponse
    {
        $project = $this->workspace($request);

        $actions = [];
        foreach ($this->entitledAdapters($project) as $adapter) {
            foreach ($adapter->availableActions() as $action) {
                $actions[] = array_merge(['module' => $adapter->moduleKey()], $action);
            }
        }
        $this->log($request->user(), $project, 'actions', 'ai.adapters.actions');

        return $this->ok(['actions' => $actions, 'count' => count($actions)]);
    }

    /**
     * Adapters the workspace is entitled to (no module branching — pure entitlement filter).
     *
     * @return list<AiModuleAdapter>
     */
    private function entitledAdapters(Project $project): array
    {
        return array_values(array_filter(
            $this->registry->all(),
            fn (AiModuleAdapter $a): bool => $this->entitlements->hasEffectiveModuleAccess($project, $a->moduleKey()),
        ));
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function log(?\App\Models\User $user, Project $project, string $action, string $endpoint, array $metadata = []): void
    {
        $this->audit->log($user, $project, 'ai_adapter_discovery_'.$action, $endpoint, 'registry', $metadata);
    }
}
