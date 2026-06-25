<?php

namespace App\Services\Compliance\Reasoning;

use App\DataTransferObjects\Compliance\Reasoning\ComplianceReasoningContext;
use App\DataTransferObjects\Compliance\Reasoning\ComplianceReasoningDecision;
use App\DataTransferObjects\Compliance\Reasoning\ReasoningFinding;
use App\DataTransferObjects\Compliance\Reasoning\ReasoningRecommendation;
use App\Enums\Compliance\Reasoning\ComplianceReasoningDecisionType;
use Ramsey\Uuid\Uuid;

/**
 * The deterministic business-rule engine (QCIF Sprint 16).
 *
 * Every rule is an explicit IF/THEN over deterministic metrics derived from the skill payloads —
 * e.g. "IF no evidence AND a mandatory requirement exists THEN finding=missing_evidence,
 * recommendation=collect_required_evidence, priority=high". No LLM, no DB, no probability. The same
 * context always fires the same rules and mints the same (uuid5) finding/recommendation UUIDs.
 */
class ComplianceReasoningRuleSet
{
    private const NS = 'qcif:sprint16:reasoning';

    public const SEVERITY_CRITICAL = 'critical';

    public const SEVERITY_HIGH = 'high';

    public const SEVERITY_MEDIUM = 'medium';

    public const SEVERITY_INFO = 'info';

    /**
     * Deterministic catalog of the business rules this engine can fire (QCIF Sprint 16). Exposed for
     * explainability / platform metrics (QCIF Sprint 18) — this is documentation of the existing
     * intelligence, not new logic.
     *
     * @return list<array{id: string, summary_en: string}>
     */
    public static function catalog(): array
    {
        return [
            ['id' => 'R-EVIDENCE-MISSING', 'summary_en' => 'No evidence recorded for a mandatory requirement ⇒ missing-evidence finding + collect-evidence recommendation.'],
            ['id' => 'R-GAP-OPEN', 'summary_en' => 'Open compliance gaps exist ⇒ open-gaps finding + remediate-gaps recommendation.'],
            ['id' => 'R-COMPLIANT', 'summary_en' => 'All assessed requirements satisfied with no open gaps ⇒ compliant finding.'],
            ['id' => 'R-RECO-CRITICAL', 'summary_en' => 'Critical remediation actions pending ⇒ critical finding + address-critical recommendation.'],
            ['id' => 'R-RECO-HIGH', 'summary_en' => 'High-priority remediation actions pending ⇒ high finding + schedule-high recommendation.'],
            ['id' => 'R-CORPUS-CITATIONS-MISSING', 'summary_en' => 'A citation-required decision has no corpus citations ⇒ missing-information (fail closed).'],
            ['id' => 'R-NO-DATA', 'summary_en' => 'No deterministic skill data available ⇒ missing-information (nothing to reason over).'],
        ];
    }

