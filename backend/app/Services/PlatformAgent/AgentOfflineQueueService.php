<?php

namespace App\Services\PlatformAgent;

use App\Models\Agent;
use App\Models\AgentOfflineEvent;
use App\Models\Project;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Offline queue ingestion with deduplication and replay tracking.
 */
class AgentOfflineQueueService
{
    /**
     * @param array<string, mixed> $stats
     */
    public function recordQueueStats(Agent $agent, array $stats): void
    {
        $agent->update(['queue_stats' => $this->normalizeStats($stats)]);
    }

    /**
     * @param array<int, array<string, mixed>> $events
     * @return array{accepted: int, duplicate: int, rejected: int}
     */
    public function ingestReplay(Agent $agent, array $events): array
    {
        if (! Schema::hasTable('agent_offline_events')) {
            return ['accepted' => 0, 'duplicate' => 0, 'rejected' => count($events)];
        }

        $accepted = 0;
        $duplicate = 0;
        $rejected = 0;

        foreach ($events as $event) {
            $type = (string) ($event['event_type'] ?? '');
            $dedupKey = (string) ($event['dedup_key'] ?? '');
            $payload = $event['payload'] ?? [];
            $eventAt = $event['event_at'] ?? now()->toIso8601String();

            if ($type === '' || $dedupKey === '' || ! is_array($payload)) {
                $rejected++;
                continue;
            }

            $exists = AgentOfflineEvent::where('agent_id', $agent->id)
                ->where('dedup_key', $dedupKey)
                ->exists();

            if ($exists) {
                $duplicate++;
                continue;
            }

            AgentOfflineEvent::create([
                'id' => (string) Str::uuid(),
                'agent_id' => $agent->id,
                'workspace_id' => $agent->workspace_id,
                'event_type' => $type,
                'dedup_key' => $dedupKey,
                'payload' => $payload,
                'event_at' => $eventAt,
                'ingested_at' => now(),
                'source' => 'replay',
            ]);
            $accepted++;
        }

        return compact('accepted', 'duplicate', 'rejected');
    }

    /**
     * @return array<string, mixed>
     */
    public function workspaceSummary(Project $project): array
    {
        if (! Schema::hasTable('agent_offline_events')) {
            return ['available' => false];
        }

        $agentIds = Agent::where('workspace_id', $project->id)->pluck('id');
        $queued = AgentOfflineEvent::whereIn('agent_id', $agentIds)->whereNull('ingested_at')->count();
        $ingested = AgentOfflineEvent::whereIn('agent_id', $agentIds)->whereNotNull('ingested_at')->count();

        $agents = Agent::where('workspace_id', $project->id)->get();
        $totalQueued = 0;
        $totalDropped = 0;
        foreach ($agents as $agent) {
            $stats = $agent->queue_stats ?? [];
            $totalQueued += (int) ($stats['queued_events'] ?? 0);
            $totalDropped += (int) ($stats['dropped_events'] ?? 0);
        }

        $oldest = AgentOfflineEvent::whereIn('agent_id', $agentIds)->min('event_at');
        $newest = AgentOfflineEvent::whereIn('agent_id', $agentIds)->max('event_at');

        return [
            'agents_reporting_queue' => $agents->filter(fn ($a) => ! empty($a->queue_stats))->count(),
            'total_queued_events' => $totalQueued,
            'total_dropped_events' => $totalDropped,
            'platform_pending_replay' => $queued,
            'platform_ingested' => $ingested,
            'oldest_event' => $oldest,
            'newest_event' => $newest,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function agentSummary(Agent $agent): array
    {
        $stats = $this->normalizeStats($agent->queue_stats ?? []);

        if (Schema::hasTable('agent_offline_events')) {
            $stats['platform_ingested'] = AgentOfflineEvent::where('agent_id', $agent->id)->count();
        }

        return $stats;
    }

    /**
     * @param array<string, mixed> $stats
     * @return array<string, mixed>
     */
    private function normalizeStats(array $stats): array
    {
        return [
            'queued_events' => (int) ($stats['queued_events'] ?? 0),
            'oldest_event' => $stats['oldest_event'] ?? null,
            'newest_event' => $stats['newest_event'] ?? null,
            'dropped_events' => (int) ($stats['dropped_events'] ?? 0),
            'replay_progress' => (int) ($stats['replay_progress'] ?? 0),
            'disk_usage_mb' => (float) ($stats['disk_usage_mb'] ?? 0),
        ];
    }
}
