<?php

namespace App\Console\Commands;

use App\Services\AlertEvaluationService;
use Illuminate\Console\Command;

class EvaluateAlerts extends Command
{
    protected $signature = 'observe:evaluate-alerts {--workspace_id= : Evaluate only for this workspace}';

    protected $description = 'Evaluate enabled alert rules against real monitoring data';

    public function handle(AlertEvaluationService $evaluator): int
    {
        $workspaceId = $this->option('workspace_id');
        $workspaceFilter = $workspaceId !== null && $workspaceId !== '' ? (int) $workspaceId : null;

        $stats = $evaluator->evaluate($workspaceFilter);

        $skipped = max(0, $stats['evaluated'] - $stats['opened'] - $stats['resolved'] - $stats['updated']);

        $this->info(sprintf(
            'Evaluated: %d rules',
            $stats['evaluated']
        ));
        $this->info(sprintf('Opened: %d alerts', $stats['opened']));
        $this->info(sprintf('Resolved: %d alert%s', $stats['resolved'], $stats['resolved'] === 1 ? '' : 's'));
        if ($stats['updated'] > 0) {
            $this->info(sprintf('Updated: %d existing alert%s', $stats['updated'], $stats['updated'] === 1 ? '' : 's'));
        }
        $this->info(sprintf('Skipped: %d', $skipped));

        return self::SUCCESS;
    }
}
