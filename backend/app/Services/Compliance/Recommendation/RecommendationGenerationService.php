<?php

namespace App\Services\Compliance\Recommendation;

use App\Enums\Compliance\Recommendation\ComplianceRecommendationPriority;
use App\Enums\Compliance\Recommendation\ComplianceRecommendationStatus;
use App\Models\Compliance\ComplianceDomain;
use App\Models\Compliance\ComplianceEvidenceExpectation;
use App\Models\Compliance\Recommendation\ComplianceRecommendation;
use App\Models\Compliance\Recommendation\ComplianceRecommendationAction;
use App\Services\Compliance\Gap\GapAssessmentService;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * The deterministic Recommendation Engine orchestrator (QCIF Sprint 13).
 *
 * Runs a gap assessment (reusing GapAssessmentService → which reuses the Evidence Correlation
 * Engine), then applies the fixed recommendation rules + priority rules to each gap finding to
 * produce explainable remediation recommendations. Every recommendation references its
 * requirement, gap status, evidence considered, rule, corpus revision, and framework release.
 *
 * Deterministic & idempotent: a recommendation's UUID is a uuid5 of (requirement + rule +
 * revision), so regenerating the same state yields the same UUIDs and never duplicates.
 *
 * NO LLM, NO RAG, NO provider calls, NO probabilistic scoring. Compliant requirements yield no
 * recommendation unless explicitly requested. Nothing is fabricated.
 */
class RecommendationGenerationService
{
    /** Stable namespace for deterministic recommendation UUIDs. */
    private const UUID_NAMESPACE = '5b2c1f8e-1c2d-5a4b-9e3f-quenyxqcif13';

    public function __construct(
        private readonly GapAssessmentService $gap = new GapAssessmentService(),
        private readonly RecommendationRuleService $rules = new RecommendationRuleService(),
        private readonly RecommendationPrioritizationService $priority = new RecommendationPrioritizationService(),
        private readonly RecommendationSummaryService $summary = new RecommendationSummaryService(),
    ) {}

