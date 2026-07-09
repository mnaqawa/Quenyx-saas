<?php

namespace App\Services\PlatformAgent;

use App\Constants\AgentLifecycleStatus;
use App\Constants\AgentPolicyStatus;
use App\Constants\AgentUpdateChannel;
use App\Constants\AgentUpdateStatus;
use App\Models\Agent;
use App\Models\AgentRelease;
use App\Models\AgentUpdateAssignment;
use App\Models\Project;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Enterprise agent self-update framework — policy-gated, never auto-updates without approval.
 */
class AgentUpdateService
{
    /**
     * @return array<string, mixed>|null Update instruction for heartbeat when policy allows.
     */
    public function heartbeatInstruction(Agent $agent): ?array
    {
        if (! Schema::hasTable('agent_releases')) {
            return null;
        }

        $assignment = $this->activeAssignment($agent);
        if ($assignment) {
            return $this->instructionFromAssignment($assignment);
        }

        $available = $this->resolveAvailableRelease($agent);
        if ($available === null) {
            return null;
        }

        if ($this->isCurrentVersion($agent, $available->version)) {
            return null;
        }

        if ($available->mandatory) {
            $assignment = $this->createAssignment($agent, $available, approved: false);

            return $this->instructionFromAssignment($assignment, optional: false);
        }

        return [
            'available' => true,
            'optional' => true,
            'current_version' => $agent->agent_version,
            'latest_version' => $available->version,
            'channel' => $agent->update_channel ?? AgentUpdateChannel::STABLE,
            'rollback_version' => $available->rollback_version,
            'requires_approval' => true,
        ];
    }

