<?php

declare(strict_types=1);

namespace App\Services\Asset\Intelligence;

use App\Models\Agent;
use App\Models\AgentInventory;
use App\Models\ObserveService;
use App\Models\ObserveTargetHost;
use App\Models\PlatformAsset;
use App\Constants\HostLifecycleStatus;
use App\Models\Project;
use App\Services\CapacityPlanningService;
use App\Support\Asset\AssetEntityId;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Sprint 22 — QynAsset Intelligence evidence collector.
 *
 * The SINGLE source of asset EVIDENCE. Every value is read directly from REAL collected data for the
 * workspace — nothing is fabricated. QynAsset has no asset table, so an "asset" is a discovered host
 * (`observe_targets_hosts`) enriched by its linked enrolled agent (`agents`) and that agent's latest
 * inventory push (`agent_inventories`). Hardware/capacity reuses {@see CapacityPlanningService} (no
 * duplicated math).
 *
 * Honesty rule: capabilities with NO collected data source in this product (software licenses,
 * warranty / end-of-life / end-of-support dates) are reported as `available => false` with an explicit
 * reason — they are NEVER fabricated.
 */
class AssetEvidenceCollector
{
    /** An agent is considered stale if not seen within this many minutes. */
    private const STALE_MINUTES = 30;

    /** An asset is considered inactive if its agent has not reported within this many hours. */
    private const INACTIVE_HOURS = 24;

    public function __construct(
        private readonly CapacityPlanningService $capacity,
    ) {}

    public function hostPrefix(Project $project): string
    {
        return 'ws'.$project->id.'-';
    }

    /**
     * Full asset inventory for the workspace (discovered hosts + agent + latest inventory).
     *
     * @return list<array<string, mixed>>
     */
    public function inventory(Project $project): array
    {
        $hosts = ObserveTargetHost::query()
            ->where('workspace_id', $project->id)
            ->orderBy('name')
            ->get();

        $agents = $this->agentsById($project);
        $inventories = $this->latestInventoriesByAgent($agents->keys()->all());

        $hostAssets = $hosts->map(fn (ObserveTargetHost $host): array => $this->describeAsset($project, $host, $agents, $inventories));

        // Inventory-only assets (no monitoring target) — printers, switches, licenses, etc.
        $inventoryOnly = PlatformAsset::query()
            ->where('workspace_id', $project->id)
            ->whereNull('monitoring_target_id')
            ->orderBy('name')
            ->get()
            ->map(fn (PlatformAsset $asset): array => $this->describePlatformAsset($project, $asset, $agents));

        return array_values(array_merge($hostAssets->all(), $inventoryOnly->all()));
    }

    /**
     * Compact summary for a single asset (host).
     *
     * @return array<string, mixed>
     */
    public function assetSummary(Project $project, ObserveTargetHost $host): array
    {
        return $this->describeAsset($project, $host, $this->agentsById($project), $this->latestInventoriesByAgent($this->agentsById($project)->keys()->all()));
    }

    /**
     * Workspace-wide asset inventory rollup — counts only, all real.
     *
     * @return array<string, mixed>
     */
    public function inventorySummary(Project $project): array
    {
        $assets = $this->inventory($project);

        $byOs = [];
        $bySource = [];
        $confidence = ['high' => 0, 'medium' => 0, 'low' => 0];
        $withAgent = 0;
        $online = 0;
        $inactive = 0;
        $enabled = 0;

        foreach ($assets as $asset) {
            $os = (string) ($asset['os'] ?? 'unknown');
            $byOs[$os] = ($byOs[$os] ?? 0) + 1;
            $source = (string) ($asset['source'] ?? 'unknown');
            $bySource[$source] = ($bySource[$source] ?? 0) + 1;
            $confidence[$asset['discovery_confidence']] = ($confidence[$asset['discovery_confidence']] ?? 0) + 1;
            if ($asset['has_agent']) {
                $withAgent++;
            }
            if (($asset['agent']['status'] ?? null) === 'online' && ! $asset['stale']) {
                $online++;
            }
            if ($asset['inactive']) {
                $inactive++;
            }
            if ($asset['enabled']) {
                $enabled++;
            }
        }

        return [
            'total' => count($assets),
            'enabled' => $enabled,
            'with_agent' => $withAgent,
            'without_agent' => count($assets) - $withAgent,
            'online' => $online,
            'inactive' => $inactive,
            'by_os' => $byOs,
            'by_source' => $bySource,
            'discovery_confidence' => $confidence,
        ];
    }