    /**
     * @return array{findings: list<ReasoningFinding>, recommendations: list<ReasoningRecommendation>, missing: list<array<string, mixed>>, applied: list<string>, warnings: list<string>}
     */
    public function apply(ComplianceReasoningContext $context, ComplianceReasoningDecision $decision): array
    {
        $findings = [];
        $recommendations = [];
        $missing = [];
        $applied = [];
        $warnings = [];

        $type = $decision->type;

        $gap = $context->payload('gap_assessment');
        $reco = $context->payload('recommendation');
        $evidence = $context->payload('evidence');

        $requirements = $this->metric($gap, ['summary', 'totals', 'requirements']);
        if ($requirements === 0) {
            $requirements = $this->corpusRequirementCount($context);
        }
        $gaps = $this->metric($gap, ['summary', 'totals', 'gaps']);
        $evidenceCount = $this->metric($evidence, ['counts', 'evidence']);
        $recoTotal = $this->metric($reco, ['summary', 'totals', 'recommendations']);
        $critical = $this->metric($reco, ['summary', 'totals', 'by_priority', 'critical']);
        $high = $this->metric($reco, ['summary', 'totals', 'by_priority', 'high']);

        $engineDecisions = [
            ComplianceReasoningDecisionType::EvidenceReview,
            ComplianceReasoningDecisionType::GapAnalysis,
            ComplianceReasoningDecisionType::ControlExplanation,
        ];

        // R-EVIDENCE-MISSING — Task 3 canonical rule.
        if (in_array($type, $engineDecisions, true) && $context->has('evidence') && $evidenceCount === 0 && $requirements > 0) {
            $applied[] = 'R-EVIDENCE-MISSING';
            $findings[] = $this->finding($context, 'R-EVIDENCE-MISSING', 'missing_evidence', self::SEVERITY_HIGH,
                'No evidence is recorded for the assessed mandatory requirement(s).',
                'لا توجد أدلة مسجّلة للمتطلبات الإلزامية التي تم تقييمها.',
                $context->groundingRefs);
            $recommendations[] = $this->recommendation($context, 'R-EVIDENCE-MISSING', 'collect_required_evidence', self::SEVERITY_HIGH,
                'Collect and attach the required evidence for the mandatory requirement(s).',
                'اجمع وأرفق الأدلة المطلوبة للمتطلبات الإلزامية.',
                $context->groundingRefs);
        }

        // R-GAP-OPEN
        if (in_array($type, [ComplianceReasoningDecisionType::GapAnalysis, ComplianceReasoningDecisionType::Recommendation, ComplianceReasoningDecisionType::EvidenceReview], true) && $gaps > 0) {
            $applied[] = 'R-GAP-OPEN';
            $findings[] = $this->finding($context, 'R-GAP-OPEN', 'open_gaps', self::SEVERITY_HIGH,
                sprintf('%d open compliance gap(s) require remediation.', $gaps),
                sprintf('%d فجوة امتثال مفتوحة تتطلب المعالجة.', $gaps),
                $context->groundingRefs);
            $recommendations[] = $this->recommendation($context, 'R-GAP-OPEN', 'remediate_open_gaps', self::SEVERITY_HIGH,
                'Remediate the open gaps, starting with the highest-priority requirements.',
                'عالج الفجوات المفتوحة بدءًا بالمتطلبات الأعلى أولوية.',
                $context->groundingRefs);
        }

        // R-COMPLIANT
        if ($type === ComplianceReasoningDecisionType::GapAnalysis && $requirements > 0 && $gaps === 0) {
            $applied[] = 'R-COMPLIANT';
            $findings[] = $this->finding($context, 'R-COMPLIANT', 'compliant', self::SEVERITY_INFO,
                'All assessed requirements are currently satisfied; no open gaps.',
                'جميع المتطلبات التي تم تقييمها مستوفاة حاليًا؛ لا توجد فجوات مفتوحة.',
                $context->groundingRefs);
        }

        // R-RECO-CRITICAL
        if (in_array($type, [ComplianceReasoningDecisionType::Recommendation, ComplianceReasoningDecisionType::GapAnalysis], true) && $critical > 0) {
            $applied[] = 'R-RECO-CRITICAL';
            $findings[] = $this->finding($context, 'R-RECO-CRITICAL', 'critical_actions_pending', self::SEVERITY_CRITICAL,
                sprintf('%d critical remediation action(s) are pending.', $critical),
                sprintf('%d إجراء معالجة حرج قيد الانتظار.', $critical),
                $context->groundingRefs);
            $recommendations[] = $this->recommendation($context, 'R-RECO-CRITICAL', 'address_critical_actions', self::SEVERITY_CRITICAL,
                'Address all critical remediation actions before lower-priority items.',
                'عالج جميع إجراءات المعالجة الحرجة قبل العناصر الأقل أولوية.',
                $context->groundingRefs);
        }

        // R-RECO-HIGH
        if ($type === ComplianceReasoningDecisionType::Recommendation && $high > 0) {
            $applied[] = 'R-RECO-HIGH';
            $findings[] = $this->finding($context, 'R-RECO-HIGH', 'high_priority_actions_pending', self::SEVERITY_HIGH,
                sprintf('%d high-priority remediation action(s) are pending.', $high),
                sprintf('%d إجراء معالجة عالي الأولوية قيد الانتظار.', $high),
                $context->groundingRefs);
            $recommendations[] = $this->recommendation($context, 'R-RECO-HIGH', 'address_high_priority_actions', self::SEVERITY_HIGH,
                'Schedule the high-priority remediation actions next.',
                'جدول إجراءات المعالجة عالية الأولوية تاليًا.',
                $context->groundingRefs);
        }

        // R-CORPUS-CITATIONS-MISSING — fail-closed signal for corpus-cited decisions.
        if ($decision->requiresCorpusCitations && $context->corpusCitations === []) {
            $applied[] = 'R-CORPUS-CITATIONS-MISSING';
            $missing[] = $this->missing($context, 'corpus_citations',
                'No official corpus citations were retrieved for a citation-required answer.',
                'لم يتم استرجاع أي استشهادات رسمية للإجابة التي تتطلب استشهادًا.');
            $warnings[] = 'reasoning_citation_context_missing';
        }

        // R-NO-DATA — nothing to reason over.
        if ($context->skillPayloads === []) {
            $applied[] = 'R-NO-DATA';
            $missing[] = $this->missing($context, 'no_supporting_data',
                'No deterministic skill data was available to reason over.',
                'لا تتوفر بيانات حتمية للاستدلال عليها.');
            $warnings[] = 'reasoning_no_supporting_data';
        }

        return [
            'findings' => $findings,
            'recommendations' => $recommendations,
            'missing' => $missing,
            'applied' => array_values(array_unique($applied)),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $citations
     */
    private function finding(ComplianceReasoningContext $context, string $ruleId, string $code, string $severity, string $en, string $ar, array $citations): ReasoningFinding
    {
        return new ReasoningFinding(
            uuid: $this->mint('finding', $ruleId, $context, $code),
            ruleId: $ruleId,
            code: $code,
            severity: $severity,
            summaryEn: $en,
            summaryAr: $ar,
            entityCode: $context->code,
            citations: array_values($citations),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $citations
     */
    private function recommendation(ComplianceReasoningContext $context, string $ruleId, string $action, string $priority, string $en, string $ar, array $citations): ReasoningRecommendation
    {
        return new ReasoningRecommendation(
            uuid: $this->mint('recommendation', $ruleId, $context, $action),
            ruleId: $ruleId,
            action: $action,
            priority: $priority,
            summaryEn: $en,
            summaryAr: $ar,
            entityCode: $context->code,
            citations: array_values($citations),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function missing(ComplianceReasoningContext $context, string $code, string $en, string $ar): array
    {
        return [
            'uuid' => $this->mint('missing', $code, $context, $code),
            'code' => $code,
            'reason_en' => $en,
            'reason_ar' => $ar,
        ];
    }

    private function mint(string $kind, string $ruleId, ComplianceReasoningContext $context, string $code): string
    {
        return (string) Uuid::uuid5(
            Uuid::uuid5(Uuid::NAMESPACE_URL, self::NS),
            implode('|', [$kind, $ruleId, $context->signature(), $code]),
        );
    }

    private function corpusRequirementCount(ComplianceReasoningContext $context): int
    {
        $corpus = $context->payload('corpus_search');
        $requirements = $corpus['requirements'] ?? null;

        return is_array($requirements) ? count($requirements) : 0;
    }

    /**
     * @param  array<string, mixed>|null  $data
     * @param  list<string|int>  $path
     */
    private function metric(?array $data, array $path): int
    {
        $current = $data;
        foreach ($path as $key) {
            if (! is_array($current) || ! array_key_exists($key, $current)) {
                return 0;
            }
            $current = $current[$key];
        }

        return is_numeric($current) ? (int) $current : 0;
    }
}
