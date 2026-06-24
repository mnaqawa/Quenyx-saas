<?php

namespace Tests\Unit;

use App\Enums\Compliance\Gap\ComplianceGapSeverity;
use App\Enums\Compliance\Gap\ComplianceGapStatus;
use App\Services\Compliance\Gap\EvidenceCorrelationService;
use App\Services\Compliance\Gap\GapCoverageService;
use App\Services\Compliance\Gap\GapEvaluationService;
use App\Services\Compliance\Gap\GapSummaryService;
use Tests\TestCase;

/**
 * DB-free, AI-free unit tests for the Gap Assessment & Evidence Correlation Engine (Sprint 12).
 *
 * These exercise the deterministic decision logic directly (evaluation rules, coverage
 * aggregation, severity mapping, correlation applicability, and reproducibility) without touching
 * a database or any AI provider.
 */
class GapAssessmentEngineTest extends TestCase
{
    private function descriptor(string $uuid, string $classification, string $status = 'approved', string $origin = 'requirement', ?int $typeId = null): array
    {
        return [
            'uuid' => $uuid,
            'status' => $status,
            'classification' => $classification,
            'origin' => $origin,
            'evidence_type_id' => $typeId,
        ];
    }

    // ---------------------------------------------------------------------
    // Enums & severity mapping
    // ---------------------------------------------------------------------

    public function test_gap_status_enum_exposes_all_required_states(): void
    {
        $this->assertSame([
            'compliant', 'partially_compliant', 'non_compliant', 'no_evidence',
            'evidence_expired', 'evidence_rejected', 'evidence_pending_validation',
            'not_assessed', 'unknown',
        ], ComplianceGapStatus::values());
    }

    public function test_severity_is_a_fixed_deterministic_map_from_status(): void
    {
        $this->assertSame(ComplianceGapSeverity::None, ComplianceGapSeverity::forStatus(ComplianceGapStatus::Compliant));
        $this->assertSame(ComplianceGapSeverity::Medium, ComplianceGapSeverity::forStatus(ComplianceGapStatus::PartiallyCompliant));
        $this->assertSame(ComplianceGapSeverity::Low, ComplianceGapSeverity::forStatus(ComplianceGapStatus::EvidencePendingValidation));
        $this->assertSame(ComplianceGapSeverity::High, ComplianceGapSeverity::forStatus(ComplianceGapStatus::NoEvidence));
        $this->assertSame(ComplianceGapSeverity::High, ComplianceGapSeverity::forStatus(ComplianceGapStatus::EvidenceExpired));
        $this->assertSame(ComplianceGapSeverity::High, ComplianceGapSeverity::forStatus(ComplianceGapStatus::EvidenceRejected));
        $this->assertSame(ComplianceGapSeverity::Info, ComplianceGapSeverity::forStatus(ComplianceGapStatus::Unknown));
    }

    // ---------------------------------------------------------------------
    // Evaluation rules
    // ---------------------------------------------------------------------

    public function test_no_evidence_yields_no_evidence_state(): void
    {
        $result = (new GapEvaluationService())->evaluate([], []);

        $this->assertSame(ComplianceGapStatus::NoEvidence, $result['status']);
        $this->assertSame(GapEvaluationService::RULE_NO_EVIDENCE, $result['evaluation_rule']);
        $this->assertSame([], $result['evidence_considered']);
    }

    public function test_only_archived_evidence_is_treated_as_no_evidence(): void
    {
        $result = (new GapEvaluationService())->evaluate([
            $this->descriptor('e1', 'archived', 'archived'),
        ], []);

        $this->assertSame(ComplianceGapStatus::NoEvidence, $result['status']);
        $this->assertCount(1, $result['evidence_ignored']);
        $this->assertSame('archived', $result['evidence_ignored'][0]['reason']);
    }

    public function test_approved_valid_with_no_required_expectations_is_compliant(): void
    {
        $result = (new GapEvaluationService())->evaluate([
            $this->descriptor('e1', 'approved_valid'),
        ], []);

        $this->assertSame(ComplianceGapStatus::Compliant, $result['status']);
        $this->assertSame(GapEvaluationService::RULE_APPROVED_NO_REQUIRED, $result['evaluation_rule']);
        $this->assertSame(ComplianceGapSeverity::None, $result['severity']);
    }

