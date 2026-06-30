<?php

declare(strict_types=1);

namespace App\Http\Controllers\Automation;

use App\Models\Automation\AutomationApproval;
use App\Services\AI\Workspace\AiWorkspaceContextResolver;
use App\Services\Automation\ApprovalEngine;
use App\Services\Automation\ExecutionHistory;
use App\Services\EntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sprint 23 — Approvals API. Listing requires `accessAi`; deciding (which can trigger a live
 * execution) requires `administerAi`. Every decision is audited.
 */
class AutomationApprovalController extends AutomationBaseController
{
    public function __construct(
        AiWorkspaceContextResolver $context,
        EntitlementService $entitlements,
        private readonly ApprovalEngine $approvals,
        private readonly ExecutionHistory $history,
    ) {
        parent::__construct($context, $entitlements);
    }

    public function index(Request $request): JsonResponse
    {
        $project = $this->workspace($request);

        $pending = array_map(function (AutomationApproval $a): array {
            return [
                'uuid' => $a->uuid,
                'status' => $a->status,
                'created_at' => optional($a->created_at)->toIso8601String(),
                'execution' => $a->execution ? $this->history->summary($a->execution) : null,
            ];
        }, $this->approvals->pending($project));

        return $this->ok(['approvals' => $pending]);
    }

    /** POST /approvals/{uuid}/decide */
    public function decide(Request $request, string $uuid): JsonResponse
    {
        $project = $this->workspace($request);
        $this->requireAdmin($project);
        $data = $request->validate([
            'approve' => 'required|boolean',
            'reason' => 'nullable|string|max:500',
        ]);

        $approval = AutomationApproval::where('project_id', $project->id)->where('uuid', $uuid)->firstOrFail();

        if ($approval->status !== 'pending') {
            return $this->fail('This approval has already been decided.', 'already_decided', 422);
        }

        $execution = $this->approvals->decide($approval, $request->user(), (bool) $data['approve'], $data['reason'] ?? null);

        return $this->ok($this->history->detail($execution));
    }
}