    /**
     * Discovery Intelligence — new / changed / inactive / unknown / duplicate assets, all derived
     * from real timestamps and identity. Never invents inventory.
     *
     * @return array<string, mixed>
     */
    public function discovery(Project $project, int $newWithinDays = 7, int $changedWithinHours = 24): array
    {
        $assets = collect($this->inventory($project));

        $new = $assets->filter(function (array $a) use ($newWithinDays): bool {
            return $a['created_at'] !== null && Carbon::parse($a['created_at'])->gte(now()->subDays($newWithinDays));
        })->values()->all();

        $changed = $assets->filter(function (array $a) use ($changedWithinHours): bool {
            return $a['updated_at'] !== null
                && $a['created_at'] !== null
                && $a['updated_at'] !== $a['created_at']
                && Carbon::parse($a['updated_at'])->gte(now()->subHours($changedWithinHours));
        })->values()->all();

        $inactive = $assets->filter(fn (array $a): bool => $a['inactive'])->values()->all();

        // "Unknown" = an asset we cannot confidently classify: claims agent source but has no agent
        // record, or has neither an agent nor any monitored service.
        $unknown = $assets->filter(function (array $a): bool {
            $claimsAgentButNone = $a['source'] === 'agent' && ! $a['has_agent'];
            $noSignals = ! $a['has_agent'] && (int) $a['service_count'] === 0;

            return $claimsAgentButNone || $noSignals;
        })->values()->all();

        return [
            'new_assets' => $new,
            'new_asset_count' => count($new),
            'changed_assets' => $changed,
            'changed_asset_count' => count($changed),
            'inactive_assets' => $inactive,
            'inactive_asset_count' => count($inactive),
            'unknown_assets' => $unknown,
            'unknown_asset_count' => count($unknown),
            'duplicate_assets' => $this->duplicates($assets),
            'window' => ['new_within_days' => $newWithinDays, 'changed_within_hours' => $changedWithinHours],
        ];
    }

    /**
     * Full evidence envelope for a single asset.
     *
     * @return array<string, mixed>
     */
    public function assetEvidence(Project $project, ObserveTargetHost $host): array
    {
        $asset = $this->assetSummary($project, $host);
        $prefixedHost = $this->hostPrefix($project).$host->name;

        $services = ObserveService::query()
            ->where('workspace_id', $project->id)
            ->where('engine_key', 'native')
            ->where('host_name', $prefixedHost)
            ->get(['service_name', 'state', 'last_state_change_at'])
            ->map(fn (ObserveService $s): array => [
                'name' => (string) $s->service_name,
                'state' => (string) $s->state,
                'since' => optional($s->last_state_change_at)->toIso8601String(),
            ])->all();

        return [
            'asset' => $asset,
            'services' => $services,
            'hardware' => $this->hardware($project, $host),
            'lifecycle' => $this->lifecycle($project, $host),
        ];
    }

    /**
     * Workspace-wide capacity rollup (host-independent) reused from Capacity Planning.
     *
     * @return array<string, mixed>
     */
    public function capacityRollup(Project $project): array
    {
        $capacity = $this->capacity->build($project->id, '30d');

        return [
            'health' => $capacity['health'] ?? null,
            'runway' => $capacity['runway'] ?? null,
        ];
    }

    /**
     * Hardware Intelligence — real hardware facts (cpu_cores from inventory) + utilization/growth
     * reused from Capacity Planning. No fabricated specs.
     *
     * @return array<string, mixed>
     */
    public function hardware(Project $project, ObserveTargetHost $host): array
    {
        $inventoryPayload = $this->latestInventoryPayloadForHost($project, $host);

        return [
            'collected' => [
                'cpu_cores' => $inventoryPayload['cpu_cores'] ?? null,
                'os' => $inventoryPayload['os'] ?? ($host->agent?->os),
                'arch' => $inventoryPayload['arch'] ?? ($host->agent?->arch),
            ],
            'capacity' => $this->capacityRollup($project),
            'note' => $inventoryPayload === []
                ? 'No agent inventory has been collected for this asset; hardware facts are limited to monitoring data.'
                : 'Hardware facts from the agent inventory push; utilization/growth reused from Capacity Planning.',
        ];
    }

