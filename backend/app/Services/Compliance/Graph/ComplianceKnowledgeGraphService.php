<?php

namespace App\Services\Compliance\Graph;

use App\Exceptions\ComplianceCorpusNotFoundException;
use App\Http\Resources\Compliance\ComplianceCorpusRevisionResource;
use App\Http\Resources\Compliance\ComplianceFrameworkReleaseResource;
use App\Http\Resources\Compliance\ComplianceFrameworkResource;
use App\Models\Compliance\ComplianceControl;
use App\Models\Compliance\ComplianceCorpusRevision;
use App\Models\Compliance\ComplianceDomain;
use App\Models\Compliance\ComplianceFramework;
use App\Models\Compliance\ComplianceFrameworkRelease;
use App\Models\Compliance\ComplianceRequirement;
use App\Services\Compliance\ComplianceCorpusQueryService;
use Illuminate\Support\Facades\DB;

/**
 * Compliance Knowledge Graph Layer (QCIF Sprint 7) — intra-framework navigation of the
 * Domain → Control → Requirement tree (plus Control self-hierarchy).
 *
 * Produces deterministic, UUID-only graph context responses. Performs NO AI execution,
 * vectors, RAG, scoring, or assessment. Cross-framework edges are delegated to an optional
 * provider seam (empty in this sprint).
 */
class ComplianceKnowledgeGraphService
{
    public function __construct(
        private readonly ComplianceCorpusQueryService $queryService = new ComplianceCorpusQueryService(),
        private readonly ComplianceRelationshipResolver $relationships = new ComplianceRelationshipResolver(),
        private readonly ComplianceCrossReferenceService $crossReferences = new ComplianceCrossReferenceService(),
    ) {}

