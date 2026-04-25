<?php

namespace App\Http\Controllers;

use App\Models\Integration;
use App\Models\IntegrationConfiguration;
use App\Models\Project;
use App\Services\EntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectIntegrationController extends Controller
{
    private const INTEGRATIONS_MODULE = 'qynintegrations';

    public function __construct(
        private EntitlementService $entitlementService
    ) {
    }

    public function index(Project $project): JsonResponse
    {
        $this->authorize('view', $project);
        if ($denied = $this->deniedWithoutIntegrationsModule($project)) {
            return $denied;
        }

        $configuredIntegrationIds = IntegrationConfiguration::query()
            ->where('project_id', $project->id)
            ->pluck('integration_id')
            ->filter()
            ->all();

        $integrations = Integration::query()
            ->orderBy('name')
            ->get()
            ->map(function (Integration $integration) use ($configuredIntegrationIds) {
                return [
                    'id' => $integration->id,
                    'name' => $integration->name,
                    'description' => $integration->description,
                    'status' => $integration->status,
                    'endpoint' => $integration->endpoint ?? 'Not configured',
                    'primary_action' => $integration->primary_action,
                    'secondary_action' => $integration->secondary_action,
                    'configured' => in_array($integration->id, $configuredIntegrationIds, true),
                ];
            })
            ->values();

        return response()->json([
            'success' => true,
            'data' => $integrations,
        ]);
    }

    public function showConfiguration(Project $project, Integration $integration): JsonResponse
    {
        $this->authorize('view', $project);
        if ($denied = $this->deniedWithoutIntegrationsModule($project)) {
            return $denied;
        }

        $configuration = IntegrationConfiguration::query()
            ->where('project_id', $project->id)
            ->where('integration_id', $integration->id)
            ->first();

        return response()->json([
            'success' => true,
            'data' => [
                'integration_id' => $integration->id,
                'project_id' => $project->id,
                'settings' => $configuration?->settings ?? null,
            ],
        ]);
    }

    public function upsertConfiguration(Request $request, Project $project, Integration $integration): JsonResponse
    {
        $this->authorize('update', $project);
        if ($denied = $this->deniedWithoutIntegrationsModule($project)) {
            return $denied;
        }

        $payload = $request->validate([
            'settings' => ['required', 'array'],
        ]);

        $configuration = IntegrationConfiguration::updateOrCreate(
            [
                'project_id' => $project->id,
                'integration_id' => $integration->id,
            ],
            [
                'settings' => $payload['settings'],
            ]
        );

        return response()->json([
            'success' => true,
            'data' => [
                'integration_id' => $integration->id,
                'project_id' => $project->id,
                'settings' => $configuration->settings,
            ],
        ]);
    }

    /**
     * Integrations are gated by the qynintegrations module (plan + overrides), matching gateway behavior.
     */
    private function deniedWithoutIntegrationsModule(Project $project): ?JsonResponse
    {
        $entitlements = $this->entitlementService->getEntitlements($project);
        $allowed = $entitlements['modules_allowed'] ?? [];
        if (! in_array(self::INTEGRATIONS_MODULE, $allowed, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Your current plan does not allow access to this module',
            ], 403);
        }

        return null;
    }
}
