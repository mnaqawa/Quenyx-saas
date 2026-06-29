<?php

declare(strict_types=1);

namespace App\Services\Observe\Intelligence;

use App\Models\ObserveService;
use App\Models\ObserveTargetHost;
use App\Models\Project;
use App\Support\Observe\OperationsEntityId;

/**
 * Sprint 21 — Infrastructure Intelligence / Impact Analysis.
 *
 * Reuses the SAME topology the Infrastructure Map is built from (hosts + their service checks +
 * /24 subnet grouping). For a target host it derives, from real data only: the services that depend
 * on the host (downstream checks), the subnet neighbors, the current critical path, a single-point-
 * of-failure assessment, and the potential blast radius if the host fails. No edges or dependencies
 * are invented — if topology data is thin, the result honestly reflects that.
 */
class InfrastructureImpactService
{
    public function __construct(
        private readonly OperationsEvidenceCollector $evidence,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function impact(Project $project, ObserveTargetHost $host): array
    {
        $prefix = $this->evidence->hostPrefix($project);
        $prefixedHost = $prefix.$host->name;

        // Services that run on (depend on) this host.
        $services = ObserveService::query()
            ->where('workspace_id', $project->id)
            ->where('engine_key', 'native')
            ->where('host_name', $prefixedHost)
            ->get(['id', 'service_name', 'state']);

        $downstream = $services->map(fn (ObserveService $s): array => [
            'uuid' => OperationsEntityId::for(OperationsEntityId::TYPE_SERVICE, $project->id, (int) $s->id),
            'name' => (string) $s->service_name,
            'state' => (string) $s->state,
        ])->all();

        $criticalPath = array_values(array_filter($downstream, fn ($s): bool => in_array($s['state'], ['critical', 'warning', 'unreachable'], true)));

        // Subnet neighbors (same /24) — the network-layer relationship the Infrastructure Map derives.
        $subnet = $this->subnetOf((string) $host->address);
        $neighbors = [];
        if ($subnet !== null) {
            $neighbors = ObserveTargetHost::query()
                ->where('workspace_id', $project->id)
                ->where('id', '!=', $host->id)
                ->get(['id', 'name', 'address'])
                ->filter(fn (ObserveTargetHost $h): bool => $this->subnetOf((string) $h->address) === $subnet)
                ->map(fn (ObserveTargetHost $h): array => [
                    'uuid' => OperationsEntityId::for(OperationsEntityId::TYPE_HOST, $project->id, (int) $h->id),
                    'name' => (string) $h->name,
                    'address' => (string) $h->address,
                ])
                ->values()
                ->all();
        }

        $serviceCount = count($downstream);
        $isSpof = $serviceCount > 0 && count($neighbors) === 0; // sole host serving its checks in its subnet

        return [
            'host' => $this->evidence->hostSnapshot($project, $host),
            'subnet' => $subnet,
            'downstream_services' => $downstream,
            'downstream_service_count' => $serviceCount,
            'critical_path' => $criticalPath,
            'subnet_neighbors' => $neighbors,
            'subnet_neighbor_count' => count($neighbors),
            'single_point_of_failure' => $isSpof,
            'blast_radius' => [
                'services_affected' => $serviceCount,
                'subnet_hosts' => count($neighbors),
                'severity' => $this->blastSeverity($serviceCount, count($criticalPath), $isSpof),
            ],
            'topology_note' => $subnet === null
                ? 'Host address is not an IPv4 address; subnet relationships could not be derived.'
                : 'Dependencies derived from service checks on this host and /24 subnet grouping (same model as the Infrastructure Map).',
        ];
    }

    private function blastSeverity(int $serviceCount, int $criticalCount, bool $isSpof): string
    {
        if ($isSpof && $serviceCount > 0) {
            return 'high';
        }
        if ($criticalCount > 0 || $serviceCount >= 5) {
            return 'medium';
        }

        return 'low';
    }

    private function subnetOf(string $address): ?string
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return null;
        }

        $parts = explode('.', $address);
        if (count($parts) !== 4) {
            return null;
        }

        return sprintf('%s.%s.%s.0/24', $parts[0], $parts[1], $parts[2]);
    }
}