    /**
     * Lifecycle Intelligence — only the lifecycle facts that are actually collected. Warranty,
     * end-of-life and end-of-support dates have NO data source in this product and are reported as
     * not collected (never fabricated).
     *
     * @return array<string, mixed>
     */
    public function lifecycle(Project $project, ?ObserveTargetHost $host = null): array
    {
        $agentVersion = null;
        $os = null;
        $enrolledAt = null;
        $lastSeenAt = null;

        if ($host !== null) {
            $agent = $host->agent;
            $agentVersion = $agent?->agent_version;
            $os = $agent?->os;
            $enrolledAt = optional($agent?->enrolled_at)->toIso8601String();
            $lastSeenAt = optional($agent?->last_seen_at)->toIso8601String();
        }

        return [
            'collected' => [
                'agent_version' => $agentVersion,
                'os' => $os,
                'enrolled_at' => $enrolledAt,
                'last_seen_at' => $lastSeenAt,
            ],
            'warranty' => ['available' => false, 'reason' => 'No warranty data source is configured for this workspace.'],
            'end_of_life' => ['available' => false, 'reason' => 'No lifecycle/end-of-life data source is configured for this workspace.'],
            'end_of_support' => ['available' => false, 'reason' => 'No end-of-support data source is configured for this workspace.'],
        ];
    }

