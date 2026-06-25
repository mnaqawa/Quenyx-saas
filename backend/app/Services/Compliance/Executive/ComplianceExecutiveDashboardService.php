<?php

namespace App\Services\Compliance\Executive;

use App\Enums\Compliance\Evidence\ComplianceEvidenceStatus;
use App\Models\Compliance\ComplianceControl;
use App\Models\Compliance\ComplianceDomain;
use App\Models\Compliance\ComplianceRequirement;
use App\Models\Compliance\ComplianceSourceDocument;
use App\Models\Compliance\Evidence\ComplianceEvidence;
use App\Services\Compliance\Gap\GapAssessmentService;
use App\Services\Compliance\Recommendation\RecommendationGenerationService;

/**
 * Executive dashboard aggregation (QCIF Sprint 18) — read-only, deterministic, real data.
 *
 * It composes a single executive view from the existing engines: framework + domain health and gap
 * distribution from the Gap Assessment Engine, remediation priorities from the Recommendation
 * Engine, the deterministic scorecard, corpus statistics + active revision info, evidence coverage,
 * and a recent-activity feed from the timeline. NO new intelligence, NO fabricated values, NO AI.
 */
class ComplianceExecutiveDashboardService
{
    public function __construct(
        private readonly GapAssessmentService $gap,
        private readonly RecommendationGenerationService $recommendations,
        private readonly ComplianceHealthScorecardService $scorecard,
        private readonly ComplianceExecutiveTimelineService $timeline,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function dashboard(?string $frameworkKey, ?string $releaseCode, int $projectId): array
    {
        [$release, $revision, $framework] = $this->gap->resolveContext($frameworkKey, $releaseCode);
        $fwKey = $framework?->key ?? $frameworkKey;
        $relCode = $release?->version_code ?? $releaseCode;

        $assessment = $this->gap->assess($fwKey, $relCode, $projectId);
        $recoResult = $this->recommendations->generate($fwKey, $relCode, $projectId);
        $recoSummary = $recoResult['summary'] ?? [];

        $coverage = $assessment['coverage'] ?? [];
        $summary = $assessment['summary'] ?? [];
        $totals = $summary['totals'] ?? [];

        $releaseId = $release?->id;

        return [
            'context_type' => 'executive_dashboard',
            'framework' => $assessment['framework'] ?? null,
            'release' => $assessment['release'] ?? null,
            'revision' => $assessment['revision'] ?? null,
            'framework_health' => $coverage['framework'] ?? null,
            'workspace_health' => $coverage['workspace'] ?? null,
            'domain_health' => array_values($coverage['domains'] ?? []),
            'gap_distribution' => [
                'by_status' => $totals['by_status'] ?? [],
                'by_severity' => $totals['by_severity'] ?? [],
                'totals' => [
                    'requirements' => (int) ($totals['requirements'] ?? 0),
                    'satisfied' => (int) ($totals['satisfied'] ?? 0),
                    'gaps' => (int) ($totals['gaps'] ?? 0),
                ],
            ],
            'recommendation_priorities' => $recoSummary['totals'] ?? [],
            'scorecard' => $this->scorecard->fromAssessment($assessment, $recoSummary, $projectId),
            'evidence_coverage' => $this->evidenceCoverage($projectId, $assessment),
            'corpus_statistics' => $this->corpusStatistics($releaseId),
            'revision_information' => $this->revisionInformation($revision),
            'recent_activity' => $this->timeline->timeline($fwKey, $relCode, $projectId, 15)['events'] ?? [],
            'determinism' => ['ai_used' => false, 'fabricated_data' => false],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $assessment
     * @return array<string, mixed>
     */
    private function evidenceCoverage(int $projectId, array $assessment): array
    {
        $byStatus = array_fill_keys(ComplianceEvidenceStatus::values(), 0);

        ComplianceEvidence::query()
            ->where('project_id', $projectId)
            ->get(['status'])
            ->each(function (ComplianceEvidence $e) use (&$byStatus): void {
                $value = $e->status?->value;
                if ($value !== null) {
                    $byStatus[$value] = ($byStatus[$value] ?? 0) + 1;
                }
            });

        return [
            'evidence_by_status' => $byStatus,
            'total_evidence' => array_sum($byStatus),
            'correlation' => $assessment['correlation']['counts'] ?? [],
        ];
    }

    /**
     * @return array<string, int>
     */
    private function corpusStatistics(?int $releaseId): array
    {
        if ($releaseId === null) {
            return ['domains' => 0, 'controls' => 0, 'requirements' => 0, 'source_documents' => 0];
        }

        return [
            'domains' => ComplianceDomain::query()->where('framework_release_id', $releaseId)->count(),
            'controls' => ComplianceControl::query()->where('framework_release_id', $releaseId)->count(),
            'requirements' => ComplianceRequirement::query()->where('framework_release_id', $releaseId)->count(),
            'source_documents' => ComplianceSourceDocument::query()->where('framework_release_id', $releaseId)->count(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function revisionInformation(mixed $revision): ?array
    {
        if ($revision === null) {
            return null;
        }

        return [
            'uuid' => $revision->uuid,
            'revision_number' => $revision->revision_number,
            'status' => $revision->status?->value,
            'activated_at' => $revision->activated_at?->toIso8601String(),
            'checksum_sha256' => $revision->checksum_sha256,
            'entity_counts' => $revision->entity_counts,
        ];
    }
}
