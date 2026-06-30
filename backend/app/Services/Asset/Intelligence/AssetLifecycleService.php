<?php

declare(strict_types=1);

namespace App\Services\Asset\Intelligence;

use App\Models\ObserveTargetHost;
use App\Models\Project;

/**
 * Sprint 22 — Lifecycle Intelligence for a single asset.
 *
 * Produces a lifecycle assessment from REAL collected facts only. Warranty / end-of-life /
 * end-of-support DATES are not collected by this product, so they are surfaced as "not collected"
 * (never fabricated). A replacement-priority signal is derived purely from observable evidence:
 * inactivity and agent-version lag relative to the rest of the fleet.
 */
class AssetLifecycleService
{
    public function __construct(
        private readonly AssetEvidenceCollector $evidence,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forecast(Project $project, ObserveTargetHost $host): array
    {
        $lifecycle = $this->evidence->lifecycle($project, $host);
        $asset = $this->evidence->assetSummary($project, $host);

        $signals = [];
        if ($asset['inactive']) {
            $signals[] = ['type' => 'inactive', 'detail' => 'Asset is inactive (agent offline or not reporting).'];
        }
        if ($asset['stale']) {
            $signals[] = ['type' => 'stale', 'detail' => 'Agent has not reported recently.'];
        }

        $fleetVersions = $this->fleetAgentVersions($project);
        $assetVersion = $asset['agent']['agent_version'] ?? null;
        if ($assetVersion !== null && $fleetVersions !== [] && $this->isLagging($assetVersion, $fleetVersions)) {
            $signals[] = ['type' => 'version_lag', 'detail' => 'Agent version is behind the most common fleet version ('.$this->mostCommon($fleetVersions).').'];
        }

        return [
            'asset' => $asset,
            'lifecycle' => $lifecycle,
            'replacement_priority' => $this->priority($signals),
            'signals' => $signals,
            'business_impact' => $this->businessImpact($asset),
            'note' => 'Lifecycle dates (warranty/EOL/EOS) are not collected; replacement priority is derived only from observable evidence.',
        ];
    }

    /**
     * @param  list<array<string, string>>  $signals
     */
    private function priority(array $signals): string
    {
        $types = array_column($signals, 'type');
        if (in_array('inactive', $types, true)) {
            return 'high';
        }
        if ($signals !== []) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * @param  array<string, mixed>  $asset
     */
    private function businessImpact(array $asset): string
    {
        $serviceCount = (int) ($asset['service_count'] ?? 0);
        if ($serviceCount >= 5) {
            return 'high';
        }
        if ($serviceCount > 0) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * @return list<string>
     */
    private function fleetAgentVersions(Project $project): array
    {
        $versions = [];
        foreach ($this->evidence->inventory($project) as $asset) {
            $v = $asset['agent']['agent_version'] ?? null;
            if (is_string($v) && $v !== '') {
                $versions[] = $v;
            }
        }

        return $versions;
    }

    /**
     * @param  list<string>  $versions
     */
    private function mostCommon(array $versions): string
    {
        $counts = array_count_values($versions);
        arsort($counts);

        return (string) array_key_first($counts);
    }

    /**
     * @param  list<string>  $versions
     */
    private function isLagging(string $version, array $versions): bool
    {
        return version_compare($version, $this->mostCommon($versions), '<');
    }
}
