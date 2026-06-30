<?php

declare(strict_types=1);

namespace App\Http\Controllers\Incident;

use App\Services\AI\Workspace\AiWorkspaceContextResolver;
use App\Services\EntitlementService;
use App\Services\Incident\Intelligence\QynReactIntelligenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sprint 23 — QynReact Incident Intelligence API: incident copilot, evidence-based response
 * recommendations, and postmortem drafting. All require the `can_use_ai` capability and are grounded
 * in the unified incident workspace (cross-module reuse, no branching). Nothing is auto-executed.
 */
class IncidentIntelligenceController extends IncidentBaseController
{
    public function __construct(
        AiWorkspaceContextResolver $context,
        EntitlementService $entitlements,
        private readonly QynReactIntelligenceService $intelligence,
    ) {
        parent::__construct($context, $entitlements);
    }

    /** POST /incidents/{uuid}/copilot */
    public function copilot(Request $request, string $uuid): JsonResponse
    {
        $project = $this->workspace($request);
        $this->requireAi($project, $request);
        $incident = $this->resolveIncident($project, $uuid);
        $data = $request->validate([
            'workspace' => 'required|string',
            'message' => 'required|string|max:4000',
            'conversation' => 'sometimes|nullable|string|uuid',
        ]);

        return $this->ok($this->intelligence->copilot($project, $request->user(), $incident, (string) $data['message'], $data['conversation'] ?? null));
    }

    /** POST /incidents/{uuid}/recommend */
    public function recommend(Request $request, string $uuid): JsonResponse
    {
        $project = $this->workspace($request);
        $this->requireAi($project, $request);
        $incident = $this->resolveIncident($project, $uuid);

        return $this->ok($this->intelligence->recommend($project, $request->user(), $incident));
    }

    /** POST /incidents/{uuid}/postmortem */
    public function postmortem(Request $request, string $uuid): JsonResponse
    {
        $project = $this->workspace($request);
        $this->requireAi($project, $request);
        $incident = $this->resolveIncident($project, $uuid);

        return $this->ok($this->intelligence->postmortem($project, $request->user(), $incident));
    }
}
