<?php

declare(strict_types=1);

namespace App\Services\Asset\Intelligence;

use App\Models\ObserveTargetHost;
use App\Models\Project;
use App\Services\Observe\Intelligence\InfrastructureImpactService;

/**
 * Sprint 22 — Dependency + Relationship Intelligence for an asset.
 *
 * Reuses the SAME topology the Infrastructure Map / Operations Impact Analysis is built from
 * ({@see InfrastructureImpactService}) — service checks on the host plus /24 subnet grouping. No edges
 * are invented: if topology is thin, the result honestly reflects that. "Dependency analyze" focuses
 * on what the asset depends on / serves; "relationship impact" focuses on the blast radius if it
 * fails. Both views come from one real topology source.
 */
class DependencyAnalysisService
{
    public function __construct(
        private readonly InfrastructureImpactService $infrastructure,
    ) {}

    /**
     * Dependency view: downstream services + subnet neighbors (the asset's relationships).
     *
     * @return array<string, mixed>
     */
    public function analyze(Project $project, ObserveTargetHost $host): array
    {
        $impact = $this->infrastructure->impact($project, $host);

        return [
            'host' => $impact['host'],
            'subnet' => $impact['subnet'],
            'dependencies' => $impact['downstream_services'],
            'dependency_count' => $impact['downstream_service_count'],
            'subnet_neighbors' => $impact['subnet_neighbors'],
            'subnet_neighbor_count' => $impact['subnet_neighbor_count'],
            'topology_note' => $impact['topology_note'],
        ];
    }

    /**
     * Relationship impact view: blast radius / single-point-of-failure if the asset fails.
     *
     * @return array<string, mixed>
     */
    public function impact(Project $project, ObserveTargetHost $host): array
    {
        return $this->infrastructure->impact($project, $host);
    }
}
