<?php

namespace App\Console\Commands;

use App\Models\ObserveTargetHost;
use App\Services\PlatformAgent\AgentHostLinker;
use Illuminate\Console\Command;

class RepairAgentObserveHostsCommand extends Command
{
    protected $signature = 'observe:repair-agent-hosts {--workspace_id= : Limit to one workspace}';

    protected $description = 'Re-link Observe hosts to Platform Agents (by IP/hostname) and convert SSH/pull metrics to telemetry';

    public function handle(AgentHostLinker $linker): int
    {
        $workspaceId = $this->option('workspace_id');
        $query = ObserveTargetHost::query()->visibleInList();
        if ($workspaceId !== null && $workspaceId !== '') {
            $query->where('workspace_id', (int) $workspaceId);
        }

        $fixed = 0;
        $skipped = 0;
        foreach ($query->get() as $host) {
            $result = $linker->linkAndHeal($host);
            if (! $result['linked']) {
                $this->warn(sprintf(
                    '— %s (id=%d) address=%s public=%s — no matching agent in workspace %d',
                    $host->name,
                    $host->id,
                    $host->address ?? '-',
                    $host->public_ip ?? '-',
                    $host->workspace_id
                ));
                $skipped++;

                continue;
            }

            $this->info(sprintf(
                '✓ %s (id=%d) → agent %s (healed_services=%d)',
                $host->name,
                $host->id,
                $result['agent_id'],
                $result['healed_services']
            ));
            $fixed++;
        }

        $this->newLine();
        $this->info("Repaired {$fixed} host(s); {$skipped} unmatched.");
        $this->line('Next: php artisan observe:run-checks'.($workspaceId ? " --workspace_id={$workspaceId}" : ''));

        return $fixed > 0 || $skipped === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
