<?php

namespace App\Console\Commands;

use App\Constants\HostLifecycleStatus;
use App\Models\Agent;
use App\Models\AgentMetric;
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
 *
 * Note: do NOT wrap MySQL ALTER TABLE in a Laravel transaction — DDL causes an
 * implicit commit and then "There is no active transaction" on commit.
 */
class RepairProductionWorkspaceCommand extends Command
{
    protected $signature = 'quenyx:repair-production-workspace
                            {email=admin@quenyx.test : Owner/member email}
                            {--canonical-id=75 : Desired Production Env project id}
                            {--seed-web-server : Create Web-Server inventory host when no agents exist}
                            {--web-private=172.31.27.23 : Private IP for seeded Web-Server}
                            {--web-public=54.163.235.254 : Public IP for seeded Web-Server}
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

        $allAgents = Agent::withTrashed()->orderByDesc('last_seen_at')->limit(20)->get(['id', 'workspace_id', 'hostname', 'public_ip', 'status', 'deleted_at', 'last_seen_at']);
        $this->line('Agents in DB (up to 20): '.$allAgents->count());
        foreach ($allAgents as $a) {
            $this->line(sprintf(
                '  agent %s ws=%s host=%s public=%s status=%s deleted=%s last_seen=%s',
                $a->id,
                $a->workspace_id,
                $a->hostname,
                $a->public_ip,
                $a->status,
                $a->deleted_at ? 'yes' : 'no',
                optional($a->last_seen_at)?->toDateTimeString() ?? '-'
            ));
        }

        if (Schema::hasTable('agent_metrics')) {
            $metricAgents = AgentMetric::query()
                ->select('agent_id')
                ->selectRaw('count(*) as c')
                ->selectRaw('max(collected_at) as last_at')
                ->groupBy('agent_id')
                ->orderByDesc('last_at')
                ->limit(10)
                ->get();
            $this->line('Recent agent_metrics agent_ids: '.$metricAgents->count());
            foreach ($metricAgents as $m) {
                $this->line(sprintf('  metrics agent=%s rows=%s last=%s', $m->agent_id, $m->c, $m->last_at));
            }
        }

        $orphanAgents = Agent::withTrashed()
            ->whereNotIn('workspace_id', $projects->pluck('id')->all() ?: [0])
            ->count();
        if ($orphanAgents > 0) {
            $this->warn("Orphan agents (workspace missing): {$orphanAgents}");
        }

        // Include already-renamed legacy Production Env rows.
        $productionDupes = Project::query()
            ->where(function ($q) {
                $q->where('name', 'Production Env')
                    ->orWhere('name', 'like', 'Production Env (legacy %');
            })
            ->orderBy('id')
            ->get();
        $this->line('Production Env / legacy ids: '.$productionDupes->pluck('id')->implode(', ') ?: '(none)');

        if (! $this->option('force') && ! $dryRun && ! $this->confirm("Proceed to pin Production Env to id {$canonicalId} for {$email}?", true)) {
            $this->warn('Cancelled.');

            return self::SUCCESS;
        }

        // Intentionally NOT in DB::transaction — MySQL DDL (AUTO_INCREMENT) commits implicitly.

        $canonical = Project::find($canonicalId);

