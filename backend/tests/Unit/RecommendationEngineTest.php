<?php

namespace Tests\Unit;

use App\Enums\Compliance\Gap\ComplianceGapSeverity;
use App\Enums\Compliance\Gap\ComplianceGapStatus;
use App\Enums\Compliance\Recommendation\ComplianceRecommendationPriority;
use App\Services\Compliance\Recommendation\RecommendationPrioritizationService;
use App\Services\Compliance\Recommendation\RecommendationRuleService;
use App\Services\Compliance\Recommendation\RecommendationSummaryService;
use Tests\TestCase;

/**
 * DB-free, AI-free unit tests for the Recommendation Engine (Sprint 13): the deterministic rule
 * mapping, the priority derivation, and the summary counts. No database or AI provider is touched.
 */
class RecommendationEngineTest extends TestCase
{
    private function finding(ComplianceGapStatus $status, ComplianceGapSeverity $severity, string $code = 'R-1', string $controlCode = 'C-1', ?string $domainUuid = 'd-1'): array
    {
        return [
            'status' => $status->value,
            'status_label_en' => $status->labelEn(),
            'status_label_ar' => $status->labelAr(),
            'severity' => $severity->value,
            'evaluation_rule' => 'some_gap_rule',
            'requirement' => ['uuid' => 'req-uuid', 'code' => $code],
            'control' => ['uuid' => 'ctl-uuid', 'code' => $controlCode],
            'domain' => ['uuid' => $domainUuid],
            'evidence_considered' => [],
            'revision_uuid' => 'rev-uuid',
            'framework_release' => ['uuid' => 'rel-uuid', 'version_code' => 'v1'],
        ];
    }

    // ---- Rule mapping -----------------------------------------------------

    public function test_no_evidence_maps_to_collect_evidence_with_three_actions(): void
    {
        $spec = (new RecommendationRuleService())->forFinding(
            $this->finding(ComplianceGapStatus::NoEvidence, ComplianceGapSeverity::High),
        );

        $this->assertNotNull($spec);
        $this->assertSame(RecommendationRuleService::TYPE_COLLECT_EVIDENCE, $spec['recommendation_type']);
        $this->assertSame('gap.no_evidence', $spec['source_rule']);
        $this->assertCount(3, $spec['action_items']);
    }

    public function test_no_evidence_names_required_type_when_known(): void
    {
        $spec = (new RecommendationRuleService())->forFinding(
            $this->finding(ComplianceGapStatus::NoEvidence, ComplianceGapSeverity::High),
            [['title_en' => 'Policy Document', 'title_ar' => 'وثيقة سياسة']],
        );

        $this->assertStringContainsString('Policy Document', $spec['action_items'][0]['label_en']);
    }

    public function test_each_gap_status_maps_to_expected_type(): void
    {
        $rules = new RecommendationRuleService();
        $cases = [
            [ComplianceGapStatus::EvidencePendingValidation, RecommendationRuleService::TYPE_VALIDATE_EVIDENCE],
            [ComplianceGapStatus::EvidenceExpired, RecommendationRuleService::TYPE_REFRESH_EVIDENCE],
            [ComplianceGapStatus::EvidenceRejected, RecommendationRuleService::TYPE_REPLACE_EVIDENCE],
            [ComplianceGapStatus::PartiallyCompliant, RecommendationRuleService::TYPE_COMPLETE_COVERAGE],
            [ComplianceGapStatus::NonCompliant, RecommendationRuleService::TYPE_COMPLETE_COVERAGE],
            [ComplianceGapStatus::Unknown, RecommendationRuleService::TYPE_MANUAL_REVIEW],
        ];

        foreach ($cases as [$status, $type]) {
            $spec = $rules->forFinding($this->finding($status, ComplianceGapSeverity::Medium));
            $this->assertNotNull($spec, $status->value);
            $this->assertSame($type, $spec['recommendation_type'], $status->value);
        }
    }