    /**
     * License Intelligence — there is no license data source in this product, so this honestly
     * reports "not collected" rather than fabricating utilization or compliance risk.
     *
     * @return array<string, mixed>
     */
    public function licenses(Project $project): array
    {
        return [
            'available' => false,
            'reason' => 'No software license data source is configured for this workspace. License intelligence '
                .'requires an inventory/license integration (e.g. GLPI/FusionInventory) that is not present.',
            'missing_licenses' => [],
            'unused_licenses' => [],
            'utilization' => null,
        ];
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Inventory-only platform asset (not a QynSight monitoring target).
     *
     * @param  Collection<int, Agent>  $agents
     * @return array<string, mixed>
     */
    private function describePlatformAsset(Project $project, PlatformAsset $asset, Collection $agents): array
    {
        $agent = $asset->agent_id !== null ? $agents->get($asset->agent_id) : null;
        $lifecycle = (string) ($asset->lifecycle_status ?? HostLifecycleStatus::ACTIVE);

        return [
            'uuid' => $asset->id,
            'name' => (string) $asset->name,
            'address' => null,
            'public_ip' => null,
            'source' => 'platform_asset',
            'enabled' => true,
            'lifecycle_status' => $lifecycle,
            'lifecycle_reason' => null,
            'tags' => [],
            'os' => $agent?->os,
            'arch' => $agent?->arch,
            'has_agent' => $agent !== null,
            'agent' => $agent !== null ? [
                'status' => (string) $agent->status,
                'lifecycle_status' => $agent->lifecycle_status ?? $agent->status,
            ] : null,
            'asset_type' => (string) $asset->asset_type,
            'is_monitoring_target' => false,
            'monitoring_target_uuid' => null,
            'discovery_confidence' => $agent !== null ? 'high' : 'medium',
            'stale' => false,
            'inactive' => in_array($lifecycle, HostLifecycleStatus::monitoringBlocked(), true),
            'service_count' => 0,
            'created_at' => optional($asset->created_at)->toIso8601String(),
            'updated_at' => optional($asset->updated_at)->toIso8601String(),
        ];
    }

    /**
     * @param  Collection<int, Agent>  $agents  keyed by agent id
     * @param  array<string, array<string, mixed>>  $inventories  latest payload keyed by agent id
     * @return array<string, mixed>
     */
    private function describeAsset(Project $project, ObserveTargetHost $host, Collection $agents, array $inventories): array
    {
        $agent = $host->agent_id !== null ? $agents->get($host->agent_id) : null;
        $payload = $agent !== null ? ($inventories[$agent->id] ?? []) : [];

        $lastSeen = $agent?->last_seen_at;
        $stale = $agent !== null && $lastSeen !== null && Carbon::parse($lastSeen)->lt(now()->subMinutes(self::STALE_MINUTES));
        $lifecycle = (string) ($host->lifecycle_status ?? HostLifecycleStatus::ACTIVE);
        $inactive = in_array($lifecycle, array_merge(HostLifecycleStatus::monitoringBlocked(), [HostLifecycleStatus::ARCHIVED]), true)
            || ! (bool) $host->enabled
            || ($agent !== null && ($agent->status === 'revoked' || $agent->status !== 'online' || ($lastSeen !== null && Carbon::parse($lastSeen)->lt(now()->subHours(self::INACTIVE_HOURS)))));

        $serviceCount = ObserveService::query()
            ->where('workspace_id', $project->id)
            ->where('engine_key', 'native')
            ->where('host_name', $this->hostPrefix($project).$host->name)
            ->count();

        return [
            'uuid' => AssetEntityId::for(AssetEntityId::TYPE_ASSET, $project->id, (int) $host->id),
            'name' => (string) $host->name,
            'address' => (string) $host->address,
            'public_ip' => $host->public_ip,
            'source' => (string) $host->source,
            'enabled' => (bool) $host->enabled,
            'lifecycle_status' => $lifecycle,
            'lifecycle_reason' => $host->lifecycle_reason,
            'tags' => is_array($host->tags) ? $host->tags : [],
            'os' => $payload['os'] ?? ($agent?->os),
            'arch' => $payload['arch'] ?? ($agent?->arch),
            'has_agent' => $agent !== null,
            'agent' => $agent !== null ? [
                'status' => (string) $agent->status,
                'agent_version' => $agent->agent_version,
                'last_seen_at' => optional($agent->last_seen_at)->toIso8601String(),
                'enrolled_at' => optional($agent->enrolled_at)->toIso8601String(),
            ] : null,
            'service_count' => $serviceCount,
            'stale' => $stale,
            'inactive' => $inactive,
            'discovery_confidence' => $this->confidence($agent !== null, $payload !== [], $serviceCount),
            'created_at' => optional($host->created_at)->toIso8601String(),
            'updated_at' => optional($host->updated_at)->toIso8601String(),
        ];
    }

    private function confidence(bool $hasAgent, bool $hasInventory, int $serviceCount): string
    {
        if ($hasAgent && $hasInventory) {
            return 'high';
        }
        if ($hasAgent || $serviceCount > 0) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $assets
     * @return array<string, mixed>
     */
    private function duplicates(Collection $assets): array
    {
        $byAddress = $assets
            ->filter(fn (array $a): bool => (string) $a['address'] !== '')
            ->groupBy(fn (array $a): string => (string) $a['address'])
            ->filter(fn (Collection $group): bool => $group->count() > 1)
            ->map(fn (Collection $group, string $address): array => [
                'key' => $address,
                'by' => 'address',
                'assets' => $group->map(fn (array $a): array => ['uuid' => $a['uuid'], 'name' => $a['name']])->values()->all(),
            ])
            ->values()
            ->all();

        $byName = $assets
            ->groupBy(fn (array $a): string => strtolower((string) $a['name']))
            ->filter(fn (Collection $group): bool => $group->count() > 1)
            ->map(fn (Collection $group, string $name): array => [
                'key' => $name,
                'by' => 'name',
                'assets' => $group->map(fn (array $a): array => ['uuid' => $a['uuid'], 'name' => $a['name']])->values()->all(),
            ])
            ->values()
            ->all();

        return ['groups' => array_merge($byAddress, $byName), 'count' => count($byAddress) + count($byName)];
    }

    /**
     * @return Collection<int, Agent>
     */
    private function agentsById(Project $project): Collection
    {
        return Agent::query()
            ->where('workspace_id', $project->id)
            ->get()
            ->keyBy('id');
    }

    /**
     * Latest inventory payload per agent id (single most recent row each).
     *
     * @param  list<string>  $agentIds
     * @return array<string, array<string, mixed>>
     */
    private function latestInventoriesByAgent(array $agentIds): array
    {
        if ($agentIds === []) {
            return [];
        }

        $rows = AgentInventory::query()
            ->whereIn('agent_id', $agentIds)
            ->orderByDesc('collected_at')
            ->get(['agent_id', 'payload', 'collected_at']);

        $latest = [];
        foreach ($rows as $row) {
            $aid = (string) $row->agent_id;
            if (! isset($latest[$aid])) {
                $latest[$aid] = is_array($row->payload) ? $row->payload : [];
            }
        }

        return $latest;
    }

    /**
     * @return array<string, mixed>
     */
    private function latestInventoryPayloadForHost(Project $project, ObserveTargetHost $host): array
    {
        if ($host->agent_id === null) {
            return [];
        }

        $row = AgentInventory::query()
            ->where('agent_id', $host->agent_id)
            ->orderByDesc('collected_at')
            ->first(['payload']);

        return $row !== null && is_array($row->payload) ? $row->payload : [];
    }
}
