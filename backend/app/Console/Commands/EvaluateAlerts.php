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

        if ($this->getOutput()->isVerbose()) {
            $evaluator->setVerbose(true);
        }

        $stats = $evaluator->evaluate($workspaceFilter);

        $derivedSkipped = max(0, $stats['evaluated'] - $stats['opened'] - $stats['resolved'] - $stats['updated']);

        $this->info(sprintf(
            'Evaluated: %d rules',
            $stats['evaluated']
        ));
        $this->info(sprintf('Opened: %d alerts', $stats['opened']));
        $this->info(sprintf('Resolved: %d alert%s', $stats['resolved'], $stats['resolved'] === 1 ? '' : 's'));
        if ($stats['updated'] > 0) {
            $this->info(sprintf('Updated: %d existing alert%s', $stats['updated'], $stats['updated'] === 1 ? '' : 's'));
        }
        $this->info(sprintf('Skipped: %d (explicit counter: %d)', $derivedSkipped, $stats['skipped']));

        if ($this->getOutput()->isVerbose()) {
            $this->newLine();
            $this->comment('Debug trace (use -vvv for full context):');

            foreach ($evaluator->getDebugEntries() as $entry) {
                $ruleId = $entry['rule_id'] ?? '—';
                $metric = $entry['metric'] ?? ($entry['query_metric'] ?? '—');
                $reason = $entry['reason'] ?? ($entry['event'] ?? '—');
                $value = $entry['metric_value'] ?? ($entry['value'] ?? null);
                $source = $entry['source_table'] ?? '—';

                $line = sprintf(
                    '  rule=%s metric=%s reason=%s value=%s source=%s',
                    $ruleId,
                    $metric,
                    $reason,
                    $value === null ? 'null' : (string) $value,
                    $source
                );
                $this->line($line);

                if ($this->getOutput()->isVeryVerbose()) {
                    $context = array_diff_key($entry, array_flip(['rule_id', 'workspace_id', 'metric', 'reason', 'event']));
                    if (! empty($context)) {
                        $this->line('    '.json_encode($context, JSON_UNESCAPED_SLASHES));
                    }
                }
            }
        }

        return self::SUCCESS;
    }
}