    public function test_compliant_yields_no_recommendation_by_default(): void
    {
        $rules = new RecommendationRuleService();
        $this->assertNull($rules->forFinding($this->finding(ComplianceGapStatus::Compliant, ComplianceGapSeverity::None)));
        $this->assertNull($rules->forFinding($this->finding(ComplianceGapStatus::NotAssessed, ComplianceGapSeverity::Info)));
    }

    public function test_compliant_yields_maintain_when_requested(): void
    {
        $spec = (new RecommendationRuleService())->forFinding(
            $this->finding(ComplianceGapStatus::Compliant, ComplianceGapSeverity::None),
            [],
            true,
        );

        $this->assertNotNull($spec);
        $this->assertSame(RecommendationRuleService::TYPE_MAINTAIN_EVIDENCE, $spec['recommendation_type']);
    }

    public function test_rule_mapping_is_reproducible(): void
    {
        $rules = new RecommendationRuleService();
        $finding = $this->finding(ComplianceGapStatus::EvidenceExpired, ComplianceGapSeverity::High);

        $this->assertEquals($rules->forFinding($finding), $rules->forFinding($finding));
    }

    // ---- Priority ---------------------------------------------------------

    public function test_priority_base_map(): void
    {
        $svc = new RecommendationPrioritizationService();
        $this->assertSame(ComplianceRecommendationPriority::High, $svc->priorityFor($this->finding(ComplianceGapStatus::NoEvidence, ComplianceGapSeverity::High))['priority']);
        $this->assertSame(ComplianceRecommendationPriority::Medium, $svc->priorityFor($this->finding(ComplianceGapStatus::PartiallyCompliant, ComplianceGapSeverity::Medium))['priority']);
        $this->assertSame(ComplianceRecommendationPriority::Low, $svc->priorityFor($this->finding(ComplianceGapStatus::EvidencePendingValidation, ComplianceGapSeverity::Low))['priority']);
        $this->assertSame(ComplianceRecommendationPriority::Informational, $svc->priorityFor($this->finding(ComplianceGapStatus::Compliant, ComplianceGapSeverity::None))['priority']);
    }

    public function test_priority_escalates_for_missing_evidence_even_with_low_severity(): void
    {
        $result = (new RecommendationPrioritizationService())->priorityFor(
            $this->finding(ComplianceGapStatus::NoEvidence, ComplianceGapSeverity::Low),
        );

        $this->assertSame(ComplianceRecommendationPriority::High, $result['priority']);
    }

    public function test_domain_criticality_critical_forces_critical(): void
    {
        $result = (new RecommendationPrioritizationService())->priorityFor(
            $this->finding(ComplianceGapStatus::PartiallyCompliant, ComplianceGapSeverity::Medium),
            'critical',
        );

        $this->assertSame(ComplianceRecommendationPriority::Critical, $result['priority']);
        $this->assertStringContainsString("domain criticality 'critical'", $result['basis_en']);
    }

    // ---- Summary ----------------------------------------------------------

    public function test_summary_counts_by_priority_type_and_gap_status(): void
    {
        $recommendations = [
            ['priority' => 'high', 'recommendation_type' => 'collect_evidence', 'gap_status' => 'no_evidence'],
            ['priority' => 'high', 'recommendation_type' => 'refresh_evidence', 'gap_status' => 'evidence_expired'],
            ['priority' => 'medium', 'recommendation_type' => 'complete_coverage', 'gap_status' => 'partially_compliant'],
        ];

        $summary = (new RecommendationSummaryService())->summarize($recommendations);

        $this->assertSame(3, $summary['totals']['recommendations']);
        $this->assertSame(2, $summary['totals']['by_priority']['high']);
        $this->assertSame(1, $summary['totals']['by_priority']['medium']);
        $this->assertSame(1, $summary['totals']['by_type']['collect_evidence']);
        $this->assertSame(1, $summary['totals']['by_gap_status']['no_evidence']);
    }
}
