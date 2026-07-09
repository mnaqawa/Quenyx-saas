<?php

namespace App\Services\PlatformAgent;

use App\Models\Agent;
use App\Models\AgentGateway;
use App\Support\AgentGateway as GatewayUrl;
use Illuminate\Support\Facades\DB;

/**
 * Multi-gateway resolution and failover readiness.
 */
class AgentGatewayService
{
    public function resolvePreferredGateway(?int $workspaceId = null): ?AgentGateway
    {
        $query = AgentGateway::query()->where('health_status', '!=', 'unhealthy');

        if ($workspaceId) {
            $workspaceGateway = (clone $query)->where('workspace_id', $workspaceId)->orderByDesc('is_primary')->first();
            if ($workspaceGateway) {
                return $workspaceGateway;
            }
        }

        return (clone $query)->where('is_primary', true)->first()
            ?? (clone $query)->orderBy('connected_agents')->first()
            ?? $this->ensureDefaultExists();
    }

    /**
     * @return array<string, mixed>|null Failover target when current gateway is unavailable.
     */
    public function failoverTarget(?string $currentGatewayId, ?int $workspaceId = null): ?array
    {
        $alternate = AgentGateway::query()
            ->where('id', '!=', $currentGatewayId)
            ->where('health_status', 'healthy')
            ->when($workspaceId, fn ($q) => $q->where(function ($inner) use ($workspaceId) {
                $inner->whereNull('workspace_id')->orWhere('workspace_id', $workspaceId);
            }))
            ->orderByDesc('is_primary')
            ->orderBy('name')
            ->orderBy('id')
            ->first();

        if (! $alternate) {
            return null;
        }

        return [
            'gateway_uuid' => $alternate->id,
            'endpoint_url' => $alternate->endpoint_url,
            'region' => $alternate->region,
        ];
    }

    public function refreshConnectedCounts(): void
    {
        $counts = Agent::query()
            ->whereNotNull('preferred_gateway_id')
            ->select('preferred_gateway_id', DB::raw('count(*) as total'))
            ->groupBy('preferred_gateway_id')
            ->pluck('total', 'preferred_gateway_id');

        AgentGateway::query()->each(function (AgentGateway $gw) use ($counts) {
            $gw->update(['connected_agents' => (int) ($counts[$gw->id] ?? 0)]);
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForWorkspace(?int $workspaceId = null): array
    {
        return AgentGateway::query()
            ->when($workspaceId, fn ($q) => $q->where(function ($inner) use ($workspaceId) {
                $inner->whereNull('workspace_id')->orWhere('workspace_id', $workspaceId);
            }))
            ->orderByDesc('is_primary')
            ->get()
            ->map(fn (AgentGateway $gw) => [
                'uuid' => $gw->id,
                'name' => $gw->name,
                'region' => $gw->region,
                'version' => $gw->version,
                'health_status' => $gw->health_status,
                'capacity' => $gw->capacity,
                'connected_agents' => $gw->connected_agents,
                'endpoint_url' => $gw->endpoint_url,
                'is_primary' => $gw->is_primary,
                'last_heartbeat' => $gw->last_heartbeat_at?->toIso8601String(),
            ])
            ->all();
    }

    public function ensureDefaultExists(): AgentGateway
    {
        $existing = AgentGateway::where('is_primary', true)->first();
        if ($existing) {
            return $existing;
        }

        return AgentGateway::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'Default Agent Gateway',
            'region' => config('agent.gateway_region', 'default'),
            'endpoint_url' => GatewayUrl::url(),
            'version' => config('agent.gateway_version', '1.0.0'),
            'health_status' => 'healthy',
            'capacity' => (int) config('agent.gateway_capacity', 5000),
            'is_primary' => true,
            'last_heartbeat_at' => now(),
        ]);
    }
}
