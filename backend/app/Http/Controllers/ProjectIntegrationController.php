<?php

namespace App\Http\Controllers;

use App\Models\Integration;
use App\Models\IntegrationConfiguration;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectIntegrationController extends Controller
{
    public function index(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

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
        $this->authorize('view', $project);

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
}
