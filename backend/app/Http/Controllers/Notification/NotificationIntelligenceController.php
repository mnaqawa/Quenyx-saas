<?php

declare(strict_types=1);

namespace App\Http\Controllers\Notification;

use App\Services\AI\Workspace\AiWorkspaceContextResolver;
use App\Services\EntitlementService;
use App\Services\Notification\Intelligence\NotificationIntelligenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sprint 24 — Notification Intelligence API (QynNotify): digest, executive summary, and copilot over
 * real active notifications. Requires the `can_use_ai` capability.
 */
class NotificationIntelligenceController extends NotificationBaseController
{
    public function __construct(
        AiWorkspaceContextResolver $context,
        EntitlementService $entitlements,
        private readonly NotificationIntelligenceService $intelligence,
    ) {
        parent::__construct($context, $entitlements);
    }

    public function digest(Request $request): JsonResponse
    {
        $project = $this->workspace($request);
        $this->requireAi($project, $request);

        return $this->ok($this->intelligence->digest($project, $request->user()));
    }

    public function executive(Request $request): JsonResponse
    {
        $project = $this->workspace($request);
        $this->requireAi($project, $request);

        return $this->ok($this->intelligence->executiveSummary($project, $request->user()));
    }

    public function copilot(Request $request): JsonResponse
    {
        $project = $this->workspace($request);
        $this->requireAi($project, $request);
        $data = $request->validate([
            'workspace' => 'required|string',
            'message' => 'required|string|max:4000',
            'conversation' => 'sometimes|nullable|string|uuid',
        ]);

        return $this->ok($this->intelligence->copilot($project, $request->user(), (string) $data['message'], $data['conversation'] ?? null));
    }
}