    /**
     * Generate (in-memory, no persistence) the full set of recommendations for a workspace.
     *
     * @param  array<string, mixed>  $options  Supported: include_compliant (bool)
     * @return array<string, mixed>
     */
    public function generate(?string $frameworkKey, ?string $releaseCode, int $projectId, array $options = []): array
    {
        $assessment = $this->gap->assess($frameworkKey, $releaseCode, $projectId);
        $includeCompliant = (bool) ($options['include_compliant'] ?? false);

        $requiredTypes = $this->requiredTypesByRequirement($assessment['requirements']);
        $criticality = $this->domainCriticalityByUuid($assessment['requirements']);

        $recommendations = [];
        foreach ($assessment['requirements'] as $finding) {
            $reqId = (int) ($finding['_ids']['requirement_id'] ?? 0);
            $spec = $this->rules->forFinding($finding, $requiredTypes[$reqId] ?? [], $includeCompliant);
            if ($spec === null) {
                continue;
            }

            $domainUuid = $finding['domain']['uuid'] ?? null;
            $priority = $this->priority->priorityFor($finding, $domainUuid === null ? null : ($criticality[$domainUuid] ?? null));

            $recommendations[] = $this->buildNode($finding, $spec, $priority);
        }

        return array_merge(
            $this->head($assessment),
            [
                'summary' => $this->summary->summarize($this->stripIdsList($recommendations)),
                'recommendations' => $recommendations,
                'generated_at' => now()->toIso8601String(),
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function generateForControl(?string $frameworkKey, ?string $releaseCode, int $projectId, string $controlCode, array $options = []): array
    {
        $result = $this->generate($frameworkKey, $releaseCode, $projectId, $options);
        $result['recommendations'] = array_values(array_filter(
            $result['recommendations'],
            fn ($r) => isset($r['control']['code']) && strcasecmp((string) $r['control']['code'], $controlCode) === 0,
        ));
        $result['summary'] = $this->summary->summarize($this->stripIdsList($result['recommendations']));

        return $this->toPublic($result);
    }

    /**
     * @return array<string, mixed>
     */
    public function generateForRequirement(?string $frameworkKey, ?string $releaseCode, int $projectId, string $requirementCode, array $options = []): array
    {
        $result = $this->generate($frameworkKey, $releaseCode, $projectId, $options);
        $result['recommendations'] = array_values(array_filter(
            $result['recommendations'],
            fn ($r) => isset($r['requirement']['code']) && strcasecmp((string) $r['requirement']['code'], $requirementCode) === 0,
        ));
        $result['summary'] = $this->summary->summarize($this->stripIdsList($result['recommendations']));

        return $this->toPublic($result);
    }

    /**
     * Persist the generated recommendations as immutable, append-only rows. Idempotent: rows with
     * an already-existing deterministic UUID are left untouched (never updated). Returns the public
     * result plus persistence counts.
     *
     * @return array<string, mixed>
     */
    public function persist(?string $frameworkKey, ?string $releaseCode, int $projectId, ?int $userId = null, array $options = []): array
    {
        $result = $this->generate($frameworkKey, $releaseCode, $projectId, $options);
        [$release, $revision] = $this->gap->resolveContext($frameworkKey, $releaseCode);

        $created = 0;
        $existing = 0;

        DB::transaction(function () use ($result, $release, $revision, $projectId, &$created, &$existing): void {
            foreach ($result['recommendations'] as $rec) {
                if (ComplianceRecommendation::query()->where('uuid', $rec['uuid'])->exists()) {
                    $existing++;

                    continue;
                }

                $model = ComplianceRecommendation::create([
                    'uuid' => $rec['uuid'],
                    'project_id' => $projectId,
                    'framework_release_id' => $release?->id,
                    'corpus_revision_id' => $revision?->id,
                    'gap_assessment_id' => null,
                    'gap_finding_id' => null,
                    'requirement_id' => $rec['_ids']['requirement_id'],
                    'control_id' => $rec['_ids']['control_id'],
                    'domain_id' => $rec['_ids']['domain_id'],
                    'recommendation_type' => $rec['recommendation_type'],
                    'priority' => $rec['priority'],
                    'status' => ComplianceRecommendationStatus::Proposed->value,
                    'title_en' => $rec['title_en'],
                    'title_ar' => $rec['title_ar'],
                    'description_en' => $rec['description_en'],
                    'description_ar' => $rec['description_ar'],
                    'rationale_en' => $rec['rationale_en'],
                    'rationale_ar' => $rec['rationale_ar'],
                    'action_items' => $rec['action_items'],
                    'source_rule' => $rec['source_rule'],
                    'metadata' => [
                        'gap_status' => $rec['gap_status'],
                        'priority_basis_en' => $rec['priority_basis_en'],
                        'priority_basis_ar' => $rec['priority_basis_ar'],
                        'evaluation_rule' => $rec['evaluation_rule'],
                    ],
                ]);

                $sort = 0;
                foreach ($rec['action_items'] as $action) {
                    ComplianceRecommendationAction::create([
                        'uuid' => $this->deterministicUuid($rec['uuid'].':action:'.$action['action_key']),
                        'recommendation_id' => $model->id,
                        'action_key' => $action['action_key'],
                        'label_en' => $action['label_en'],
                        'label_ar' => $action['label_ar'],
                        'sort_order' => $sort++,
                    ]);
                }

                $created++;
            }
        });

        $public = $this->toPublic($result);
        $public['persistence'] = ['created' => $created, 'existing' => $existing, 'total' => $created + $existing];

        return $public;
    }

    /**
     * Remove internal persistence-only id maps so API responses stay strictly UUID-only.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    public function toPublic(array $result): array
    {
        if (isset($result['recommendations']) && is_array($result['recommendations'])) {
            $result['recommendations'] = $this->stripIdsList($result['recommendations']);
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Node construction
    // -------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $finding
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>  $priority
     * @return array<string, mixed>
     */
    private function buildNode(array $finding, array $spec, array $priority): array
    {
        /** @var ComplianceRecommendationPriority $priorityEnum */
        $priorityEnum = $priority['priority'];
        $requirementUuid = (string) ($finding['requirement']['uuid'] ?? '');
        $revisionUuid = (string) ($finding['revision_uuid'] ?? '');

        $uuid = $this->deterministicUuid('recommendation:'.$requirementUuid.':'.$spec['source_rule'].':'.$revisionUuid);

        return [
            'uuid' => $uuid,
            'requirement' => $finding['requirement'],
            'control' => $finding['control'],
            'domain' => $finding['domain'],
            'gap_status' => $finding['status'],
            'gap_status_label_en' => $finding['status_label_en'] ?? null,
            'gap_status_label_ar' => $finding['status_label_ar'] ?? null,
            'recommendation_type' => $spec['recommendation_type'],
            'priority' => $priorityEnum->value,
            'priority_label_en' => $priorityEnum->labelEn(),
            'priority_label_ar' => $priorityEnum->labelAr(),
            'priority_basis_en' => $priority['basis_en'],
            'priority_basis_ar' => $priority['basis_ar'],
            'status' => ComplianceRecommendationStatus::Proposed->value,
            'title_en' => $spec['title_en'],
            'title_ar' => $spec['title_ar'],
            'description_en' => $spec['description_en'],
            'description_ar' => $spec['description_ar'],
            'rationale_en' => $spec['rationale_en'],
            'rationale_ar' => $spec['rationale_ar'],
            'action_items' => $spec['action_items'],
            'evidence_considered' => $finding['evidence_considered'] ?? [],
            'source_rule' => $spec['source_rule'],
            'evaluation_rule' => $finding['evaluation_rule'] ?? null,
            'revision_uuid' => $finding['revision_uuid'] ?? null,
            'framework_release' => $finding['framework_release'] ?? null,
            '_ids' => $finding['_ids'] ?? null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $findings
     * @return array<int, list<array<string, mixed>>>
     */
    private function requiredTypesByRequirement(array $findings): array
    {
        $reqIds = array_values(array_filter(array_map(
            fn ($f) => $f['_ids']['requirement_id'] ?? null,
            $findings,
        )));

        if ($reqIds === []) {
            return [];
        }

        $map = [];
        ComplianceEvidenceExpectation::query()
            ->whereIn('requirement_id', $reqIds)
            ->where('is_required', true)
            ->with('evidenceType')
            ->get()
            ->each(function (ComplianceEvidenceExpectation $expectation) use (&$map): void {
                $type = $expectation->evidenceType;
                if ($type === null) {
                    return;
                }
                $map[$expectation->requirement_id][] = [
                    'uuid' => $type->uuid,
                    'key' => $type->key,
                    'title_en' => $type->title_en,
                    'title_ar' => $type->title_ar,
                ];
            });

        return $map;
    }

    /**
     * @param  list<array<string, mixed>>  $findings
     * @return array<string, string|null>
     */
    private function domainCriticalityByUuid(array $findings): array
    {
        $domainUuids = array_values(array_filter(array_map(
            fn ($f) => $f['domain']['uuid'] ?? null,
            $findings,
        )));

        if ($domainUuids === []) {
            return [];
        }

        $map = [];
        ComplianceDomain::query()
            ->whereIn('uuid', array_unique($domainUuids))
            ->get(['uuid', 'metadata'])
            ->each(function (ComplianceDomain $domain) use (&$map): void {
                $criticality = is_array($domain->metadata) ? ($domain->metadata['criticality'] ?? null) : null;
                $map[$domain->uuid] = is_string($criticality) ? $criticality : null;
            });

        return $map;
    }

    /**
     * @param  list<array<string, mixed>>  $recommendations
     * @return list<array<string, mixed>>
     */
    private function stripIdsList(array $recommendations): array
    {
        return array_map(function ($rec) {
            unset($rec['_ids']);

            return $rec;
        }, $recommendations);
    }

    /**
     * @param  array<string, mixed>  $assessment
     * @return array<string, mixed>
     */
    private function head(array $assessment): array
    {
        return [
            'context_type' => 'recommendations',
            'framework' => $assessment['framework'] ?? null,
            'release' => $assessment['release'] ?? null,
            'revision' => $assessment['revision'] ?? null,
        ];
    }

    private function deterministicUuid(string $name): string
    {
        return (string) Uuid::uuid5(self::namespaceUuid(), $name);
    }

    private static function namespaceUuid(): string
    {
        // Derive a valid namespace UUID deterministically from our (intentionally non-UUID) seed,
        // so we never depend on a hand-written UUID literal being well-formed.
        return (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'quenyx:qcif:'.self::UUID_NAMESPACE);
    }
}