        if (! $canonical) {
            $this->warn("Project {$canonicalId} missing — recreating as Production Env.");
            if (! $dryRun) {
                DB::table('projects')->insert([
                    'id' => $canonicalId,
                    'uuid' => (string) Str::uuid(),
                    'owner_id' => $user->id,
                    'name' => 'Production Env',
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
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
            if (! $canonical) {
                $this->error("Failed to load/create project {$canonicalId}");

                return self::FAILURE;
            }
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

        foreach ($productionDupes as $dupe) {
            if ((int) $dupe->id === $canonicalId) {
                continue;
            }
            if ($dupe->name === 'Production Env') {
                $newName = 'Production Env (legacy '.$dupe->id.')';
                $this->line("Rename project {$dupe->id} → \"{$newName}\"");
                if (! $dryRun) {
                    $dupe->name = $newName;
                    $dupe->save();
                }
            }

            $agentIds = Agent::withTrashed()->where('workspace_id', $dupe->id)->pluck('id');
            $this->line("  Remap agents from {$dupe->id}: {$agentIds->count()} → workspace {$canonicalId}");
            if (! $dryRun && $agentIds->isNotEmpty()) {
                Agent::withTrashed()->whereIn('id', $agentIds)->update(['workspace_id' => $canonicalId]);
            }

            $hostCount = ObserveTargetHost::withTrashed()->where('workspace_id', $dupe->id)->count();
            $this->line("  Remap observe hosts from {$dupe->id}: {$hostCount} → workspace {$canonicalId}");
            if (! $dryRun && $hostCount > 0) {
                ObserveTargetHost::withTrashed()->where('workspace_id', $dupe->id)->update(['workspace_id' => $canonicalId]);
            }

            if (Schema::hasTable('observe_services')) {
                $svcCount = ObserveService::where('workspace_id', $dupe->id)->count();
                $this->line("  Remap observe_services from {$dupe->id}: {$svcCount} → workspace {$canonicalId}");
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

        // Also pick up any orphan agents (workspace FK broken / deleted project).
        if (! $dryRun) {
            $orphans = Agent::withTrashed()
                ->whereNotIn('workspace_id', Project::query()->pluck('id')->all() ?: [0])
                ->get();
            foreach ($orphans as $orphan) {
                $this->line("  Remap orphan agent {$orphan->id} → workspace {$canonicalId}");
                $orphan->workspace_id = $canonicalId;
                $orphan->save();
            }
        }

        $agents = Agent::withTrashed()
            ->where('workspace_id', $canonicalId)
            ->orderByDesc('last_seen_at')
            ->get()
            ->filter(fn (Agent $a) => ($a->status ?? '') !== 'revoked');

        $this->line('Agents available for host recreate: '.$agents->count());
        $createdHosts = 0;
        foreach ($agents as $agent) {
            if ($dryRun) {
                $this->line("  [dry-run] ensure host for agent {$agent->id} ({$agent->hostname})");

                continue;
            }

            if ($agent->trashed()) {
                $agent->restore();
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
            } else {
                $this->line("  Platform host already present (id={$platform->id})");
            }
        } else {
            $this->line('  [dry-run] ensure Platform 127.0.0.1 host');
        }

        // Optional inventory seed when agents were cascade-deleted with the old project.
        if ($this->option('seed-web-server') || $agents->isEmpty()) {
            $priv = trim((string) $this->option('web-private'));
            $pub = trim((string) $this->option('web-public'));
            if ($priv !== '' || $pub !== '') {
                if ($dryRun) {
                    $this->line("  [dry-run] seed Web-Server priv={$priv} pub={$pub}");
                } else {
                    $web = ObserveTargetHost::withTrashed()
                        ->where('workspace_id', $canonicalId)
                        ->where(function ($q) use ($priv, $pub) {
                            $q->where('name', 'Web-Server');
                            if ($priv !== '') {
                                $q->orWhere('address', $priv);
                            }
                            if ($pub !== '') {
                                $q->orWhere('public_ip', $pub);
                            }
                        })
                        ->first();
                    if (! $web) {
                        $web = ObserveTargetHost::create([
                            'workspace_id' => $canonicalId,
                            'name' => 'Web-Server',
                            'address' => $priv !== '' ? $priv : $pub,
                            'public_ip' => $pub !== '' ? $pub : null,
                            'source' => 'manual',
                            'enabled' => true,
                            'lifecycle_status' => HostLifecycleStatus::ACTIVE,
                            'check_command' => 'check-host-alive',
                        ]);
                        $profiles->attachToHost($web, $canonicalId);
                        $createdHosts++;
                        $this->info("  Seeded Web-Server host (id={$web->id}) — re-enroll Platform Agent to link telemetry");
                    } elseif ($web->trashed()) {
                        $web->restore();
                        $web->forceFill([
                            'enabled' => true,
                            'lifecycle_status' => HostLifecycleStatus::ACTIVE,
                            'address' => $priv !== '' ? $priv : $web->address,
                            'public_ip' => $pub !== '' ? $pub : $web->public_ip,
                        ])->save();
                        $this->info('  Restored Web-Server host');
                    } else {
                        $this->line("  Web-Server host already present (id={$web->id})");
                    }
                }
            }
        }

        $finalHosts = $dryRun ? 0 : ObserveTargetHost::where('workspace_id', $canonicalId)->count();
        $this->newLine();
        $this->info($dryRun
            ? '[dry-run] complete — no writes'
            : "Done. Production Env is project {$canonicalId}. Hosts now: {$finalHosts} (created this run: {$createdHosts}).");
        $this->line('Open: /app/workspaces/'.$canonicalId.'/observe/targets');
        $this->line('Clear browser localStorage key quenyx.selected_workspace_id, then select Production Env.');
        $this->line('Then: php artisan observe:run-checks --workspace_id='.$canonicalId);
        if ($agents->isEmpty()) {
            $this->warn('No agents found — Web-Server telemetry needs agent re-enrollment into workspace '.$canonicalId.'.');
        }

        return self::SUCCESS;
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
