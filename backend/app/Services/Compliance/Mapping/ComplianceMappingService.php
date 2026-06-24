<?php

namespace App\Services\Compliance\Mapping;

use App\Enums\Compliance\CorpusRevisionStatus;
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
 * Cross-Framework Mapping Foundation (QCIF Sprint 8) — orchestrates deterministic, UUID-only
 * mapping responses built on control objectives.
 *
 * Returns EMPTY mappings where data does not exist; never fabricates relationships and never
 * hardcodes framework relationships. Confidence is a BASIS (official|manual|derived), never a
 * numeric score. Performs NO AI execution.
 */
class ComplianceMappingService
{
    public function __construct(
        private readonly ComplianceControlObjectiveResolver $objectives = new ComplianceControlObjectiveResolver(),
        private readonly ComplianceCorpusQueryService $queryService = new ComplianceCorpusQueryService(),
    ) {}

    /**
     * Effective (frameworkKey, releaseCode) for caching. Explicit params win; otherwise the
     * single release that currently has an active revision (NCA ECC today). Null when none.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public function resolveContextCodes(?string $frameworkKey, ?string $releaseCode): array
    {
        [$release, , $framework] = $this->resolveContext($frameworkKey, $releaseCode);
        if ($release === null || $framework === null) {
            return [null, null];
        }

        return [(string) $framework->key, (string) $release->version_code];
    }

    /**
     * @return array<string, mixed>
     */
    public function getControlObjectives(?string $frameworkKey = null, ?string $releaseCode = null): array
    {
        [$release, $revision, $framework] = $this->resolveContext($frameworkKey, $releaseCode);

        $objectives = $this->objectives->allObjectives()->map(function (ComplianceControlObjective $objective) use ($release) {
            $node = $this->objectiveNode($objective);
            $node['mapped_control_count'] = count($this->objectives->controlLinksForObjective($objective, $release));

            return $node;
        })->all();

        return array_merge(
            $this->head('control_objectives', $framework, $release, $revision),
            [
                'objectives' => $objectives,
                'count' => count($objectives),
                'generated_at' => $this->now(),
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function getObjectiveMapping(string $objectiveCode, ?string $frameworkKey = null, ?string $releaseCode = null): array
    {
        [$release, $revision, $framework] = $this->resolveContext($frameworkKey, $releaseCode);
        $objective = $this->objectives->findObjective($objectiveCode);

        $relatedControls = [];
        foreach ($this->objectives->controlLinksForObjective($objective, $release) as $link) {
            $node = $this->controlNode($link['control']);
            $node['confidence'] = $link['confidence']->value;
            $node['mapping_type'] = $link['mapping_type'];
            $node['origin'] = $link['origin'];
            $relatedControls[] = $node;
        }

        return array_merge(
            $this->head('objective_mapping', $framework, $release, $revision),
            [
                'objective' => $this->objectiveNode($objective),
                'source_control' => null,
                'related_controls' => $relatedControls,
                'generated_at' => $this->now(),
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function getControlMapping(string $controlCode, ?string $frameworkKey = null, ?string $releaseCode = null): array
    {
        [$release, $revision, $framework] = $this->resolveContext($frameworkKey, $releaseCode);
        $control = $this->resolveControl($release, $controlCode);

        // The control's own release/revision is authoritative for this response.
        $control->loadMissing(['sourceDocument', 'domain', 'frameworkRelease.framework']);
        $controlRelease = $control->frameworkRelease ?? $release;
        $controlFramework = $controlRelease?->framework ?? $framework;
        $controlRevision = $controlRelease !== null ? $this->safeActiveRevision($controlRelease) : $revision;

        $objectiveLinks = $this->objectives->objectiveLinksForControl($control);
        $objectives = [];
        $primaryObjective = null;
        foreach ($objectiveLinks as $link) {
            $node = $this->objectiveNode($link['objective']);
            $node['confidence'] = $link['confidence']->value;
            $node['origin'] = $link['origin'];
            $objectives[] = $node;
            if ($primaryObjective === null && $link['origin'] === 'corpus') {
                $primaryObjective = $this->objectiveNode($link['objective']);
            }
        }

        $intraRelated = [];
        foreach ($this->objectives->relatedControlLinks($control, $controlRelease) as $link) {
            $node = $this->controlNode($link['control']);
            $node['confidence'] = $link['confidence']->value;
            $node['shared_objectives'] = array_map(
                fn (ComplianceControlObjective $o) => ['uuid' => $o->uuid, 'code' => $o->code, 'title_en' => $o->title_en, 'title_ar' => $o->title_ar],
                $link['shared_objectives'],
            );
            $intraRelated[] = $node;
        }

        return array_merge(
            $this->head('control_mapping', $controlFramework, $controlRelease, $controlRevision),
            [
                'objective' => $primaryObjective,
                'source_control' => array_merge($this->controlNode($control), ['objectives' => $objectives]),
                'related_controls' => [
                    'intra_framework' => $intraRelated,
                    // No cross-framework mapping provider is bound this sprint, and only one
                    // framework exists — so cross-framework relationships are empty (no fakes).
                    'cross_framework' => [],
                ],
                'generated_at' => $this->now(),
            ],
        );
    }

    // -------------------------------------------------------------------------
    // Context resolution
    // -------------------------------------------------------------------------

    /**
     * @return array{0: ?ComplianceFrameworkRelease, 1: ?ComplianceCorpusRevision, 2: ?ComplianceFramework}
     */
    public function resolveContext(?string $frameworkKey, ?string $releaseCode): array
    {
        if ($frameworkKey !== null && $frameworkKey !== '' && $releaseCode !== null && $releaseCode !== '') {
            $release = $this->queryService->resolveRelease($frameworkKey, $releaseCode);
            $revision = $this->queryService->getActiveRevision($release);

            return [$release, $revision, $release->framework];
        }

        return $this->resolvePrimary();
    }

    /**
     * The single framework release that currently has an active revision. Returns nulls when
     * zero or more than one exist (caller must then pass explicit framework/release).
     *
     * @return array{0: ?ComplianceFrameworkRelease, 1: ?ComplianceCorpusRevision, 2: ?ComplianceFramework}
     */
    private function resolvePrimary(): array
    {
        $releaseIds = ComplianceCorpusRevision::query()
            ->where('status', CorpusRevisionStatus::Active)
            ->pluck('framework_release_id')
            ->unique()
            ->values();

        if ($releaseIds->count() !== 1) {
            return [null, null, null];
        }

        $release = ComplianceFrameworkRelease::query()
            ->with('framework')
            ->whereKey($releaseIds->first())
            ->first();

        if ($release === null) {
            return [null, null, null];
        }

        return [$release, $this->safeActiveRevision($release), $release->framework];
    }

    private function safeActiveRevision(ComplianceFrameworkRelease $release): ?ComplianceCorpusRevision
    {
        return ComplianceCorpusRevision::query()
            ->where('framework_release_id', $release->id)
            ->where('status', CorpusRevisionStatus::Active)
            ->orderByDesc('revision_number')
            ->first();
    }

    private function resolveControl(?ComplianceFrameworkRelease $release, string $controlCode): ComplianceControl
    {
        $query = ComplianceControl::query()
            ->where(function ($q) use ($controlCode): void {
                $q->where('code', $controlCode)
                    ->orWhere('display_code', $controlCode)
                    ->orWhere('normalized_code', $controlCode);
            });

        if ($release !== null) {
            $query->where('framework_release_id', $release->id);
        }

        $control = $query->first();
        if ($control === null) {
            throw new \App\Exceptions\ComplianceCorpusNotFoundException("Control not found: {$controlCode}.");
        }

        return $control;
    }

    // -------------------------------------------------------------------------
    // Node builders (UUID-only)
    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function objectiveNode(ComplianceControlObjective $objective): array
    {
        return [
            'entity_type' => 'control_objective',
            'uuid' => $objective->uuid,
            'code' => $objective->code,
            'title_en' => $objective->title_en,
            'title_ar' => $objective->title_ar,
            'category_en' => $objective->category_en,
            'category_ar' => $objective->category_ar,
            'provenance' => [
                'source_reference' => $objective->source_reference,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function controlNode(ComplianceControl $control): array
    {
        $control->loadMissing(['sourceDocument', 'domain']);
        $domain = $control->domain;

        return [
            'entity_type' => 'control',
            'uuid' => $control->uuid,
            'code' => $control->code,
            'display_code' => $control->display_code,
            'normalized_code' => $control->normalized_code,
            'title_en' => $control->title_en,
            'title_ar' => $control->title_ar,
            'domain' => $domain === null ? null : [
                'uuid' => $domain->uuid,
                'code' => $domain->code,
                'title_en' => $domain->title_en,
                'title_ar' => $domain->title_ar,
            ],
            'provenance' => [
                'source_document_key' => $control->sourceDocument?->key,
                'source_reference' => $control->source_reference,
                'source_page' => $control->source_page,
                'official_reference' => $control->official_reference,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function head(string $contextType, ?ComplianceFramework $framework, ?ComplianceFrameworkRelease $release, ?ComplianceCorpusRevision $revision): array
    {
        return [
            'context_type' => $contextType,
            'framework' => $framework === null ? null : ComplianceFrameworkResource::make($framework)->resolve(),
            'release' => $release === null ? null : ComplianceFrameworkReleaseResource::make($release)->resolve(),
            'revision' => $revision === null ? null : ComplianceCorpusRevisionResource::make($revision)->resolve(),
        ];
    }

    private function now(): string
    {
        return now()->toIso8601String();
    }
}
