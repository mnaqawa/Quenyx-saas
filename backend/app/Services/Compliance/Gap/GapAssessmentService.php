<?php

namespace App\Services\Compliance\Gap;

use App\Enums\Compliance\CorpusRevisionStatus;
use App\Enums\Compliance\Evidence\ComplianceEvidenceStatus;
use App\Enums\Compliance\Gap\ComplianceGapStatus;
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
use App\Models\Compliance\Evidence\ComplianceEvidence;
use App\Models\Compliance\Gap\ComplianceCoverageSnapshot;
use App\Models\Compliance\Gap\ComplianceGapAssessment;
use App\Models\Compliance\Gap\ComplianceGapFinding;
use App\Services\Compliance\ComplianceCorpusQueryService;
use App\Services\Compliance\Evidence\EvidenceValidationService;
use Illuminate\Support\Facades\DB;

/**
 * The first deterministic Compliance Intelligence Engine (QCIF Sprint 12).
 *
 * Orchestrates a Gap Assessment for a workspace against one framework release + active corpus
 * revision by composing the correlation engine, the evaluation engine, coverage aggregation, and
 * the summary builder. Every result is reproducible and fully explainable; it references the
 * requirement, the evidence, the evaluation rule, the corpus revision, and the framework release.
 *
 * NO AI, NO LLM, NO provider calls, NO probabilistic scoring. Empty/missing data yields explicit
 * "no evidence" / "not assessed" states — it never fabricates evidence or gaps.
 */
class GapAssessmentService
{
    public function __construct(
        private readonly EvidenceCorrelationService $correlation = new EvidenceCorrelationService(),
        private readonly GapEvaluationService $evaluation = new GapEvaluationService(),
        private readonly GapCoverageService $coverage = new GapCoverageService(),
        private readonly GapSummaryService $summary = new GapSummaryService(),
        private readonly EvidenceValidationService $validation = new EvidenceValidationService(),
        private readonly ComplianceCorpusQueryService $queryService = new ComplianceCorpusQueryService(),
    ) {}

