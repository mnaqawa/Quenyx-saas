<?php

declare(strict_types=1);

namespace App\Services\Asset\Intelligence;

use App\DataTransferObjects\Ai\AiUsage;
use App\Models\AuditLog;
use App\Models\ObserveTargetHost;
use App\Models\Project;
use App\Models\User;
use App\Repositories\Ai\AiConversationRepository;
use App\Services\AI\ModuleAiNarrator;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 22 — QynAsset Intelligence orchestrator.
 *
 * Aggregates the Asset Intelligence dashboard (overview), runs the Asset Copilot (reusing the shared
 * Quenyx AI conversation surface), and produces the contextual asset actions (explain, dependency,
 * lifecycle, license, relationship impact). ALL numbers come from {@see AssetEvidenceCollector} and
 * reused platform services; the AI layer only narrates through the shared {@see ModuleAiNarrator}.
 * No provider is called here, no AI logic is duplicated, and nothing is fabricated.
 */
class AssetIntelligenceService
{
    private const AUDIT_PREFIX = 'asset_intelligence_';

    private const ROLE_PREAMBLE = 'You are Quenyx AI operating as an Asset Intelligence analyst for the '
        .'QynAsset platform. You explain a real asset inventory (discovered hosts, enrolled agents, collected '
        .'inventory, dependencies, hardware/capacity). Use ONLY the asset evidence provided below — never invent '
        .'assets, hardware specs, licenses, warranty/lifecycle dates, or numbers. When a fact is marked as not '
        .'collected, say it is not collected and what integration would provide it. Be concise, specific, and '
        .'evidence-based.';

    public function __construct(
        private readonly AssetEvidenceCollector $evidence,
        private readonly ModuleAiNarrator $narrator,
        private readonly DependencyAnalysisService $dependencies,
        private readonly AssetLifecycleService $lifecycle,
        private readonly LicenseAdvisorService $licenses,
        private readonly AssetRecommendationService $recommendationService,
        private readonly AiConversationRepository $conversations,
    ) {}