    public function recordProgress(Agent $agent, array $payload): void
    {
        if (! Schema::hasTable('agent_update_assignments')) {
            return;
        }

        $status = (string) ($payload['status'] ?? '');
        $progress = isset($payload['progress']) ? (int) $payload['progress'] : null;
        $result = isset($payload['result']) ? (string) $payload['result'] : null;
        $error = isset($payload['error']) ? (string) $payload['error'] : null;

        $updates = array_filter([
            'update_status' => $status !== '' ? $status : null,
            'update_progress' => $progress,
            'lifecycle_status' => in_array($status, AgentUpdateStatus::inProgress(), true)
                ? AgentLifecycleStatus::AGENT_UPDATING
                : null,
        ], fn ($v) => $v !== null);

        if ($updates !== []) {
            $agent->update($updates);
        }

        $assignment = $this->activeAssignment($agent);
        if (! $assignment) {
            return;
        }

        $assignment->update(array_filter([
            'status' => $status !== '' ? $status : $assignment->status,
            'progress' => $progress ?? $assignment->progress,
            'result' => $result,
            'error_message' => $error,
            'started_at' => $assignment->started_at ?? (in_array($status, AgentUpdateStatus::inProgress(), true) ? now() : null),
            'completed_at' => in_array($status, [AgentUpdateStatus::SUCCEEDED, AgentUpdateStatus::FAILED, AgentUpdateStatus::ROLLED_BACK], true)
                ? now()
                : null,
        ], fn ($v) => $v !== null));

        if (in_array($status, [AgentUpdateStatus::SUCCEEDED, AgentUpdateStatus::FAILED, AgentUpdateStatus::ROLLED_BACK], true)) {
            $this->recordHistory($agent, $assignment, $status, $result, $error);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function workspaceSummary(Project $project): array
    {
        if (! Schema::hasTable('agent_releases')) {
            return ['available' => false];
        }

        $agents = Agent::where('workspace_id', $project->id)->get();
        $channelCounts = [];
        $statusCounts = [];
        $needsUpgrade = 0;

        foreach ($agents as $agent) {
            $ch = $agent->update_channel ?? AgentUpdateChannel::STABLE;
            $channelCounts[$ch] = ($channelCounts[$ch] ?? 0) + 1;
            $st = $agent->update_status ?? 'current';
            $statusCounts[$st] = ($statusCounts[$st] ?? 0) + 1;

            if (in_array($agent->policy_status, [
                AgentPolicyStatus::UPGRADE_AVAILABLE,
                AgentPolicyStatus::UNSUPPORTED_VERSION,
            ], true)) {
                $needsUpgrade++;
            }
        }

        $latest = AgentRelease::query()
            ->where('is_latest', true)
            ->where('channel', AgentUpdateChannel::STABLE)
            ->orderByDesc('published_at')
            ->first();

        return [
            'latest_version' => $latest?->version,
            'agents_total' => $agents->count(),
            'agents_needing_upgrade' => $needsUpgrade,
            'channel_distribution' => $channelCounts,
            'update_status_distribution' => $statusCounts,
            'active_campaigns' => Schema::hasTable('agent_update_campaigns')
                ? \App\Models\AgentUpdateCampaign::where('workspace_id', $project->id)->where('status', 'active')->count()
                : 0,
        ];
    }

    public function approveAssignment(AgentUpdateAssignment $assignment): AgentUpdateAssignment
    {
        $assignment->update([
            'approved' => true,
            'status' => AgentUpdateStatus::APPROVED,
        ]);

        return $assignment->fresh();
    }

    private function activeAssignment(Agent $agent): ?AgentUpdateAssignment
    {
        return AgentUpdateAssignment::query()
            ->where('agent_id', $agent->id)
            ->whereNotIn('status', [AgentUpdateStatus::SUCCEEDED, AgentUpdateStatus::FAILED, AgentUpdateStatus::ROLLED_BACK, AgentUpdateStatus::SKIPPED])
            ->orderByDesc('created_at')
            ->first();
    }

    private function resolveAvailableRelease(Agent $agent): ?AgentRelease
    {
        $channel = $agent->update_channel ?? AgentUpdateChannel::STABLE;
        $platform = $this->normalizePlatform($agent->os);
        $arch = $agent->arch ?: 'amd64';

        return AgentRelease::query()
            ->where('channel', $channel)
            ->where('platform', $platform)
            ->where('arch', $arch)
            ->where('is_latest', true)
            ->orderByDesc('published_at')
            ->first()
            ?? AgentRelease::query()
                ->where('channel', AgentUpdateChannel::STABLE)
                ->where('is_latest', true)
                ->orderByDesc('published_at')
                ->first();
    }

    private function createAssignment(Agent $agent, AgentRelease $release, bool $approved): AgentUpdateAssignment
    {
        return AgentUpdateAssignment::create([
            'id' => (string) Str::uuid(),
            'agent_id' => $agent->id,
            'release_id' => $release->id,
            'workspace_id' => $agent->workspace_id,
            'status' => $approved ? AgentUpdateStatus::APPROVED : AgentUpdateStatus::PENDING,
            'approved' => $approved,
            'rollback_allowed' => true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function instructionFromAssignment(AgentUpdateAssignment $assignment, bool $optional = false): array
    {
        $release = $assignment->release;
        $approved = (bool) $assignment->approved;
        $inWindow = $this->inMaintenanceWindow($assignment);

        return [
            'assignment_uuid' => $assignment->id,
            'approved' => $approved,
            'optional' => $optional && ! ($release?->mandatory ?? false),
            'mandatory' => (bool) ($release?->mandatory ?? false),
            'current_version' => $assignment->agent?->agent_version,
            'target_version' => $release?->version,
            'rollback_version' => $release?->rollback_version,
            'channel' => $release?->channel,
            'download_url' => $approved && $inWindow ? $release?->download_url : null,
            'checksum_sha256' => $approved && $inWindow ? $release?->checksum_sha256 : null,
            'signature' => $approved && $inWindow ? $release?->signature : null,
            'maintenance_window' => [
                'start' => $assignment->maintenance_window_start?->toIso8601String(),
                'end' => $assignment->maintenance_window_end?->toIso8601String(),
                'active' => $inWindow,
            ],
            'may_proceed' => $approved && $inWindow,
        ];
    }

    private function inMaintenanceWindow(AgentUpdateAssignment $assignment): bool
    {
        if ($assignment->maintenance_window_start === null && $assignment->maintenance_window_end === null) {
            return true;
        }

        $now = now();

        if ($assignment->maintenance_window_start && $now->lt($assignment->maintenance_window_start)) {
            return false;
        }

        if ($assignment->maintenance_window_end && $now->gt($assignment->maintenance_window_end)) {
            return false;
        }

        return true;
    }

    private function isCurrentVersion(Agent $agent, string $targetVersion): bool
    {
        return version_compare((string) ($agent->agent_version ?? '0'), $targetVersion, '>=');
    }

    private function normalizePlatform(?string $os): string
    {
        $os = strtolower((string) $os);
        if (str_contains($os, 'win')) {
            return 'windows';
        }
        if (str_contains($os, 'darwin') || str_contains($os, 'mac')) {
            return 'darwin';
        }

        return 'linux';
    }

    private function recordHistory(Agent $agent, AgentUpdateAssignment $assignment, string $status, ?string $result, ?string $error): void
    {
        if (! Schema::hasTable('agent_update_history')) {
            return;
        }

        \App\Models\AgentUpdateHistory::create([
            'id' => (string) Str::uuid(),
            'agent_id' => $agent->id,
            'workspace_id' => $agent->workspace_id,
            'from_version' => $agent->getOriginal('agent_version') ?? $agent->agent_version,
            'to_version' => $assignment->release?->version,
            'channel' => $assignment->release?->channel,
            'status' => $status,
            'result' => $result,
            'rollback' => $status === AgentUpdateStatus::ROLLED_BACK,
            'detail' => $error,
            'recorded_at' => now(),
        ]);
    }
}
