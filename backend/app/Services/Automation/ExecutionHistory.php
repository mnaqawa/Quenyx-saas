<?php

declare(strict_types=1);

namespace App\Services\Automation;

use App\Models\Automation\AutomationExecution;
use App\Models\Project;

/**
 * Sprint 23 — Execution History: a read model over automation executions for the Executions and
 * Execution History UIs. Workspace-scoped, UUID-addressed.
 */
class ExecutionHistory
{
    /**
     * @param  array<string, mixed>  $filters  status, adapter_key, incident_id, limit
     * @return list<array<string, mixed>>
     */
    public function list(Project $project, array $filters = []): array
    {
        $query = AutomationExecution::query()
            ->where('project_id', $project->id)
            ->orderByDesc('created_at');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['adapter_key'])) {
            $query->where('adapter_key', $filters['adapter_key']);
        }
        if (! empty($filters['incident_id'])) {
            $query->where('incident_id', $filters['incident_id']);
        }

        return $query->limit((int) ($filters['limit'] ?? 100))
            ->get()
            ->map(fn (AutomationExecution $e): array => $this->summary($e))
            ->all();
    }

    public function find(Project $project, string $uuid): ?AutomationExecution
    {
        return AutomationExecution::query()
            ->where('project_id', $project->id)
            ->where('uuid', $uuid)
            ->with(['steps', 'approval'])
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(AutomationExecution $execution): array
    {
        return [
            'uuid' => $execution->uuid,
            'adapter_key' => $execution->adapter_key,
            'action_key' => $execution->action_key,
            'status' => $execution->status,
            'mode' => $execution->mode,
            'rolled_back' => (bool) $execution->rolled_back,
            'duration_ms' => $execution->duration_ms,
            'incident_id' => $execution->incident_id,
            'workflow_id' => $execution->workflow_id,
            'runbook_id' => $execution->runbook_id,
            'created_at' => optional($execution->created_at)->toIso8601String(),
            'finished_at' => optional($execution->finished_at)->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(AutomationExecution $execution): array
    {
        return array_merge($this->summary($execution), [
            'parameters' => $execution->parameters,
            'context' => $execution->context,
            'result' => $execution->result,
            'error' => $execution->error,
            'steps' => $execution->steps->map(fn ($s): array => [
                'step_index' => $s->step_index,
                'name' => $s->name,
                'status' => $s->status,
                'output' => $s->output,
                'started_at' => optional($s->started_at)->toIso8601String(),
                'finished_at' => optional($s->finished_at)->toIso8601String(),
            ])->all(),
            'approval' => $execution->approval ? [
                'uuid' => $execution->approval->uuid,
                'status' => $execution->approval->status,
                'reason' => $execution->approval->reason,
                'decided_at' => optional($execution->approval->decided_at)->toIso8601String(),
            ] : null,
        ]);
    }
}
