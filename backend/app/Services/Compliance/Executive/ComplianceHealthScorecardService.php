<?php

namespace App\Services\Compliance\Executive;

use App\Enums\Compliance\Gap\ComplianceGapSeverity;
use App\Enums\Compliance\Gap\ComplianceGapStatus;
use App\Models\Compliance\Gap\ComplianceGapAssessment;
use App\Services\Compliance\Gap\GapAssessmentService;
use App\Services\Compliance\Recommendation\RecommendationGenerationService;

/**
 * Deterministic compliance health scorecard (QCIF Sprint 18).
 *
 * It does NOT invent percentages or scores. It exposes the discrete, rule-derived requirement counts
 * already produced by the Gap Assessment Engine (compliant, partially compliant, no evidence,
 * expired, rejected, pending) plus the Recommendation Engine totals, and an optional trend built
 * from the append-only gap-assessment history. Pure counting — no AI.
 */
class ComplianceHealthScorecardService
{
    public function __construct(
        private readonly GapAssessmentService $gap,
        private readonly RecommendationGenerationService $recommendations,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function scorecard(?string $frameworkKey, ?string $releaseCode, int $projectId): array
    {
        $assessment = $this->gap->assess($frameworkKey, $releaseCode, $projectId);
        $recommendationSummary = $this->recommendations->generate($frameworkKey, $releaseCode, $projectId)['summary'] ?? [];

        return $this->fromAssessment($assessment, $recommendationSummary, $projectId);
    }

    /**
     * Build the scorecard from an already-computed assessment + recommendation summary (so the
     * dashboard does not re-run the gap engine).
     *
     * @param  array<string, mixed>  $assessment
     * @param  array<string, mixed>  $recommendationSummary
     * @return array<string, mixed>
     */
    public function fromAssessment(array $assessment, array $recommendationSummary, int $projectId): array
    {
        $summary = $assessment['summary'] ?? [];
        $totals = $summary['totals'] ?? [];
        $byStatus = $totals['by_status'] ?? [];
        $bySeverity = $totals['by_severity'] ?? [];
        $recoTotals = $recommendationSummary['totals'] ?? [];

        $status = fn (ComplianceGapStatus $s): int => (int) ($byStatus[$s->value] ?? 0);

        $cards = [
            'compliant_requirements' => $status(ComplianceGapStatus::Compliant),
            'partially_compliant' => $status(ComplianceGapStatus::PartiallyCompliant),
            'non_compliant' => $status(ComplianceGapStatus::NonCompliant),
            'no_evidence' => $status(ComplianceGapStatus::NoEvidence),
            'evidence_expired' => $status(ComplianceGapStatus::EvidenceExpired),
            'evidence_rejected' => $status(ComplianceGapStatus::EvidenceRejected),
            'evidence_pending' => $status(ComplianceGapStatus::EvidencePendingValidation),
            'not_assessed' => $status(ComplianceGapStatus::NotAssessed),
            'recommendations' => (int) ($recoTotals['recommendations'] ?? 0),
        ];

        return [
            'context_type' => 'compliance_scorecard',
            'framework' => $assessment['framework'] ?? null,
            'release' => $assessment['release'] ?? null,
            'revision' => $assessment['revision'] ?? null,
            'totals' => [
                'requirements' => (int) ($totals['requirements'] ?? 0),
                'satisfied' => (int) ($totals['satisfied'] ?? 0),
                'gaps' => (int) ($totals['gaps'] ?? 0),
            ],
            'cards' => $cards,
            'by_status' => $this->labelled($byStatus, ComplianceGapStatus::cases()),
            'by_severity' => $bySeverity,
            'by_recommendation_priority' => $recoTotals['by_priority'] ?? [],
            'workspace_status' => $summary['workspace_status'] ?? ComplianceGapStatus::NotAssessed->value,
            'framework_status' => $summary['framework_status'] ?? null,
            'trend' => $this->trend($projectId, $assessment),
            'generated_at' => $assessment['generated_at'] ?? now()->toIso8601String(),
        ];
    }

    /**
     * Build a deterministic trend from the append-only gap-assessment history (where it exists).
     *
     * @param  array<string, mixed>  $assessment
     * @return array<string, mixed>
     */
    private function trend(int $projectId, array $assessment): array
    {
        $window = (int) config('compliance.executive.trend_window', 12);

        try {
            $history = ComplianceGapAssessment::query()
                ->where('project_id', $projectId)
                ->orderByDesc('assessed_at')
                ->limit(max(1, $window))
                ->get(['uuid', 'assessed_at', 'summary', 'framework_release_id']);
        } catch (\Throwable $e) {
            return ['available' => false, 'points' => []];
        }

        if ($history->isEmpty()) {
            return ['available' => false, 'points' => []];
        }

        $points = $history->reverse()->values()->map(function (ComplianceGapAssessment $a): array {
            $totals = (array) (($a->summary['totals'] ?? []));

            return [
                'assessment_uuid' => $a->uuid,
                'assessed_at' => $a->assessed_at?->toIso8601String(),
                'requirements' => (int) ($totals['requirements'] ?? 0),
                'satisfied' => (int) ($totals['satisfied'] ?? 0),
                'gaps' => (int) ($totals['gaps'] ?? 0),
            ];
        })->all();

        return [
            'available' => true,
            'source' => 'gap_assessment_history',
            'points' => $points,
        ];
    }

    /**
     * @param  array<string, int>  $counts
     * @param  list<ComplianceGapStatus|ComplianceGapSeverity>  $cases
     * @return list<array<string, mixed>>
     */
    private function labelled(array $counts, array $cases): array
    {
        $out = [];
        foreach ($cases as $case) {
            $out[] = [
                'key' => $case->value,
                'label_en' => method_exists($case, 'labelEn') ? $case->labelEn() : $case->value,
                'label_ar' => method_exists($case, 'labelAr') ? $case->labelAr() : $case->value,
                'count' => (int) ($counts[$case->value] ?? 0),
            ];
        }

        return $out;
    }
}
