<?php

declare(strict_types=1);

namespace App\Services\Automation;

use App\Models\Automation\AutomationExecution;
use App\Models\Automation\AutomationLearningRecord;
use App\Models\Project;

/**
 * Sprint 23 — Automation Learning.
 *
 * The ONLY "learning" in the platform: every execution outcome is captured as an immutable, auditable
 * {@see AutomationLearningRecord}. Future AI recommendations cite AGGREGATED historical outcomes
 * (success/failure/rollback rates, typical duration) — there is NO model training, NO hidden state,
 * and everything is workspace-scoped and inspectable.
 */
class AutomationLearningService
{
    public function record(AutomationExecution $execution, ?string $recommendationKey = null, ?string $feedback = null): void
    {
        AutomationLearningRecord::create([
            'project_id' => $execution->project_id,
            'execution_id' => $execution->id,
            'recommendation_key' => $recommendationKey,
            'action_key' => $execution->action_key,
            'outcome' => $this->outcomeFor($execution),
            'duration_ms' => $execution->duration_ms,
            'operator_feedback' => $feedback,
            'metadata' => [
                'adapter_key' => $execution->adapter_key,
                'mode' => $execution->mode,
            ],
        ]);
    }

    public function feedback(AutomationExecution $execution, string $feedback): AutomationLearningRecord
    {
        return AutomationLearningRecord::create([
            'project_id' => $execution->project_id,
            'execution_id' => $execution->id,
            'recommendation_key' => null,
            'action_key' => $execution->action_key,
            'outcome' => $this->outcomeFor($execution),
            'duration_ms' => $execution->duration_ms,
            'operator_feedback' => $feedback,
            'metadata' => ['type' => 'operator_feedback'],
        ]);
    }

    /**
     * Aggregated, auditable outcome statistics per action — the historical evidence the AI cites.
     *
     * @return array<string, mixed>
     */
    public function stats(Project $project): array
    {
        $records = AutomationLearningRecord::query()
            ->where('project_id', $project->id)
            ->get(['action_key', 'outcome', 'duration_ms']);

        $byAction = [];
        foreach ($records as $record) {
            $key = (string) ($record->action_key ?? 'unknown');
            $byAction[$key] ??= ['action_key' => $key, 'total' => 0, 'succeeded' => 0, 'failed' => 0, 'rolled_back' => 0, 'dry_run' => 0, 'duration_sum' => 0, 'duration_n' => 0];
            $byAction[$key]['total']++;
            $outcome = (string) $record->outcome;
            if (isset($byAction[$key][$outcome])) {
                $byAction[$key][$outcome]++;
            }
            if ($record->duration_ms !== null) {
                $byAction[$key]['duration_sum'] += (int) $record->duration_ms;
                $byAction[$key]['duration_n']++;
            }
        }

        $actions = [];
        foreach ($byAction as $row) {
            $n = max(1, (int) $row['total']);
            $actions[] = [
                'action_key' => $row['action_key'],
                'total' => $row['total'],
                'succeeded' => $row['succeeded'],
                'failed' => $row['failed'],
                'rolled_back' => $row['rolled_back'],
                'dry_run' => $row['dry_run'],
                'success_rate' => round(($row['succeeded'] / $n) * 100, 1),
                'avg_duration_ms' => $row['duration_n'] > 0 ? (int) round($row['duration_sum'] / $row['duration_n']) : null,
            ];
        }

        return [
            'total_records' => $records->count(),
            'actions' => $actions,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    private function outcomeFor(AutomationExecution $execution): string
    {
        if ($execution->rolled_back) {
            return 'rolled_back';
        }

        return match ($execution->status) {
            'succeeded' => 'success',
            'failed' => 'failure',
            'rolled_back' => 'rolled_back',
            default => 'dry_run',
        };
    }
}
