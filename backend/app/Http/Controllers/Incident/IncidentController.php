<?php

declare(strict_types=1);

namespace App\Http\Controllers\Incident;

use App\Services\Ai\Workspace\AiWorkspaceContextResolver;
use App\Services\EntitlementService;
use App\Services\Incident\IncidentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sprint 23 — Incident CRUD + the unified incident workspace aggregate + timeline entries.
 */
class IncidentController extends IncidentBaseController
{
    public function __construct(
        AiWorkspaceContextResolver $context,
        EntitlementService $entitlements,
        private readonly IncidentService $incidents,
    ) {
        parent::__construct($context, $entitlements);
    }

    public function index(Request $request): JsonResponse
    {
        $project = $this->workspace($request);

        return $this->ok(['incidents' => $this->incidents->list($project, $request->query('status'))]);
    }

    public function store(Request $request): JsonResponse
    {
        $project = $this->workspace($request);
        $data = $request->validate([
            'title' => 'required|string|max:200',
            'description' => 'nullable|string|max:5000',
            'severity' => 'nullable|in:critical,high,medium,low',
            'source' => 'nullable|in:manual,alert',
            'alert_uuid' => 'nullable|uuid',
            'asset_uuid' => 'nullable|uuid',
        ]);

        $incident = $this->incidents->create($project, $request->user(), $data);

        return $this->ok($this->incidents->summary($incident), 201);
    }

    /** GET /incidents/{uuid} — unified incident workspace. */
    public function show(Request $request, string $uuid): JsonResponse
    {
        $project = $this->workspace($request);
        $incident = $this->resolveIncident($project, $uuid);

        return $this->ok($this->incidents->workspace($incident));
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $project = $this->workspace($request);
        $incident = $this->resolveIncident($project, $uuid);
        $data = $request->validate([
            'title' => 'sometimes|string|max:200',
            'description' => 'nullable|string|max:5000',
            'severity' => 'sometimes|in:critical,high,medium,low',
            'status' => 'sometimes|in:open,investigating,mitigated,resolved,closed',
            'resolution' => 'nullable|string|max:5000',
            'postmortem' => 'nullable|array',
        ]);

        if (array_key_exists('postmortem', $data)) {
            $incident->update(['postmortem' => $data['postmortem']]);
            unset($data['postmortem']);
        }

        return $this->ok($this->incidents->summary($this->incidents->update($incident, $request->user(), $data)));
    }

    /** POST /incidents/{uuid}/timeline */
    public function addTimeline(Request $request, string $uuid): JsonResponse
    {
        $project = $this->workspace($request);
        $incident = $this->resolveIncident($project, $uuid);
        $data = $request->validate([
            'type' => 'required|string|max:48',
            'category' => 'nullable|string|max:48',
            'description' => 'required|string|max:2000',
            'metadata' => 'nullable|array',
        ]);

        $entry = $this->incidents->addTimeline($incident, $request->user(), $data['type'], $data['category'] ?? null, $data['description'], $data['metadata'] ?? []);

        return $this->ok([
            'uuid' => $incident->uuid,
            'entry' => [
                'at' => optional($entry->at)->toIso8601String(),
                'type' => $entry->type,
                'category' => $entry->category,
                'description' => $entry->description,
            ],
        ], 201);
    }
}
