<?php

namespace App\Services\Compliance\Mapping;

use App\Enums\Compliance\CorpusRevisionStatus;
use App\Exceptions\ComplianceCorpusNotFoundException;
use App\Http\Resources\Compliance\ComplianceCorpusRevisionResource;
use App\Http\Resources\Compliance\ComplianceFrameworkReleaseResource;
use App\Http\Resources\Compliance\ComplianceFrameworkResource;
use App\Models\Compliance\ComplianceControl;
use App\Models\Compliance\ComplianceControlObjective;
use App\Models\Compliance\ComplianceCorpusRevision;
use App\Models\Compliance\ComplianceFramework;
use App\Models\Compliance\ComplianceFrameworkRelease;
use App\Services\Compliance\ComplianceCorpusQueryService;

/**
 * Framework coverage + comparison via shared control objectives.
 *
 * Coverage is intra-framework today (NCA ECC). Comparison is generic and future-ready: it
 * intersects the objectives referenced by two frameworks' controls. With only one framework
 * onboarded, comparison returns an EMPTY result (no fabricated cross-framework mappings).
 * Confidence is a BASIS (official|manual|derived); no numeric scores. No AI execution.
 */
class ComplianceFrameworkComparisonService
{
    public function __construct(
        private readonly ComplianceControlObjectiveResolver $objectives = new ComplianceControlObjectiveResolver(),
        private readonly ComplianceCorpusQueryService $queryService = new ComplianceCorpusQueryService(),
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getFrameworkCoverage(string $frameworkKey, ?string $releaseCode = null): array
    {
        $release = $this->resolveRelease($frameworkKey, $releaseCode);
        if ($release === null) {
            throw new ComplianceCorpusNotFoundException("Framework release not found for framework={$frameworkKey}.");
        }
        $revision = $this->safeActiveRevision($release);
        $framework = $release->framework;

        $totalControls = ComplianceControl::query()->where('framework_release_id', $release->id)->count();

        $coverage = [];
        $mappedControlIds = [];
        foreach ($this->objectives->allObjectives() as $objective) {
            $links = $this->objectives->controlLinksForObjective($objective, $release);
            if ($links === []) {
                continue;
            }

            $official = 0;
            $manual = 0;
            foreach ($links as $link) {
                $mappedControlIds[$link['control']->id] = true;
                if ($link['origin'] === 'corpus') {
                    $official++;
                } else {
                    $manual++;
                }
            }

            $coverage[] = [
                'objective' => [
                    'uuid' => $objective->uuid,
                    'code' => $objective->code,
                    'title_en' => $objective->title_en,
                    'title_ar' => $objective->title_ar,
                ],
                'control_count' => count($links),
                'confidence_breakdown' => [
                    'official' => $official,
                    'manual' => $manual,
                ],
            ];
        }

        $mappedCount = count($mappedControlIds);

        return [
            'context_type' => 'framework_coverage',
            'framework' => $framework === null ? null : ComplianceFrameworkResource::make($framework)->resolve(),
            'release' => ComplianceFrameworkReleaseResource::make($release)->resolve(),
            'revision' => $revision === null ? null : ComplianceCorpusRevisionResource::make($revision)->resolve(),
            'coverage_summary' => [
                'total_controls' => $totalControls,
                'mapped_controls' => $mappedCount,
                'unmapped_controls' => max(0, $totalControls - $mappedCount),
                'objectives_referenced' => count($coverage),
            ],
            'objectives' => $coverage,
            'generated_at' => $this->now(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getFrameworkComparison(
        string $sourceFrameworkKey,
        string $targetFrameworkKey,
        ?string $sourceReleaseCode = null,
        ?string $targetReleaseCode = null,
    ): array {
        $sourceRelease = $this->resolveRelease($sourceFrameworkKey, $sourceReleaseCode);
        if ($sourceRelease === null) {
            throw new ComplianceCorpusNotFoundException("Source framework release not found: {$sourceFrameworkKey}.");
        }

        $targetRelease = $this->resolveRelease($targetFrameworkKey, $targetReleaseCode);

        // Target not onboarded (or no active revision) → empty comparison, no fabricated data.
        if ($targetRelease === null) {
            return [
                'context_type' => 'framework_comparison',
                'source' => $this->frameworkBlock($sourceRelease),
                'target' => null,
                'shared_objectives' => [],
                'note' => "No comparable framework available for '{$targetFrameworkKey}'. Cross-framework mappings will appear once the framework is onboarded.",
                'generated_at' => $this->now(),
            ];
        }

        $sourceMap = $this->objectiveControlMap($sourceRelease);
        $targetMap = $this->objectiveControlMap($targetRelease);
        $sharedObjectiveIds = array_intersect(array_keys($sourceMap), array_keys($targetMap));

        $shared = [];
        foreach ($sharedObjectiveIds as $objectiveId) {
            $objective = $sourceMap[$objectiveId]['objective'];
            $shared[] = [
                'objective' => [
                    'uuid' => $objective->uuid,
                    'code' => $objective->code,
                    'title_en' => $objective->title_en,
                    'title_ar' => $objective->title_ar,
                ],
                'source_controls' => $sourceMap[$objectiveId]['controls'],
                'target_controls' => $targetMap[$objectiveId]['controls'],
                // Relationship is computed by QCIF from a shared objective, not asserted by a source.
                'confidence' => 'derived',
            ];
        }

        return [
            'context_type' => 'framework_comparison',
            'source' => $this->frameworkBlock($sourceRelease),
            'target' => $this->frameworkBlock($targetRelease),
            'shared_objectives' => $shared,
            'note' => $shared === [] ? 'No shared control objectives between the selected frameworks.' : null,
            'generated_at' => $this->now(),
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * objectiveId => [objective => model, controls => list<control node>]
     *
     * @return array<int, array{objective: ComplianceControlObjective, controls: list<array<string, mixed>>}>
     */
    private function objectiveControlMap(ComplianceFrameworkRelease $release): array
    {
        $map = [];
        foreach ($this->objectives->allObjectives() as $objective) {
            $links = $this->objectives->controlLinksForObjective($objective, $release);
            if ($links === []) {
                continue;
            }
            $controls = [];
            foreach ($links as $link) {
                $control = $link['control'];
                $controls[] = [
                    'uuid' => $control->uuid,
                    'code' => $control->code,
                    'title_en' => $control->title_en,
                    'title_ar' => $control->title_ar,
                    'confidence' => $link['confidence']->value,
                ];
            }
            $map[(int) $objective->id] = ['objective' => $objective, 'controls' => $controls];
        }

        return $map;
    }

    /**
     * @return array<string, mixed>
     */
    private function frameworkBlock(ComplianceFrameworkRelease $release): array
    {
        $revision = $this->safeActiveRevision($release);

        return [
            'framework' => $release->framework === null ? null : ComplianceFrameworkResource::make($release->framework)->resolve(),
            'release' => ComplianceFrameworkReleaseResource::make($release)->resolve(),
            'revision' => $revision === null ? null : ComplianceCorpusRevisionResource::make($revision)->resolve(),
        ];
    }

    private function resolveRelease(string $frameworkKey, ?string $releaseCode): ?ComplianceFrameworkRelease
    {
        try {
            if ($releaseCode !== null && $releaseCode !== '') {
                return $this->queryService->resolveRelease($frameworkKey, $releaseCode);
            }
        } catch (ComplianceCorpusNotFoundException) {
            return null;
        }

        // No explicit release → the framework's release that currently has an active revision.
        $framework = ComplianceFramework::query()->where('key', $frameworkKey)->first();
        if ($framework === null) {
            return null;
        }

        $releaseId = ComplianceCorpusRevision::query()
            ->where('status', CorpusRevisionStatus::Active)
            ->whereIn('framework_release_id', ComplianceFrameworkRelease::query()->where('framework_id', $framework->id)->select('id'))
            ->orderByDesc('revision_number')
            ->value('framework_release_id');

        if ($releaseId === null) {
            return null;
        }

        return ComplianceFrameworkRelease::query()->with('framework')->whereKey($releaseId)->first();
    }

    private function safeActiveRevision(ComplianceFrameworkRelease $release): ?ComplianceCorpusRevision
    {
        return ComplianceCorpusRevision::query()
            ->where('framework_release_id', $release->id)
            ->where('status', CorpusRevisionStatus::Active)
            ->orderByDesc('revision_number')
            ->first();
    }

    private function now(): string
    {
        return now()->toIso8601String();
    }
}
