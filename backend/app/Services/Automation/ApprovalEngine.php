<?php

declare(strict_types=1);

namespace App\Services\Automation;

use App\Models\Automation\AutomationApproval;
use App\Models\Automation\AutomationExecution;
use App\Models\Project;
use App\Models\User;

/**
 * Sprint 23 — the Approval Engine: the human gate in front of every live execution.
 *
 * A live execution cannot run until an authorized operator approves it; rejecting cancels the
 * execution. Every decision is audited. This is what makes "no automatic destructive actions"
 * structurally true.
 */
class ApprovalEngine
{
    public function __construct(
        private readonly ExecutionEngine $engine,
        private readonly AutomationAuditLogger $audit,
    ) {}

    /**
     * @return list<AutomationApproval>
     */
    public function pending(Project $project): array
    {
        return AutomationApproval::query()
            ->where('project_id', $project->id)
            ->where('status', 'pending')
            ->with('execution')
            ->orderByDesc('created_at')
            ->get()
            ->all();
    }

    public function decide(AutomationApproval $approval, User $user, bool $approve, ?string $reason = null): AutomationExecution
    {
        $approval->update([
            'status' => $approve ? 'approved' : 'rejected',
            'decided_by' => $user->id,
            'decided_at' => now(),
            'reason' => $reason,
        ]);

        $execution = $approval->execution;

        if ($approve) {
            $this->audit->log($user, $execution->project, 'automation_execution_approved', [
                'execution_uuid' => $execution->uuid,
                'approval_uuid' => $approval->uuid,
            ]);

            return $this->engine->runApproved($execution, $user);
        }

        $execution->update(['status' => 'cancelled']);
        $this->audit->log($user, $execution->project, 'automation_execution_rejected', [
            'execution_uuid' => $execution->uuid,
            'approval_uuid' => $approval->uuid,
            'reason' => $reason,
        ]);

        return $execution->fresh(['steps', 'approval']);
    }
}
