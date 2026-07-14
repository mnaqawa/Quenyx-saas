<?php

namespace App\Console\Commands;

use App\Constants\HostLifecycleStatus;
use App\Models\Agent;
use App\Models\ObserveService;
use App\Models\ObserveTargetHost;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Services\DefaultMonitoringProfileService;
use App\Services\PlatformAgent\AgentHostLinker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Ops recovery: pin admin "Production Env" to a canonical workspace id (default 75),
 * remap agents/services, and recreate Observe hosts from enrolled agents.
 */
class RepairProductionWorkspaceCommand extends Command
{
    protected $signature = 'quenyx:repair-production-workspace
                            {email=admin@quenyx.test : Owner/member email}
                            {--canonical-id=75 : Desired Production Env project id}
                            {--dry-run : Show plan without writing}
                            {--force : Skip confirmation}';

    protected $description = 'Repair Production Env workspace id, remap agents, and recreate missing hosts from agents';

    public function handle(DefaultMonitoringProfileService $profiles, AgentHostLinker $linker): int
    {
        $email = (string) $this->argument('email');
        $canonicalId = (int) $this->option('canonical-id');
        $dryRun = (bool) $this->option('dry-run');

        $user = User::where('email', $email)->first();
        if (! $user) {
            $this->error("User not found: {$email}");

            return self::FAILURE;
        }

        $this->info("User: {$user->name} ({$user->email}) id={$user->id}");
        $this->line('--- Diagnostics ---');

        $projects = Project::query()->orderBy('id')->get(['id', 'name', 'owner_id', 'status', 'uuid']);
        foreach ($projects as $p) {
            $role = ProjectMembership::where('project_id', $p->id)->where('user_id', $user->id)->value('role');
            $hosts = ObserveTargetHost::withTrashed()->where('workspace_id', $p->id)->count();
            $agents = Agent::withTrashed()->where('workspace_id', $p->id)->count();
            $svcs = Schema::hasTable('observe_services')
                ? ObserveService::where('workspace_id', $p->id)->count()
                : 0;
            $marker = $p->name === 'Production Env' ? ' ★' : '';
            $this->line(sprintf(
                '  project %d "%s" owner=%s role=%s hosts=%d agents=%d observe_services=%d%s',
                $p->id,
                $p->name,
                $p->owner_id,
                $role ?? '-',
                $hosts,
                $agents,
                $svcs,
                $marker
            ));
        }

        $orphanAgents = Agent::withTrashed()
            ->whereNotIn('workspace_id', $projects->pluck('id')->all())
            ->count();
        if ($orphanAgents > 0) {
            $this->warn("Orphan agents (workspace missing): {$orphanAgents}");
        }

        $productionDupes = Project::where('name', 'Production Env')->orderBy('id')->get();
        $this->line('Production Env ids: '.$productionDupes->pluck('id')->implode(', ') ?: '(none)');

        if (! $this->option('force') && ! $dryRun && ! $this->confirm("Proceed to pin Production Env to id {$canonicalId} for {$email}?", true)) {
            $this->warn('Cancelled.');

            return self::SUCCESS;
        }

        return DB::transaction(function () use ($user, $canonicalId, $dryRun, $profiles, $linker, $productionDupes) {
            $canonical = Project::find($canonicalId);

            if (! $canonical) {
                $this->warn("Project {$canonicalId} missing — recreating as Production Env.");
                if (! $dryRun) {
                    // Insert with explicit id so FK/agent data can stay on 75.
                    DB::table('projects')->insert([
                        'id' => $canonicalId,
                        'uuid' => (string) Str::uuid(),
                        'owner_id' => $user->id,
                        'name' => 'Production Env',
                        'status' => 'active',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    // Keep autoincrement ahead of inserted id (MySQL).
                    try {
                        $max = (int) DB::table('projects')->max('id');
                        DB::statement('ALTER TABLE projects AUTO_INCREMENT = '.($max + 1));
                    } catch (\Throwable) {
                        // ignore on non-MySQL
                    }
                }
                $canonical = $dryRun ? null : Project::find($canonicalId);
            }

            if ($dryRun) {
                $this->info('[dry-run] Would ensure project name/owner/membership on '.$canonicalId);
            } else {
                $canonical->name = 'Production Env';
                $canonical->status = 'active';
                if ((int) $canonical->owner_id !== (int) $user->id) {
                    $canonical->owner_id = $user->id;
                }
                $canonical->save();

                ProjectMembership::updateOrCreate(
                    ['project_id' => $canonicalId, 'user_id' => $user->id],
                    ['role' => 'owner']
                );
            }

            // Rename other "Production Env" projects so UI selection is unambiguous.
            foreach ($productionDupes as $dupe) {
                if ((int) $dupe->id === $canonicalId) {
                    continue;
                }
                $newName = 'Production Env (legacy '.$dupe->id.')';
                $this->line("Rename project {$dupe->id} → \"{$newName}\"");
                if (! $dryRun) {
                    $dupe->name = $newName;
                    $dupe->save();
                }

                // Move agents from duplicate Production Env into canonical.
                $agentIds = Agent::withTrashed()->where('workspace_id', $dupe->id)->pluck('id');
                $this->line("  Remap agents: {$agentIds->count()} → workspace {$canonicalId}");
                if (! $dryRun && $agentIds->isNotEmpty()) {
                    Agent::withTrashed()->whereIn('id', $agentIds)->update(['workspace_id' => $canonicalId]);
                }

                // Move leftover observe hosts if any.
                $hostCount = ObserveTargetHost::withTrashed()->where('workspace_id', $dupe->id)->count();
                $this->line("  Remap observe hosts: {$hostCount} → workspace {$canonicalId}");
                if (! $dryRun && $hostCount > 0) {
                    ObserveTargetHost::withTrashed()->where('workspace_id', $dupe->id)->update(['workspace_id' => $canonicalId]);
                }

                // Remap observe_services rows + host_name prefix.
                if (Schema::hasTable('observe_services')) {
                    $svcCount = ObserveService::where('workspace_id', $dupe->id)->count();
                    $this->line("  Remap observe_services: {$svcCount} → workspace {$canonicalId}");
                    if (! $dryRun && $svcCount > 0) {
                        $oldPrefix = 'ws'.$dupe->id.'-';
                        $newPrefix = 'ws'.$canonicalId.'-';
                        $rows = ObserveService::where('workspace_id', $dupe->id)->get();
                        foreach ($rows as $row) {
                            $hostName = (string) $row->host_name;
                            if (str_starts_with($hostName, $oldPrefix)) {
                                $hostName = $newPrefix.substr($hostName, strlen($oldPrefix));
                            }
                            $row->workspace_id = $canonicalId;
                            $row->host_name = $hostName;
                            $row->save();
                        }
                    }
                }
            }

            // Recreate hosts from agents on canonical workspace.
            $agents = $dryRun
                ? Agent::query()->where(function ($q) use ($canonicalId, $productionDupes) {
                    $ids = $productionDupes->pluck('id')->push($canonicalId)->unique()->all();
                    $q->whereIn('workspace_id', $ids);
                })->get()
                : Agent::query()
                    ->where('workspace_id', $canonicalId)
                    ->where(function ($q) {
                        $q->whereNull('status')->orWhere('status', '!=', 'revoked');
                    })
                    ->orderByDesc('last_seen_at')
                    ->get();

            $this->line('Agents available for host recreate: '.$agents->count());
            $createdHosts = 0;
            foreach ($agents as $agent) {
                if ($dryRun) {
                    $this->line("  [dry-run] ensure host for agent {$agent->id} ({$agent->hostname})");

                    continue;
                }

                $existing = ObserveTargetHost::withTrashed()
                    ->where('workspace_id', $canonicalId)
                    ->where(function ($q) use ($agent) {
                        $q->where('agent_id', $agent->id);
                        if ($agent->hostname) {
                            $q->orWhere('name', $agent->hostname);
                        }
                    })
                    ->first();

                if ($existing) {
                    if ($existing->trashed()) {
                        $existing->restore();
                    }
                    $existing->forceFill([
                        'agent_id' => $agent->id,
                        'source' => 'agent',
                        'enabled' => true,
                        'lifecycle_status' => HostLifecycleStatus::ACTIVE,
                        'public_ip' => $existing->public_ip ?: $agent->public_ip,
                        'address' => $existing->address ?: $this->preferPrivateIp($agent),
                    ])->save();
                    $profiles->attachToHost($existing->fresh(), $canonicalId);
                    $linker->linkAndHeal($existing->fresh());
                    $this->info("  Restored/updated host {$existing->name} (id={$existing->id})");

                    continue;
                }

                $name = $this->uniqueHostName($canonicalId, $this->friendlyAgentName($agent));
                $host = ObserveTargetHost::create([
                    'workspace_id' => $canonicalId,
                    'name' => $name,
                    'address' => $this->preferPrivateIp($agent),
                    'public_ip' => $agent->public_ip,
                    'agent_id' => $agent->id,
                    'source' => 'agent',
                    'enabled' => true,
                    'lifecycle_status' => HostLifecycleStatus::ACTIVE,
                ]);
                $profiles->attachToHost($host, $canonicalId);
                $linker->linkAndHeal($host->fresh());
                $createdHosts++;
                $this->info("  Created host {$host->name} (id={$host->id}) from agent {$agent->id}");
            }

            // Ensure Platform loopback host exists (local plugins).
            if (! $dryRun) {
                $platform = ObserveTargetHost::withTrashed()
                    ->where('workspace_id', $canonicalId)
                    ->where(function ($q) {
                        $q->where('name', 'Platform')
                            ->orWhere('address', '127.0.0.1')
                            ->orWhere('address', 'localhost');
                    })
                    ->first();
                if (! $platform) {
                    $platform = ObserveTargetHost::create([
                        'workspace_id' => $canonicalId,
                        'name' => 'Platform',
                        'address' => '127.0.0.1',
                        'public_ip' => null,
                        'source' => 'manual',
                        'enabled' => true,
                        'lifecycle_status' => HostLifecycleStatus::ACTIVE,
                        'check_command' => 'check-host-alive',
                    ]);
                    $profiles->attachToHost($platform, $canonicalId);
                    $this->info("  Created Platform host (id={$platform->id})");
                } elseif ($platform->trashed()) {
                    $platform->restore();
                    $platform->forceFill([
                        'enabled' => true,
                        'lifecycle_status' => HostLifecycleStatus::ACTIVE,
                        'address' => '127.0.0.1',
                    ])->save();
                    $this->info('  Restored Platform host');
                }
            } else {
                $this->line('  [dry-run] ensure Platform 127.0.0.1 host');
            }

            $finalHosts = $dryRun ? 0 : ObserveTargetHost::where('workspace_id', $canonicalId)->count();
            $this->newLine();
            $this->info($dryRun
                ? '[dry-run] complete — no writes'
                : "Done. Production Env is project {$canonicalId}. Hosts now: {$finalHosts} (created from agents this run: {$createdHosts}).");
            $this->line('Open: /app/workspaces/'.$canonicalId.'/observe/targets');
            $this->line('Also clear browser localStorage key quenyx.selected_workspace_id or re-select Production Env.');
            $this->line('Then: php artisan observe:run-checks --workspace='.$canonicalId);

            return self::SUCCESS;
        });
    }

    private function preferPrivateIp(Agent $agent): string
    {
        $private = $agent->private_ips;
        if (is_array($private)) {
            foreach ($private as $ip) {
                if (is_string($ip) && trim($ip) !== '' && trim($ip) !== (string) $agent->public_ip) {
                    return trim($ip);
                }
            }
            foreach ($private as $ip) {
                if (is_string($ip) && trim($ip) !== '') {
                    return trim($ip);
                }
            }
        }

        return trim((string) ($agent->public_ip ?: $agent->hostname ?: 'unknown'));
    }

    private function friendlyAgentName(Agent $agent): string
    {
        $host = trim((string) $agent->hostname);
        if ($host === '') {
            return 'Agent-'.substr((string) $agent->id, 0, 8);
        }
        // Prefer short labels used in UI previously.
        if (str_contains(strtolower($host), 'ip-172') || str_contains(strtolower($host), 'ec2')) {
            return 'Web-Server';
        }

        return $host;
    }

    private function uniqueHostName(int $workspaceId, string $base): string
    {
        $name = $base;
        $i = 0;
        while (ObserveTargetHost::withTrashed()->where('workspace_id', $workspaceId)->where('name', $name)->exists()) {
            $i++;
            $name = $base.'-'.$i;
        }

        return $name;
    }
}