    // -------------------------------------------------------------------------
    // Context capabilities
    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function getFrameworkContext(string $frameworkKey, string $releaseCode): array
    {
        [$release, $revision, $framework] = $this->resolveContext($frameworkKey, $releaseCode);
        $domains = $this->relationships->domainsOfRelease($release);

        return array_merge(
            $this->envelopeHead('framework_context', $framework, $release, $revision),
            [
                'node' => $this->frameworkNode($framework),
                'graph' => [
                    'root' => 'framework',
                    'domains' => $domains->map(fn (ComplianceDomain $d) => $this->domainNode($d, true))->all(),
                ],
                'counts' => $this->frameworkCounts($release),
                'generated_at' => $this->now(),
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function getDomainContext(string $frameworkKey, string $releaseCode, string $domainCode): array
    {
        [$release, $revision, $framework] = $this->resolveContext($frameworkKey, $releaseCode);
        $domain = $this->resolveDomain($release, $domainCode);

        $controls = $this->relationships->controlsOfDomain($domain);
        $ancestors = array_map(fn (ComplianceDomain $d) => $this->domainNode($d), $this->relationships->parentDomainChain($domain));
        $siblings = $this->siblingDomains($release, $domain);

        return array_merge(
            $this->envelopeHead('domain_context', $framework, $release, $revision),
            [
                'node' => $this->domainNode($domain, true),
                'ancestors' => $ancestors,
                'descendants' => $controls->map(fn (ComplianceControl $c) => $this->controlNode($c, true))->all(),
                'siblings' => $siblings,
                'cross_references' => $this->crossReferences->crossReferencesFor('domain', (string) $domain->uuid, $frameworkKey, $releaseCode),
                'counts' => [
                    'controls' => $this->relationships->controlCountForDomain($domain),
                    'requirements' => $this->relationships->requirementCountForDomain($domain),
                ],
                'generated_at' => $this->now(),
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function getControlContext(string $frameworkKey, string $releaseCode, string $controlCode): array
    {
        [$release, $revision, $framework] = $this->resolveContext($frameworkKey, $releaseCode);
        $control = $this->resolveControl($release, $controlCode);

        $childControls = $this->relationships->childControls($control);
        $requirements = $this->relationships->requirementsOfControl($control);

        $descendants = array_merge(
            $childControls->map(fn (ComplianceControl $c) => $this->controlNode($c, true))->all(),
            $requirements->map(fn (ComplianceRequirement $r) => $this->requirementNode($r))->all(),
        );

        return array_merge(
            $this->envelopeHead('control_context', $framework, $release, $revision),
            [
                'node' => $this->controlNode($control, true),
                'ancestors' => $this->controlAncestorNodes($control),
                'descendants' => $descendants,
                'siblings' => $this->relationships->siblingControls($control)
                    ->map(fn (ComplianceControl $c) => $this->controlNode($c))->all(),
                'cross_references' => $this->crossReferences->crossReferencesFor('control', (string) $control->uuid, $frameworkKey, $releaseCode),
                'generated_at' => $this->now(),
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function getRequirementContext(string $frameworkKey, string $releaseCode, string $requirementCode): array
    {
        [$release, $revision, $framework] = $this->resolveContext($frameworkKey, $releaseCode);
        $requirement = $this->resolveRequirement($release, $requirementCode);

        $control = $this->relationships->controlOfRequirement($requirement);
        $domain = $control !== null ? $this->relationships->domainOfControl($control) : null;

        $ancestors = [];
        if ($domain !== null) {
            $ancestors[] = $this->domainNode($domain);
        }
        if ($control !== null) {
            foreach ($this->relationships->parentControlChain($control) as $parent) {
                $ancestors[] = $this->controlNode($parent);
            }
            $ancestors[] = $this->controlNode($control);
        }

        return array_merge(
            $this->envelopeHead('requirement_context', $framework, $release, $revision),
            [
                'node' => $this->requirementNode($requirement),
                'ancestors' => $ancestors,
                'descendants' => [],
                'siblings' => $this->relationships->siblingRequirements($requirement)
                    ->map(fn (ComplianceRequirement $r) => $this->requirementNode($r))->all(),
                'cross_references' => $this->crossReferences->crossReferencesFor('requirement', (string) $requirement->uuid, $frameworkKey, $releaseCode),
                'generated_at' => $this->now(),
            ],
        );
    }

    // -------------------------------------------------------------------------
    // Granular graph capabilities
    // -------------------------------------------------------------------------

    /**
     * Ordered root → immediate parent (excludes the entity itself).
     *
     * @return list<array<string, mixed>>
     */
    public function getAncestors(string $frameworkKey, string $releaseCode, string $entityType, string $code): array
    {
        [$release] = $this->resolveContext($frameworkKey, $releaseCode);

        return match ($entityType) {
            'domain' => array_map(fn (ComplianceDomain $d) => $this->domainNode($d), $this->relationships->parentDomainChain($this->resolveDomain($release, $code))),
            'control' => $this->controlAncestorNodes($this->resolveControl($release, $code)),
            'requirement' => $this->requirementAncestorNodes($this->resolveRequirement($release, $code)),
            default => throw new ComplianceCorpusNotFoundException("Unknown entity type: {$entityType}."),
        };
    }

    /**
     * Immediate descendants (one level). Domains → controls; controls → child controls +
     * requirements; requirements → [].
     *
     * @return list<array<string, mixed>>
     */
    public function getDescendants(string $frameworkKey, string $releaseCode, string $entityType, string $code): array
    {
        [$release] = $this->resolveContext($frameworkKey, $releaseCode);

        return match ($entityType) {
            'domain' => $this->relationships->controlsOfDomain($this->resolveDomain($release, $code))
                ->map(fn (ComplianceControl $c) => $this->controlNode($c, true))->all(),
            'control' => $this->controlDescendantNodes($this->resolveControl($release, $code)),
            'requirement' => [],
            default => throw new ComplianceCorpusNotFoundException("Unknown entity type: {$entityType}."),
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getSiblingControls(string $frameworkKey, string $releaseCode, string $controlCode): array
    {
        [$release] = $this->resolveContext($frameworkKey, $releaseCode);
        $control = $this->resolveControl($release, $controlCode);

        return $this->relationships->siblingControls($control)
            ->map(fn (ComplianceControl $c) => $this->controlNode($c))->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getSiblingRequirements(string $frameworkKey, string $releaseCode, string $requirementCode): array
    {
        [$release] = $this->resolveContext($frameworkKey, $releaseCode);
        $requirement = $this->resolveRequirement($release, $requirementCode);

        return $this->relationships->siblingRequirements($requirement)
            ->map(fn (ComplianceRequirement $r) => $this->requirementNode($r))->all();
    }

    // -------------------------------------------------------------------------
    // Resolution helpers
    // -------------------------------------------------------------------------

    /**
     * @return array{0: ComplianceFrameworkRelease, 1: ComplianceCorpusRevision, 2: ComplianceFramework}
     */
    private function resolveContext(string $frameworkKey, string $releaseCode): array
    {
        $release = $this->queryService->resolveRelease($frameworkKey, $releaseCode);
        $revision = $this->queryService->getActiveRevision($release);

        return [$release, $revision, $release->framework];
    }

    private function resolveDomain(ComplianceFrameworkRelease $release, string $domainCode): ComplianceDomain
    {
        $domain = $this->queryService->findDomain($release, $domainCode);
        $domain->loadMissing('sourceDocument');

        return $domain;
    }

    private function resolveControl(ComplianceFrameworkRelease $release, string $controlCode): ComplianceControl
    {
        $control = $this->queryService->findControl($release, $controlCode);
        $control->loadMissing('sourceDocument');

        return $control;
    }

    private function resolveRequirement(ComplianceFrameworkRelease $release, string $requirementCode): ComplianceRequirement
    {
        $requirement = ComplianceRequirement::query()
            ->where('framework_release_id', $release->id)
            ->where(function ($query) use ($requirementCode): void {
                $query->where('code', $requirementCode)
                    ->orWhere('display_code', $requirementCode)
                    ->orWhere('normalized_code', $requirementCode);
            })
            ->with('sourceDocument')
            ->first();

        if ($requirement === null) {
            throw new ComplianceCorpusNotFoundException("Requirement not found: {$requirementCode}.");
        }

        return $requirement;
    }

    // -------------------------------------------------------------------------
    // Node + edge builders
    // -------------------------------------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    private function controlAncestorNodes(ComplianceControl $control): array
    {
        $nodes = [];
        $domain = $this->relationships->domainOfControl($control);
        if ($domain !== null) {
            $nodes[] = $this->domainNode($domain);
        }
        foreach ($this->relationships->parentControlChain($control) as $parent) {
            $nodes[] = $this->controlNode($parent);
        }

        return $nodes;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function requirementAncestorNodes(ComplianceRequirement $requirement): array
    {
        $control = $this->relationships->controlOfRequirement($requirement);
        if ($control === null) {
            return [];
        }
        $nodes = $this->controlAncestorNodes($control);
        $nodes[] = $this->controlNode($control);

        return $nodes;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function controlDescendantNodes(ComplianceControl $control): array
    {
        return array_merge(
            $this->relationships->childControls($control)->map(fn (ComplianceControl $c) => $this->controlNode($c, true))->all(),
            $this->relationships->requirementsOfControl($control)->map(fn (ComplianceRequirement $r) => $this->requirementNode($r))->all(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function frameworkNode(ComplianceFramework $framework): array
    {
        return [
            'entity_type' => 'framework',
            'uuid' => $framework->uuid,
            'key' => $framework->key,
            'code' => $framework->code,
            'title_en' => $framework->title_en,
            'title_ar' => $framework->title_ar,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function domainNode(ComplianceDomain $domain, bool $withCounts = false): array
    {
        $node = [
            'entity_type' => 'domain',
            'uuid' => $domain->uuid,
            'code' => $domain->code,
            'display_code' => $domain->display_code,
            'normalized_code' => $domain->normalized_code,
            'title_en' => $domain->title_en,
            'title_ar' => $domain->title_ar,
            'provenance' => $this->provenance($domain),
        ];

        if ($withCounts) {
            $node['child_counts'] = [
                'controls' => $this->relationships->controlCountForDomain($domain),
                'requirements' => $this->relationships->requirementCountForDomain($domain),
            ];
        }

        return $node;
    }

    /**
     * @return array<string, mixed>
     */
    private function controlNode(ComplianceControl $control, bool $withCounts = false): array
    {
        $node = [
            'entity_type' => 'control',
            'uuid' => $control->uuid,
            'code' => $control->code,
            'display_code' => $control->display_code,
            'normalized_code' => $control->normalized_code,
            'level' => $control->level,
            'control_type' => $control->control_type?->value,
            'title_en' => $control->title_en,
            'title_ar' => $control->title_ar,
            'provenance' => $this->provenance($control),
        ];

        if ($withCounts) {
            $node['child_counts'] = [
                'child_controls' => $this->relationships->childControlCount($control),
                'requirements' => $this->relationships->requirementCountForControl($control),
            ];
        }

        return $node;
    }

    /**
     * @return array<string, mixed>
     */
    private function requirementNode(ComplianceRequirement $requirement): array
    {
        return [
            'entity_type' => 'requirement',
            'uuid' => $requirement->uuid,
            'code' => $requirement->code,
            'display_code' => $requirement->display_code,
            'normalized_code' => $requirement->normalized_code,
            'title_en' => $requirement->title_en,
            'title_ar' => $requirement->title_ar,
            'requirement_text_en' => $requirement->requirement_text_en,
            'requirement_text_ar' => $requirement->requirement_text_ar,
            'provenance' => $this->provenance($requirement),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function provenance(ComplianceControl|ComplianceDomain|ComplianceRequirement $entity): array
    {
        $document = $entity->relationLoaded('sourceDocument')
            ? $entity->sourceDocument
            : $entity->sourceDocument()->getResults();

        return [
            'source_document_key' => $document?->key,
            'source_reference' => $entity->source_reference,
            'source_page' => $entity->source_page,
            'official_reference' => $entity->official_reference,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function siblingDomains(ComplianceFrameworkRelease $release, ComplianceDomain $domain): array
    {
        return $this->relationships->domainsOfRelease($release)
            ->filter(fn (ComplianceDomain $d) => $d->id !== $domain->id && $d->parent_domain_id === $domain->parent_domain_id)
            ->map(fn (ComplianceDomain $d) => $this->domainNode($d))
            ->values()
            ->all();
    }

    /**
     * @return array<string, int>
     */
    private function frameworkCounts(ComplianceFrameworkRelease $release): array
    {
        return [
            'domains' => (int) DB::table('compliance_domains')->where('framework_release_id', $release->id)->count(),
            'controls' => (int) DB::table('compliance_controls')->where('framework_release_id', $release->id)->count(),
            'requirements' => (int) DB::table('compliance_requirements')->where('framework_release_id', $release->id)->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function envelopeHead(
        string $contextType,
        ComplianceFramework $framework,
        ComplianceFrameworkRelease $release,
        ComplianceCorpusRevision $revision,
    ): array {
        return [
            'context_type' => $contextType,
            'framework' => ComplianceFrameworkResource::make($framework)->resolve(),
            'release' => ComplianceFrameworkReleaseResource::make($release)->resolve(),
            'active_revision' => ComplianceCorpusRevisionResource::make($revision)->resolve(),
        ];
    }

    private function now(): string
    {
        return now()->toIso8601String();
    }
}
