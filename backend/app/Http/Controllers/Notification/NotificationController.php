<?php

declare(strict_types=1);

namespace App\Http\Controllers\Notification;

use App\Services\AI\Workspace\AiWorkspaceContextResolver;
use App\Services\EntitlementService;
use App\Services\Notification\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sprint 24 — Notification Center API (QynNotify). Ingest (deduplicated/correlated/routed), list,
 * correlations, and mark-read. No fake routing — recipients are real workspace members.
 */
class NotificationController extends NotificationBaseController
{
    public function __construct(
        AiWorkspaceContextResolver $context,
        EntitlementService $entitlements,
        private readonly NotificationService $notifications,
    ) {
        parent::__construct($context, $entitlements);
    }

    public function index(Request $request): JsonResponse
    {
        $project = $this->workspace($request);

        return $this->ok([
            'notifications' => $this->notifications->list($project, [
                'status' => $request->query('status'),
                'severity' => $request->query('severity'),
            ]),
            'correlations' => $this->notifications->correlations($project),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $project = $this->workspace($request);
        $data = $request->validate([
            'workspace' => 'required|string',
            'title' => 'required|string|max:250',
            'body' => 'nullable|string',
            'type' => 'nullable|in:event,alert,incident,ticket,automation,digest',
            'severity' => 'nullable|in:critical,high,medium,low,info',
            'source' => 'nullable|string|max:64',
            'correlation_id' => 'nullable|string|max:191',
        ]);

        return $this->ok($this->notifications->summary($this->notifications->ingest($project, $request->user(), $data)), 201);
    }

    public function markRead(Request $request, string $uuid): JsonResponse
    {
        $project = $this->workspace($request);
        $notification = $this->notifications->find($project, $uuid);
        abort_if($notification === null, 404, 'Notification not found.');

        return $this->ok($this->notifications->summary($this->notifications->markRead($project, $request->user(), $notification)));
    }
}
