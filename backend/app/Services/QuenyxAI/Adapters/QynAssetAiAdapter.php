<?php

namespace App\Services\QuenyxAI\Adapters;

use App\Models\Project;
use App\Services\Asset\Intelligence\AssetEvidenceCollector;

/**
 * QynAsset's adapter into the Quenyx AI Platform (Sprint 22 — Asset Intelligence).
 *
 * QynAsset is the SECOND production AI adapter. It exposes its asset-intelligence capabilities and
 * contextual actions, and builds a deterministic, workspace-scoped evidence context from REAL
 * collected data via {@see AssetEvidenceCollector} (discovered hosts + enrolled agents + agent
 * inventory + reused capacity/topology). It is a thin seam: it reuses the QynAsset domain services and
 * the shared Quenyx AI runtime ({@see \App\Services\AI\ModuleAiNarrator}) — it moves NO business
 * logic, duplicates NO AI logic, calls NO provider directly, and fabricates NO inventory, lifecycle,
 * or license data. Capabilities with no data source (licenses, warranty/EOL dates) are surfaced
 * honestly as "not collected".
 *
 * All action endpoints are workspace-scoped and UUID-only (see {@see \App\Support\Asset\AssetEntityId}).
 */
class QynAssetAiAdapter extends AbstractAiModuleAdapter
{
    public function __construct(
        private readonly AssetEvidenceCollector $evidence,
    ) {}

    public function moduleKey(): string
    {
        return 'qynasset';
    }

    public function moduleName(): string
    {
        return 'QynAsset';
    }

    public function moduleDescription(): string
    {
        return 'Asset Intelligence — explains a real asset inventory: discovery, CMDB questions, '
            .'lifecycle, dependencies, hardware/capacity, and evidence-based recommendations.';
    }

    public function moduleCategory(): string
    {
        return 'Asset Management';
    }

    public function moduleIcon(): string
    {
        return 'server';
    }

    /**
     * @return list<string>
     */
    public function capabilities(): array
    {
        return [
            'asset_discovery_intelligence',
            'cmdb_intelligence',
            'lifecycle_intelligence',
            'asset_relationship_analysis',
            'dependency_intelligence',
            'license_intelligence',
            'hardware_intelligence',
            'operational_asset_summary',
            'asset_health_summary',
            'risk_summary',
        ];
    }

    /**
     * @return list<string>
     */
    public function supportedEntities(): array
    {
        return ['workspace', 'asset', 'dependency', 'relationship', 'lifecycle', 'license'];
    }

    /**
     * Deterministic, workspace-scoped asset context — real data only.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function buildContext(Project $project, array $options = []): array
    {
        $discovery = $this->evidence->discovery($project);

        return [
            'module' => $this->moduleKey(),
            'workspace_uuid' => (string) $project->uuid,
            'generated_at' => now()->toIso8601String(),
            'inventory_summary' => $this->evidence->inventorySummary($project),
            'discovery' => [
                'new_assets' => array_slice($discovery['new_assets'], 0, 25),
                'changed_assets' => array_slice($discovery['changed_assets'], 0, 25),
                'inactive_assets' => array_slice($discovery['inactive_assets'], 0, 25),
                'unknown_assets' => array_slice($discovery['unknown_assets'], 0, 25),
                'duplicate_assets' => $discovery['duplicate_assets'],
            ],
            'capacity' => $this->evidence->capacityRollup($project),
            'license_intelligence' => $this->evidence->licenses($project),
            'guardrails' => [
                'Use only the asset evidence provided in this context.',
                'Cite the specific assets, agents, or metrics you rely on.',
                'Never invent inventory, hardware specs, licenses, or lifecycle/warranty dates.',
                'If a fact is marked not collected, say it is not collected and name the integration required.',
            ],
        ];
    }

    /**
     * Contextual "✨ Quenyx AI" actions, mapped to the UUID-only Asset Intelligence endpoints.
     *
     * @return list<array<string, mixed>>
     */
    public function availableActions(): array
    {
        $base = '/api/qynasset/intelligence';

        return [
            [
                'key' => 'copilot',
                'capability' => 'cmdb_intelligence',
                'target' => 'workspace',
                'label' => 'Ask Quenyx AI',
                'method' => 'POST',
                'endpoint' => "{$base}/copilot",
            ],
            [
                'key' => 'overview',
                'capability' => 'operational_asset_summary',
                'target' => 'workspace',
                'label' => 'Asset Intelligence',
                'method' => 'GET',
                'endpoint' => "{$base}/overview",
            ],
            [
                'key' => 'explain_asset',
                'capability' => 'asset_discovery_intelligence',
                'target' => 'asset',
                'label' => 'Explain',
                'method' => 'POST',
                'endpoint' => "{$base}/assets/{uuid}/explain",
            ],
            [
                'key' => 'analyze_dependency',
                'capability' => 'dependency_intelligence',
                'target' => 'dependency',
                'label' => 'Analyze',
                'method' => 'POST',
                'endpoint' => "{$base}/assets/{uuid}/dependencies",
            ],
            [
                'key' => 'forecast_lifecycle',
                'capability' => 'lifecycle_intelligence',
                'target' => 'lifecycle',
                'label' => 'Forecast',
                'method' => 'POST',
                'endpoint' => "{$base}/assets/{uuid}/lifecycle",
            ],
            [
                'key' => 'relationship_impact',
                'capability' => 'asset_relationship_analysis',
                'target' => 'relationship',
                'label' => 'Impact',
                'method' => 'POST',
                'endpoint' => "{$base}/assets/{uuid}/impact",
            ],
            [
                'key' => 'review_license',
                'capability' => 'license_intelligence',
                'target' => 'license',
                'label' => 'Review',
                'method' => 'POST',
                'endpoint' => "{$base}/licenses/review",
            ],
        ];
    }
}