    /**
     * Run a full deterministic gap assessment and return the in-memory result (UUID-only). This
     * does NOT persist anything — read endpoints use this; persistence is via createAssessment().
     *
     * @return array<string, mixed>
     */
    public function assess(?string $frameworkKey, ?string $releaseCode, int $projectId): array
    {
        [$release, $revision, $framework] = $this->resolveContext($frameworkKey, $releaseCode);
        if ($release === null || $revision === null) {
            throw new ComplianceCorpusNotFoundException(
                'Unable to resolve a single active framework release for gap assessment; specify framework and release.'
            );
        }

        $index = $this->correlation->buildIndex($projectId);

        $requirements = ComplianceRequirement::query()
            ->where('framework_release_id', $release->id)
            ->with(['control.domain', 'evidenceExpectations'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $findings = [];
        foreach ($requirements as $requirement) {
            $findings[] = $this->buildFinding($requirement, $index, $release, $revision);
        }

        $frameworkNode = $framework === null ? null : [
            'uuid' => $framework->uuid,
            'code' => $framework->key,
            'title_en' => $framework->title_en,
            'title_ar' => $framework->title_ar,
        ];

        $coverage = $this->coverage->aggregate($findings, $frameworkNode);
        $summary = $this->summary->summarize($findings, $coverage);

        return array_merge(
            $this->head($framework, $release, $revision),
            [
                'workspace' => ['scope' => 'workspace'],
                'summary' => $summary,
                'coverage' => $coverage,
                'requirements' => $findings,
                'correlation' => ['counts' => $index['counts']],
                'generated_at' => now()->toIso8601String(),
            ],
        );
    }

    /**
     * Slice a full assessment down to a single control (its requirement findings + coverage).
     *
     * @return array<string, mixed>
     */
    public function assessControl(?string $frameworkKey, ?string $releaseCode, int $projectId, string $controlCode): array
    {
        $assessment = $this->assess($frameworkKey, $releaseCode, $projectId);

        $findings = array_values(array_map(
            fn ($f) => $this->stripIds($f),
            array_filter(
                $assessment['requirements'],
                fn ($f) => isset($f['control']['code']) && $this->codeMatches((string) $f['control']['code'], $controlCode),
            ),
        ));

        $controlCoverage = null;
        foreach ($assessment['coverage']['controls'] as $node) {
            if (isset($node['code']) && $this->codeMatches((string) $node['code'], $controlCode)) {
                $controlCoverage = $node;
                break;
            }
        }

        if ($findings === [] && $controlCoverage === null) {
            throw new ComplianceCorpusNotFoundException("Control not found in assessment: {$controlCode}.");
        }

        return array_merge(
            $this->onlyHead($assessment),
            [
                'control' => $controlCoverage,
                'requirements' => $findings,
                'generated_at' => $assessment['generated_at'],
            ],
        );
    }

    /**
     * Slice a full assessment down to a single requirement finding (full explainability).
     *
     * @return array<string, mixed>
     */
    public function assessRequirement(?string $frameworkKey, ?string $releaseCode, int $projectId, string $requirementCode): array
    {
        $assessment = $this->assess($frameworkKey, $releaseCode, $projectId);

        foreach ($assessment['requirements'] as $finding) {
            if (isset($finding['requirement']['code']) && $this->codeMatches((string) $finding['requirement']['code'], $requirementCode)) {
                return array_merge($this->onlyHead($assessment), ['finding' => $this->stripIds($finding), 'generated_at' => $assessment['generated_at']]);
            }
        }

        throw new ComplianceCorpusNotFoundException("Requirement not found in assessment: {$requirementCode}.");
    }

    /**
     * Persist an immutable assessment snapshot (assessment + findings + coverage snapshots).
     * Provided for scheduled/triggered runs; the read API never calls this (reads are side-effect
     * free). History is append-only — the models reject updates/deletes.
     */
    public function createAssessment(?string $frameworkKey, ?string $releaseCode, int $projectId, ?int $userId = null): ComplianceGapAssessment
    {
        $result = $this->assess($frameworkKey, $releaseCode, $projectId);
        [$release, $revision, $framework] = $this->resolveContext($frameworkKey, $releaseCode);

        return DB::transaction(function () use ($result, $release, $revision, $framework, $projectId, $userId) {
            $assessment = ComplianceGapAssessment::create([
                'project_id' => $projectId,
                'framework_id' => $framework?->id,
                'framework_release_id' => $release?->id,
                'corpus_revision_id' => $revision?->id,
                'assessed_at' => now(),
                'summary' => $result['summary'],
                'metadata' => ['correlation' => $result['correlation']],
                'created_by' => $userId,
            ]);

            foreach ($result['requirements'] as $finding) {
                ComplianceGapFinding::create([
                    'gap_assessment_id' => $assessment->id,
                    'requirement_id' => $finding['_ids']['requirement_id'],
                    'requirement_uuid' => $finding['requirement']['uuid'],
                    'control_id' => $finding['_ids']['control_id'],
                    'control_uuid' => $finding['control']['uuid'] ?? null,
                    'domain_id' => $finding['_ids']['domain_id'],
                    'domain_uuid' => $finding['domain']['uuid'] ?? null,
                    'framework_release_id' => $release?->id,
                    'corpus_revision_id' => $revision?->id,
                    'corpus_revision_uuid' => $revision?->uuid,
                    'status' => $finding['status'],
                    'severity' => $finding['severity'],
                    'evaluation_rule' => $finding['evaluation_rule'],
                    'reason' => $finding['reason'],
                    'evidence_considered' => $finding['evidence_considered'],
                    'evidence_ignored' => $finding['evidence_ignored'],
                ]);
            }

            $this->persistCoverage($assessment, $result['coverage'], $release, $revision);

            return $assessment;
        });
    }

    /**
     * Effective (frameworkKey, releaseCode) for caching. Explicit params win; otherwise the single
     * release that currently has an active revision. Null when none/ambiguous.
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
     * A lightweight fingerprint of workspace evidence so a revision-aware cache also invalidates
     * when tenant evidence changes (count + latest mutation). Deterministic, cheap.
     */
    public function evidenceFingerprint(int $projectId): string
    {
        $count = ComplianceEvidence::query()->where('project_id', $projectId)->count();
        $latest = ComplianceEvidence::query()->where('project_id', $projectId)->max('updated_at');

        return md5($projectId.'|'.$count.'|'.(string) $latest);
    }

    // -------------------------------------------------------------------------
    // Finding construction
    // -------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $index
     * @return array<string, mixed>
     */
    private function buildFinding(
        ComplianceRequirement $requirement,
        array $index,
        ComplianceFrameworkRelease $release,
        ComplianceCorpusRevision $revision,
    ): array {
        $control = $requirement->control;
        $domain = $control?->domain;

        $applicable = $this->correlation->applicableEvidenceForRequirement($index, $requirement->id, $control?->id);

        $descriptors = [];
        foreach ($applicable as $item) {
            $descriptors[] = $this->descriptor($item['evidence'], $item['origin']);
        }

        $expectations = $requirement->evidenceExpectations->map(fn ($e) => [
            'is_required' => (bool) $e->is_required,
            'evidence_type_id' => $e->evidence_type_id,
            'code' => $e->code,
        ])->all();

        $decision = $this->evaluation->evaluate($descriptors, $expectations);
        /** @var ComplianceGapStatus $status */
        $status = $decision['status'];

        return [
            'requirement' => $this->requirementNode($requirement),
            'control' => $control === null ? null : $this->controlNode($control),
            'domain' => $domain === null ? null : $this->domainNode($domain),
            'status' => $status->value,
            'status_label_en' => $status->labelEn(),
            'status_label_ar' => $status->labelAr(),
            'severity' => $decision['severity']->value,
            'evaluation_rule' => $decision['evaluation_rule'],
            'reason' => $decision['reason'],
            'evidence_considered' => $decision['evidence_considered'],
            'evidence_ignored' => $decision['evidence_ignored'],
            'revision_uuid' => $revision->uuid,
            'framework_release' => [
                'uuid' => $release->uuid,
                'version_code' => $release->version_code,
            ],
            // Internal id map for persistence only — stripped from API output before sending.
            '_ids' => [
                'requirement_id' => $requirement->id,
                'control_id' => $control?->id,
                'domain_id' => $domain?->id,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function descriptor(ComplianceEvidence $evidence, string $origin): array
    {
        return [
            'uuid' => $evidence->uuid,
            'status' => $evidence->status?->value,
            'classification' => $this->classify($evidence),
            'origin' => $origin,
            'evidence_type_id' => $evidence->evidence_type_id,
        ];
    }

    /**
     * Deterministic classification used by the evaluation engine.
     */
    private function classify(ComplianceEvidence $evidence): string
    {
        if ($this->validation->isExpired($evidence)) {
            return 'expired';
        }

        return match ($evidence->status) {
            ComplianceEvidenceStatus::Approved => 'approved_valid',
            ComplianceEvidenceStatus::Rejected => 'rejected',
            ComplianceEvidenceStatus::Archived => 'archived',
            ComplianceEvidenceStatus::Registered,
            ComplianceEvidenceStatus::Collected,
            ComplianceEvidenceStatus::Validated => 'pending',
            default => 'pending',
        };
    }

    // -------------------------------------------------------------------------
    // Context resolution (mirrors the mapping foundation)
    // -------------------------------------------------------------------------

    /**
     * @return array{0: ?ComplianceFrameworkRelease, 1: ?ComplianceCorpusRevision, 2: ?ComplianceFramework}
     */
    public function resolveContext(?string $frameworkKey, ?string $releaseCode): array
    {
        if ($frameworkKey !== null && $frameworkKey !== '' && $releaseCode !== null && $releaseCode !== '') {
            $release = $this->queryService->resolveRelease($frameworkKey, $releaseCode);
            $revision = $this->safeActiveRevision($release);

            return [$release, $revision, $release->framework];
        }

        return $this->resolvePrimary();
    }

    /**
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

        $release = ComplianceFrameworkRelease::query()->with('framework')->whereKey($releaseIds->first())->first();
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

    // -------------------------------------------------------------------------
    // Node builders (UUID-only)
    // -------------------------------------------------------------------------

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
            'title_en' => $requirement->title_en,
            'title_ar' => $requirement->title_ar,
            'provenance' => [
                'source_reference' => $requirement->source_reference,
                'source_page' => $requirement->source_page,
                'official_reference' => $requirement->official_reference,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function controlNode(ComplianceControl $control): array
    {
        return [
            'entity_type' => 'control',
            'uuid' => $control->uuid,
            'code' => $control->code,
            'display_code' => $control->display_code,
            'title_en' => $control->title_en,
            'title_ar' => $control->title_ar,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function domainNode(ComplianceDomain $domain): array
    {
        return [
            'entity_type' => 'domain',
            'uuid' => $domain->uuid,
            'code' => $domain->code,
            'title_en' => $domain->title_en,
            'title_ar' => $domain->title_ar,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function head(?ComplianceFramework $framework, ?ComplianceFrameworkRelease $release, ?ComplianceCorpusRevision $revision): array
    {
        return [
            'context_type' => 'gap_assessment',
            'framework' => $framework === null ? null : ComplianceFrameworkResource::make($framework)->resolve(),
            'release' => $release === null ? null : ComplianceFrameworkReleaseResource::make($release)->resolve(),
            'revision' => $revision === null ? null : ComplianceCorpusRevisionResource::make($revision)->resolve(),
        ];
    }

    /**
     * @param  array<string, mixed>  $assessment
     * @return array<string, mixed>
     */
    private function onlyHead(array $assessment): array
    {
        return [
            'context_type' => $assessment['context_type'],
            'framework' => $assessment['framework'],
            'release' => $assessment['release'],
            'revision' => $assessment['revision'],
        ];
    }

    private function codeMatches(string $candidate, string $wanted): bool
    {
        return strcasecmp($candidate, $wanted) === 0;
    }

    /**
     * Remove the internal persistence-only id map from a public-facing assessment result so API
     * responses stay strictly UUID-only.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    public function toPublic(array $result): array
    {
        if (isset($result['requirements']) && is_array($result['requirements'])) {
            $result['requirements'] = array_map(fn ($f) => $this->stripIds($f), $result['requirements']);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $finding
     * @return array<string, mixed>
     */
    private function stripIds(array $finding): array
    {
        unset($finding['_ids']);

        return $finding;
    }

    /**
     * @param  array<string, mixed>  $coverage
     */
    private function persistCoverage(
        ComplianceGapAssessment $assessment,
        array $coverage,
        ?ComplianceFrameworkRelease $release,
        ?ComplianceCorpusRevision $revision,
    ): void {
        $rows = [];
        $rows[] = ['scope' => 'workspace', 'node' => $coverage['workspace']];
        if ($coverage['framework'] !== null) {
            $rows[] = ['scope' => 'framework', 'node' => $coverage['framework']];
        }
        foreach ($coverage['domains'] as $node) {
            $rows[] = ['scope' => 'domain', 'node' => $node];
        }
        foreach ($coverage['controls'] as $node) {
            $rows[] = ['scope' => 'control', 'node' => $node];
        }

        foreach ($rows as $row) {
            ComplianceCoverageSnapshot::create([
                'gap_assessment_id' => $assessment->id,
                'scope_type' => $row['scope'],
                'scope_uuid' => $row['node']['uuid'] ?? null,
                'scope_code' => $row['node']['code'] ?? null,
                'status' => $row['node']['status'],
                'totals' => $row['node']['totals'] ?? null,
                'framework_release_id' => $release?->id,
                'corpus_revision_id' => $revision?->id,
            ]);
        }
    }
}