    public function test_all_required_types_matched_is_compliant(): void
    {
        $result = (new GapEvaluationService())->evaluate(
            [
                $this->descriptor('e1', 'approved_valid', typeId: 10),
                $this->descriptor('e2', 'approved_valid', typeId: 20),
            ],
            [
                ['is_required' => true, 'evidence_type_id' => 10, 'code' => 'A'],
                ['is_required' => true, 'evidence_type_id' => 20, 'code' => 'B'],
            ],
        );

        $this->assertSame(ComplianceGapStatus::Compliant, $result['status']);
        $this->assertSame(GapEvaluationService::RULE_ALL_REQUIRED_SATISFIED, $result['evaluation_rule']);
    }

    public function test_some_required_types_matched_is_partial(): void
    {
        $result = (new GapEvaluationService())->evaluate(
            [
                $this->descriptor('e1', 'approved_valid', typeId: 10),
            ],
            [
                ['is_required' => true, 'evidence_type_id' => 10, 'code' => 'A'],
                ['is_required' => true, 'evidence_type_id' => 20, 'code' => 'B'],
            ],
        );

        $this->assertSame(ComplianceGapStatus::PartiallyCompliant, $result['status']);
        $this->assertSame(GapEvaluationService::RULE_SOME_REQUIRED_SATISFIED, $result['evaluation_rule']);
        $this->assertSame(ComplianceGapSeverity::Medium, $result['severity']);
    }

    public function test_approved_evidence_not_matching_required_types_is_partial(): void
    {
        $result = (new GapEvaluationService())->evaluate(
            [
                $this->descriptor('e1', 'approved_valid', typeId: 99),
            ],
            [
                ['is_required' => true, 'evidence_type_id' => 10, 'code' => 'A'],
            ],
        );

        $this->assertSame(ComplianceGapStatus::PartiallyCompliant, $result['status']);
        $this->assertSame(GapEvaluationService::RULE_APPROVED_TYPES_UNMATCHED, $result['evaluation_rule']);
    }

    public function test_pending_evidence_yields_pending_validation(): void
    {
        $result = (new GapEvaluationService())->evaluate([
            $this->descriptor('e1', 'pending', 'collected'),
        ], []);

        $this->assertSame(ComplianceGapStatus::EvidencePendingValidation, $result['status']);
        $this->assertSame(GapEvaluationService::RULE_PENDING, $result['evaluation_rule']);
    }

    public function test_expired_only_yields_expired(): void
    {
        $result = (new GapEvaluationService())->evaluate([
            $this->descriptor('e1', 'expired', 'expired'),
        ], []);

        $this->assertSame(ComplianceGapStatus::EvidenceExpired, $result['status']);
        $this->assertSame(GapEvaluationService::RULE_EXPIRED, $result['evaluation_rule']);
    }

    public function test_rejected_only_yields_rejected(): void
    {
        $result = (new GapEvaluationService())->evaluate([
            $this->descriptor('e1', 'rejected', 'rejected'),
        ], []);

        $this->assertSame(ComplianceGapStatus::EvidenceRejected, $result['status']);
        $this->assertSame(GapEvaluationService::RULE_REJECTED, $result['evaluation_rule']);
    }

    public function test_approved_supersedes_rejected_and_expired_which_are_ignored(): void
    {
        $result = (new GapEvaluationService())->evaluate([
            $this->descriptor('e1', 'approved_valid'),
            $this->descriptor('e2', 'rejected', 'rejected'),
            $this->descriptor('e3', 'expired', 'expired'),
        ], []);

        $this->assertSame(ComplianceGapStatus::Compliant, $result['status']);
        $this->assertCount(1, $result['evidence_considered']);
        $ignoredReasons = array_column($result['evidence_ignored'], 'reason');
        $this->assertContains('rejected', $ignoredReasons);
        $this->assertContains('expired', $ignoredReasons);
    }

    public function test_evaluation_is_reproducible(): void
    {
        $service = new GapEvaluationService();
        $input = [
            $this->descriptor('e1', 'pending', 'collected'),
            $this->descriptor('e2', 'expired', 'expired'),
        ];

        $a = $service->evaluate($input, []);
        $b = $service->evaluate($input, []);

        $this->assertEquals($a, $b);
    }

    // ---------------------------------------------------------------------
    // Coverage aggregation
    // ---------------------------------------------------------------------