    /**
     * Asset Intelligence dashboard — real data only (no AI call).
     *
     * @return array<string, mixed>
     */
    public function overview(Project $project): array
    {
        $discovery = $this->evidence->discovery($project);

        return [
            'inventory_summary' => $this->evidence->inventorySummary($project),
            'discovery' => [
                'new_asset_count' => $discovery['new_asset_count'],
                'changed_asset_count' => $discovery['changed_asset_count'],
                'inactive_asset_count' => $discovery['inactive_asset_count'],
                'unknown_asset_count' => $discovery['unknown_asset_count'],
                'duplicate_count' => $discovery['duplicate_assets']['count'],
                'new_assets' => array_slice($discovery['new_assets'], 0, 10),
                'inactive_assets' => array_slice($discovery['inactive_assets'], 0, 10),
            ],
            'capacity' => $this->evidence->capacityRollup($project),
            'recent_recommendations' => array_slice($this->recommendationService->recommendations($project), 0, 8),
            'recent_ai_investigations' => $this->recentInvestigations($project),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * CMDB / Asset Copilot — reuses the shared AI conversation surface and grounds answers in the
     * current asset evidence.
     *
     * @return array<string, mixed>
     */
    public function copilot(Project $project, ?User $user, string $question, ?string $conversationUuid = null): array
    {
        $discovery = $this->evidence->discovery($project);
        $evidence = [
            'inventory_summary' => $this->evidence->inventorySummary($project),
            'discovery' => [
                'new_assets' => array_slice($discovery['new_assets'], 0, 25),
                'changed_assets' => array_slice($discovery['changed_assets'], 0, 25),
                'inactive_assets' => array_slice($discovery['inactive_assets'], 0, 25),
                'unknown_assets' => array_slice($discovery['unknown_assets'], 0, 25),
                'duplicate_assets' => $discovery['duplicate_assets'],
            ],
            'capacity' => $this->evidence->capacityRollup($project),
            'license_intelligence' => $this->licenses->review($project)['licenses'],
        ];

        $ai = $this->narrate($project, $user, 'asset_copilot', $evidence, $question, 'copilot', 'qynasset.intelligence.copilot', $this->summaryCitations($evidence));

        $providerKey = $ai['provider'] ?? 'mock';
        $conversation = $conversationUuid !== null
            ? $this->conversations->findForProject($project, $conversationUuid)
            : null;
        if ($conversation === null) {
            $conversation = $this->conversations->start($project, $user, $providerKey, $ai['model'] ?? null, [
                'title' => 'Asset Copilot',
                'origin' => 'qynasset_asset_intelligence',
            ]);
        }

        $promptLogging = (bool) config('ai.feature_flags.prompt_logging', false);
        $this->conversations->recordMessage($conversation, 'user', $promptLogging ? $question : null, new AiUsage(), (bool) ($ai['mocked'] ?? false));
        $assistant = $this->conversations->recordMessage(
            $conversation,
            'assistant',
            $promptLogging ? ($ai['content'] ?? null) : null,
            new AiUsage(
                (int) ($ai['usage']['prompt_tokens'] ?? 0),
                (int) ($ai['usage']['completion_tokens'] ?? 0),
                (int) ($ai['usage']['total_tokens'] ?? 0),
            ),
            (bool) ($ai['mocked'] ?? false),
        );

        return [
            'conversation_uuid' => $conversation->uuid,
            'message_uuid' => $assistant->uuid,
            'answer' => $ai,
            'evidence' => $evidence,
        ];
    }

    /**
     * Asset Discovery / CMDB explanation for a single asset.
     *
     * @return array<string, mixed>
     */
    public function explainAsset(Project $project, ?User $user, ObserveTargetHost $host): array
    {
        $evidence = $this->evidence->assetEvidence($project, $host);

        $question = sprintf(
            'Explain asset "%s": what it is, its discovery confidence, current activity, hardware facts collected, '
            .'and any gaps — using only the evidence.',
            $host->name
        );

        $ai = $this->narrate($project, $user, 'asset_explain', $evidence, $question, 'asset_explain', 'qynasset.intelligence.assets.explain', $this->assetCitations($evidence['asset']));

        return array_merge($evidence, ['ai_explanation' => $ai]);
    }

    /**
     * @return array<string, mixed>
     */
    public function analyzeDependency(Project $project, ?User $user, ObserveTargetHost $host): array
    {
        $evidence = $this->dependencies->analyze($project, $host);

        $question = sprintf(
            'Analyze the dependencies of asset "%s": what it depends on / serves, subnet neighbors, and any single '
            .'point of failure — using only the topology evidence.',
            $host->name
        );

        $ai = $this->narrate($project, $user, 'asset_dependency', $evidence, $question, 'dependency_analyze', 'qynasset.intelligence.assets.dependencies', $this->assetCitations($evidence['host']));

        return array_merge($evidence, ['ai_analysis' => $ai]);
    }

    /**
     * @return array<string, mixed>
     */
    public function forecastLifecycle(Project $project, ?User $user, ObserveTargetHost $host): array
    {
        $evidence = $this->lifecycle->forecast($project, $host);

        $question = sprintf(
            'Forecast the lifecycle of asset "%s": replacement priority and business impact from the observable '
            .'signals. Lifecycle/warranty dates are not collected — say so explicitly. Use only the evidence.',
            $host->name
        );

        $ai = $this->narrate($project, $user, 'asset_lifecycle', $evidence, $question, 'lifecycle_forecast', 'qynasset.intelligence.assets.lifecycle', $this->assetCitations($evidence['asset']));

        return array_merge($evidence, ['ai_forecast' => $ai]);
    }

    /**
     * @return array<string, mixed>
     */
    public function relationshipImpact(Project $project, ?User $user, ObserveTargetHost $host): array
    {
        $evidence = $this->dependencies->impact($project, $host);

        $question = sprintf(
            'Explain the impact if asset "%s" fails: affected services, blast radius, single point of failure risk, '
            .'and cascading failures — using only the topology evidence.',
            $host->name
        );

        $ai = $this->narrate($project, $user, 'asset_relationship', $evidence, $question, 'relationship_impact', 'qynasset.intelligence.assets.impact', $this->assetCitations($evidence['host']));

        return array_merge($evidence, ['ai_explanation' => $ai]);
    }

    /**
     * License Intelligence review (workspace-level — there is no per-license entity).
     *
     * @return array<string, mixed>
     */
    public function reviewLicense(Project $project, ?User $user): array
    {
        $evidence = $this->licenses->review($project);

        $question = 'Review software license intelligence for this workspace: utilization, unused/missing licenses, '
            .'optimization, and compliance risk. If no license data is collected, say so and name the integration '
            .'required. Use only the evidence.';

        $ai = $this->narrate($project, $user, 'asset_license', $evidence, $question, 'license_review', 'qynasset.intelligence.licenses.review', [[
            'source_document_key' => 'qynasset.licenses',
            'official_reference' => 'License intelligence',
            'type' => 'license',
        ]]);

        return array_merge($evidence, ['ai_review' => $ai]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recommendations(Project $project): array
    {
        return $this->recommendationService->recommendations($project);
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $evidence
     * @param  list<array<string, mixed>>  $citations
     * @return array<string, mixed>
     */
    private function narrate(Project $project, ?User $user, string $contextType, array $evidence, string $question, string $action, string $endpoint, array $citations): array
    {
        return $this->narrator->narrate(
            $project,
            $user,
            $contextType,
            $evidence,
            $question,
            self::ROLE_PREAMBLE,
            self::AUDIT_PREFIX.$action,
            $endpoint,
            ModuleAiNarrator::DEFAULT_GUARDRAILS,
            'text',
            $citations,
        );
    }

    /**
     * @param  array<string, mixed>  $asset
     * @return list<array<string, mixed>>
     */
    private function assetCitations(array $asset): array
    {
        return [[
            'source_document_key' => 'qynasset.asset.'.($asset['uuid'] ?? ''),
            'official_reference' => 'Asset: '.($asset['name'] ?? ''),
            'type' => 'asset',
        ]];
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return list<array<string, mixed>>
     */
    private function summaryCitations(array $evidence): array
    {
        $refs = [['source_document_key' => 'qynasset.inventory_summary', 'official_reference' => 'Asset inventory summary', 'type' => 'inventory']];
        foreach (($evidence['discovery']['inactive_assets'] ?? []) as $asset) {
            $refs[] = ['source_document_key' => 'qynasset.asset.'.($asset['uuid'] ?? ''), 'official_reference' => 'Asset: '.($asset['name'] ?? ''), 'type' => 'asset'];
        }

        return array_slice($refs, 0, 30);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentInvestigations(Project $project): array
    {
        if (! Schema::hasTable('audit_logs')) {
            return [];
        }

        return AuditLog::query()
            ->where('project_id', $project->id)
            ->where('action', 'like', self::AUDIT_PREFIX.'%')
            ->orderByDesc('timestamp')
            ->limit(10)
            ->get(['action', 'metadata', 'timestamp'])
            ->map(fn (AuditLog $log): array => [
                'action' => str_replace(self::AUDIT_PREFIX, '', (string) $log->action),
                'context_type' => is_array($log->metadata) ? ($log->metadata['context_type'] ?? null) : null,
                'at' => optional($log->timestamp)->toIso8601String(),
            ])
            ->all();
    }
}
