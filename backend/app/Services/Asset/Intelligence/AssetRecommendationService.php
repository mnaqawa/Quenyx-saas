<?php

declare(strict_types=1);

namespace App\Services\Asset\Intelligence;

use App\Models\Project;

/**
 * Sprint 22 — evidence-based asset recommendations.
 *
 * Every recommendation cites concrete evidence (an inactive agent, a stale heartbeat, a duplicate
 * address, a monitored host with no agent, a capacity hotspot). NEVER produces a recommendation
 * without evidence, and never invents lifecycle/license-based advice that has no data behind it.
 */
class AssetRecommendationService
{
    public function __construct(
        private readonly AssetEvidenceCollector $evidence,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function recommendations(Project $project): array
    {
        $recommendations = [];
        $discovery = $this->evidence->discovery($project);

        // 1) Inactive assets → investigate connectivity / decommission.
        foreach ($discovery['inactive_assets'] as $asset) {
            $recommendations[] = [
                'type' => 'investigate_dependency',
                'severity' => 'warning',
                'title' => 'Investigate inactive asset',
                'target' => $asset['name'],
                'target_uuid' => $asset['uuid'],
                'rationale' => sprintf('Asset "%s" is inactive (agent offline or not reporting).', $asset['name']),
                'evidence' => [['type' => 'asset_state', 'uuid' => $asset['uuid'], 'inactive' => true]],
            ];
        }

        // 2) Monitored host without an agent → enroll an agent for full inventory/health.
        foreach ($this->evidence->inventory($project) as $asset) {
            if (! $asset['has_agent'] && (int) $asset['service_count'] > 0) {
                $recommendations[] = [
                    'type' => 'review_ownership',
                    'severity' => 'info',
                    'title' => 'Enroll an agent for full asset coverage',
                    'target' => $asset['name'],
                    'target_uuid' => $asset['uuid'],
                    'rationale' => sprintf('Asset "%s" is monitored (%d service check(s)) but has no enrolled agent, so hardware/inventory facts are unavailable.', $asset['name'], (int) $asset['service_count']),
                    'evidence' => [['type' => 'coverage', 'uuid' => $asset['uuid'], 'has_agent' => false, 'service_count' => $asset['service_count']]],
                ];
            }
        }

        // 3) Duplicate assets → consolidate / review.
        foreach ($discovery['duplicate_assets']['groups'] as $group) {
            $recommendations[] = [
                'type' => 'consolidate_assets',
                'severity' => 'info',
                'title' => 'Review possible duplicate assets',
                'target' => $group['key'],
                'rationale' => sprintf('%d assets share the same %s "%s".', count($group['assets']), $group['by'], $group['key']),
                'evidence' => [['type' => 'duplicate', 'by' => $group['by'], 'key' => $group['key'], 'assets' => $group['assets']]],
            ];
        }

        // 4) Capacity hotspots on assets → hardware action (reuses Capacity Planning health).
        foreach ($this->capacityHotspots($this->evidence->capacityRollup($project)) as $hotspot) {
            $recommendations[] = $hotspot;
        }

        return $recommendations;
    }

    /**
     * Capacity recommendations derived from the workspace-wide capacity health rollup (real data).
     *
     * @param  array<string, mixed>  $capacity
     * @return list<array<string, mixed>>
     */
    private function capacityHotspots(array $capacity): array
    {
        $runway = $capacity['runway'] ?? [];
        if (! is_array($runway)) {
            return [];
        }

        $out = [];
        foreach (['cpu', 'memory', 'storage'] as $resource) {
            $entry = $runway[$resource] ?? null;
            if (! is_array($entry) || ($entry['status'] ?? 'healthy') === 'healthy') {
                continue;
            }
            $out[] = [
                'type' => 'replace_hardware',
                'severity' => ($entry['status'] ?? '') === 'critical' ? 'critical' : 'warning',
                'title' => sprintf('Plan %s hardware/capacity action', $resource),
                'target' => 'workspace',
                'rationale' => $entry['days'] !== null
                    ? sprintf('%s runway is ~%d days (status: %s).', ucfirst($resource), (int) $entry['days'], $entry['status'])
                    : sprintf('%s capacity status is %s.', ucfirst($resource), $entry['status']),
                'evidence' => [['type' => 'capacity', 'resource' => $resource, 'runway_days' => $entry['days'] ?? null]],
            ];
        }

        return $out;
    }
}