    public function test_coverage_aggregates_upward_with_deterministic_counts(): void
    {
        $findings = [
            $this->finding('c1', 'd1', ComplianceGapStatus::Compliant),
            $this->finding('c1', 'd1', ComplianceGapStatus::NoEvidence),
            $this->finding('c2', 'd1', ComplianceGapStatus::Compliant),
        ];

        $coverage = (new GapCoverageService())->aggregate($findings, ['uuid' => 'fw1', 'code' => 'ECC']);

        // c1 has one compliant + one gap => partially compliant
        $c1 = $this->findScope($coverage['controls'], 'c1');
        $this->assertSame(ComplianceGapStatus::PartiallyCompliant->value, $c1['status']);
        $this->assertSame(2, $c1['totals']['requirements']);
        $this->assertSame(1, $c1['totals']['satisfied']);

        // c2 fully compliant
        $c2 = $this->findScope($coverage['controls'], 'c2');
        $this->assertSame(ComplianceGapStatus::Compliant->value, $c2['status']);

        // domain d1: 2 of 3 satisfied => partial
        $this->assertSame(ComplianceGapStatus::PartiallyCompliant->value, $coverage['domains'][0]['status']);

        // workspace: 2 of 3 satisfied => partial
        $this->assertSame(ComplianceGapStatus::PartiallyCompliant->value, $coverage['workspace']['status']);
        $this->assertSame(3, $coverage['workspace']['totals']['requirements']);
    }

    public function test_empty_scope_is_not_assessed(): void
    {
        $service = new GapCoverageService();
        $this->assertSame(ComplianceGapStatus::NotAssessed, $service->aggregateStatus(0, 0));
        $this->assertSame(ComplianceGapStatus::Compliant, $service->aggregateStatus(4, 4));
        $this->assertSame(ComplianceGapStatus::NonCompliant, $service->aggregateStatus(4, 0));
        $this->assertSame(ComplianceGapStatus::PartiallyCompliant, $service->aggregateStatus(4, 2));
    }

    // ---------------------------------------------------------------------
    // Summary
    // ---------------------------------------------------------------------

    public function test_summary_counts_by_status_and_severity(): void
    {
        $findings = [
            $this->finding('c1', 'd1', ComplianceGapStatus::Compliant),
            $this->finding('c1', 'd1', ComplianceGapStatus::NoEvidence),
        ];
        $coverage = (new GapCoverageService())->aggregate($findings, ['uuid' => 'fw1', 'code' => 'ECC']);
        $summary = (new GapSummaryService())->summarize($findings, $coverage);

        $this->assertSame(2, $summary['totals']['requirements']);
        $this->assertSame(1, $summary['totals']['satisfied']);
        $this->assertSame(1, $summary['totals']['gaps']);
        $this->assertSame(1, $summary['totals']['by_status']['compliant']);
        $this->assertSame(1, $summary['totals']['by_status']['no_evidence']);
        $this->assertSame(1, $summary['totals']['by_severity']['high']);
    }

    // ---------------------------------------------------------------------
    // Correlation applicability (pure array logic, no DB)
    // ---------------------------------------------------------------------

    public function test_applicable_evidence_merges_requirement_and_control_links(): void
    {
        $index = [
            'evidence_by_id' => [1 => (object) ['id' => 1], 2 => (object) ['id' => 2], 3 => (object) ['id' => 3]],
            'requirement_evidence' => [100 => [1]],
            'control_evidence' => [200 => [2, 1]], // 1 is also a control link but must dedupe
            'domain_evidence' => [],
            'framework_evidence' => [],
            'objective_evidence' => [],
            'counts' => [],
        ];

        $items = (new EvidenceCorrelationService())->applicableEvidenceForRequirement($index, 100, 200);

        $this->assertCount(2, $items); // evidence 1 (requirement origin) + evidence 2 (control origin)
        $this->assertSame('requirement', $items[0]['origin']);
        $this->assertSame('control', $items[1]['origin']);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function finding(string $controlUuid, string $domainUuid, ComplianceGapStatus $status): array
    {
        return [
            'control' => ['uuid' => $controlUuid, 'code' => $controlUuid, 'title_en' => null, 'title_ar' => null],
            'domain' => ['uuid' => $domainUuid, 'code' => $domainUuid, 'title_en' => null, 'title_ar' => null],
            'status' => $status,
            'severity' => ComplianceGapSeverity::forStatus($status)->value,
        ];
    }

    private function findScope(array $nodes, string $uuid): array
    {
        foreach ($nodes as $node) {
            if (($node['uuid'] ?? null) === $uuid) {
                return $node;
            }
        }

        $this->fail("Scope not found: {$uuid}");
    }
}
