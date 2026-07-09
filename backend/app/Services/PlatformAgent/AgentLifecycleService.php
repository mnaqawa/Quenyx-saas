<?php

namespace App\Services\PlatformAgent;

use App\Constants\AgentConstants;
use App\Models\Agent;
use App\Models\ObserveTargetHost;
use App\Models\Project;
use App\Models\User;
use App\Services\Platform\EventBus\PlatformEventNames;
use App\Services\Platform\EventBus\PublishesPlatformEvents;
use App\Services\Platform\PlatformAuditLogger;
use Illuminate\Support\Facades\Hash;

class AgentLifecycleService
{
    use PublishesPlatformEvents;

    public function __construct(
        private HostLifecycleService $hostLifecycle,
        private PlatformAuditLogger $audit
    ) {
    }

    /**
     * Revoke agent: invalidate secret, mark inactive, sync linked hosts.
     */
    public function revoke(Project $project, Agent $agent, ?User $user, ?string $reason = null): Agent
    {
        $this->assertWorkspace($project, $agent);

        $agent->update([
            'status' => 'revoked',
            'revoked_at' => now(),
            'revoked_reason' => $reason ?? 'Agent revoked by administrator.',
            'agent_secret_hash' => Hash::make(Agent::generateId()),
        ]);

        $this->syncLinkedHosts($project, $agent, $user, $reason);

        $this->audit->log($user, $project, 'agent.revoked', [
            'agent_id' => $agent->id,
            'hostname' => $agent->hostname,
        ]);

        $this->publishPlatformEvent(PlatformEventNames::AGENT_REVOKED, $project, $user, [
            'agent_id' => $agent->id,
            'hostname' => $agent->hostname,
            'reason' => $reason,
        ]);

        return $agent->fresh();
    }

    /**
     * Delete agent (soft): revoke + soft delete. Linked hosts marked agent_removed.
     */
    public function delete(Project $project, Agent $agent, ?User $user, string $hostAction = 'agent_removed'): void
    {
        $this->assertWorkspace($project, $agent);

        $this->revoke($project, $agent, $user, 'Agent deleted.');

        $agent->update(['deleted_at' => now()]);

        $this->audit->log($user, $project, 'agent.deleted', [
            'agent_id' => $agent->id,
            'hostname' => $agent->hostname,
            'host_action' => $hostAction,
        ]);
    }

    private function syncLinkedHosts(Project $project, Agent $agent, ?User $user, ?string $reason): void
    {
        $hosts = ObserveTargetHost::query()
            ->where('workspace_id', $project->id)
            ->where('agent_id', $agent->id)
            ->get();

        foreach ($hosts as $host) {
            $this->hostLifecycle->markAgentRemoved(
                $project,
                $host,
                $user,
                $reason ?? 'Monitoring disabled because the platform agent was removed.'
            );
        }
    }

    private function assertWorkspace(Project $project, Agent $agent): void
    {
        if ((int) $agent->workspace_id !== (int) $project->id) {
            throw new \InvalidArgumentException('Agent not found in this workspace.');
        }
    }
}
