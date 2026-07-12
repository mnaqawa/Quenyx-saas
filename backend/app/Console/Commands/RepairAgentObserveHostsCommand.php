<?php

namespace App\Console\Commands;

use App\Models\Agent;
use App\Models\ObserveTargetHost;
use App\Services\DefaultMonitoringProfileService;
use Illuminate\Console\Command;

class RepairAgentObserveHostsCommand extends Command
{
    protected $signature = 'observe:repair-agent-hosts {--workspace_id= : Limit to one workspace}';

    protected $description = 'Re-link Observe hosts to Platform Agents and convert SSH metric checks to telemetry';

    public function handle(DefaultMonitoringProfileService $profiles): int
    {
        $workspaceId = $this->option('workspace_id');
        $query = ObserveTargetHost::query()->visibleInList();
        if ($workspaceId !== null && $workspaceId !== '') {
            $query->where('workspace_id', (int) $workspaceId);
        }

        $fixed = 0;
        foreach ($query->get() as $host) {
            $agent = null;
            if ($host->agent_id) {
                $agent = Agent::find($host->agent_id);
            }
            if (! $agent) {
                $agent = Agent::query()
                    ->where('workspace_id', $host->workspace_id)
                    ->where(function ($q) use ($host) {
                        $q->where('hostname', $host->name)
                            ->orWhere('hostname', 'like', $host->name.'%');
                    })
                    ->where(function ($q) {
                        $q->whereNull('status')->orWhere('status', '!=', 'revoked');
                    })
                    ->orderByDesc('last_seen_at')
                    ->first();
            }

            if (! $agent) {
                continue;
            }

            $updates = [
                'agent_id' => $agent->id,
                'source' => 'agent',
            ];
            if (! empty($agent->public_ip) && empty($host->public_ip)) {
                $updates['public_ip'] = $agent->public_ip;
            }
            if (is_array($agent->private_ips) && $agent->private_ips !== [] && empty($host->address)) {
                $updates['address'] = (string) $agent->private_ips[0];
            }

            $host->forceFill($updates)->save();
            $result = $profiles->attachToHost($host->fresh(), (int) $host->workspace_id);
            $this->line(sprintf(
                '✓ %s (id=%d) → agent %s (attached=%d healed=%d)',
                $host->name,
                $host->id,
                $agent->id,
                $result['attached'] ?? 0,
                $result['skipped'] ?? 0
            ));
            $fixed++;
        }

        $this->info("Repaired {$fixed} agent-linked host(s).");
        $this->line('Next: php artisan observe:run-checks'.($workspaceId ? " --workspace_id={$workspaceId}" : ''));

        return Command::SUCCESS;
    }
}
