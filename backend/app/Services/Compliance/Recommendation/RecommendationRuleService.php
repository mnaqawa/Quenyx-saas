<?php

namespace App\Services\Compliance\Recommendation;

use App\Enums\Compliance\Gap\ComplianceGapStatus;

/**
 * The deterministic recommendation rule engine (QCIF Sprint 13).
 *
 * Maps a single gap finding to exactly one recommendation specification (type, bilingual
 * title/description/rationale, action items, source rule) — or null when no recommendation is
 * warranted (Compliant / NotAssessed, unless explicitly requested). The mapping is a fixed,
 * ordered ruleset: the same finding ALWAYS produces the same specification.
 *
 * No LLM, no RAG, no probability. It NEVER invents control text — titles only reference the
 * requirement's own code (corpus data); guidance is generic remediation language, not legal advice.
 */
class RecommendationRuleService
{
    public const TYPE_COLLECT_EVIDENCE = 'collect_evidence';
    public const TYPE_VALIDATE_EVIDENCE = 'validate_evidence';
    public const TYPE_REFRESH_EVIDENCE = 'refresh_evidence';
    public const TYPE_REPLACE_EVIDENCE = 'replace_evidence';
    public const TYPE_COMPLETE_COVERAGE = 'complete_coverage';
    public const TYPE_MANUAL_REVIEW = 'manual_review';
    public const TYPE_MAINTAIN_EVIDENCE = 'maintain_evidence';

    /**
     * @param  array<string, mixed>  $finding  A gap finding from GapAssessmentService
     * @param  list<array<string, mixed>>  $requiredTypes  Known required evidence types (uuid/key/title)
     * @return array<string, mixed>|null
     */
    public function forFinding(array $finding, array $requiredTypes = [], bool $includeCompliant = false): ?array
    {
        $status = $finding['status'] instanceof ComplianceGapStatus
            ? $finding['status']
            : ComplianceGapStatus::tryFrom((string) $finding['status']);

        if ($status === null) {
            return null;
        }

        $code = (string) ($finding['requirement']['code'] ?? '');
        $statusEn = $status->labelEn();
        $statusAr = $status->labelAr();
        $rule = (string) ($finding['evaluation_rule'] ?? '');

        return match ($status) {
            ComplianceGapStatus::NoEvidence => $this->noEvidence($code, $statusEn, $statusAr, $rule, $requiredTypes),
            ComplianceGapStatus::EvidencePendingValidation => $this->pendingValidation($code, $statusEn, $statusAr, $rule),
            ComplianceGapStatus::EvidenceExpired => $this->expired($code, $statusEn, $statusAr, $rule),
            ComplianceGapStatus::EvidenceRejected => $this->rejected($code, $statusEn, $statusAr, $rule),
            ComplianceGapStatus::PartiallyCompliant => $this->partial($code, $statusEn, $statusAr, $rule),
            ComplianceGapStatus::NonCompliant => $this->nonCompliant($code, $statusEn, $statusAr, $rule),
            ComplianceGapStatus::Unknown => $this->unknown($code, $statusEn, $statusAr, $rule),
            ComplianceGapStatus::Compliant => $includeCompliant ? $this->maintain($code, $statusEn, $statusAr, $rule) : null,
            ComplianceGapStatus::NotAssessed => null,
        };
    }

    /**
     * @param  list<array<string, mixed>>  $requiredTypes
     * @return array<string, mixed>
     */
    private function noEvidence(string $code, string $statusEn, string $statusAr, string $rule, array $requiredTypes): array
    {
        $typesEn = $this->typeListLabel($requiredTypes, 'title_en');
        $typesAr = $this->typeListLabel($requiredTypes, 'title_ar');

        $collectEn = $typesEn !== ''
            ? "Collect and link the required evidence ({$typesEn}) demonstrating that requirement {$code} is met."
            : "Collect and link evidence demonstrating that requirement {$code} is met.";
        $collectAr = $typesAr !== ''
            ? "اجمع واربط الأدلة المطلوبة ({$typesAr}) التي تُثبت تحقق المتطلب {$code}."
            : "اجمع واربط الأدلة التي تُثبت تحقق المتطلب {$code}.";

        $actions = [
            $this->action('collect_required_evidence', $collectEn, $collectAr),
            $this->action('assign_evidence_owner', 'Assign an owner responsible for collecting this evidence.', 'عيّن مالكًا مسؤولًا عن جمع هذا الدليل.'),
            $this->action('set_due_date', 'Set a due date for evidence collection.', 'حدد تاريخ استحقاق لجمع الدليل.'),
        ];

        return $this->spec(
            self::TYPE_COLLECT_EVIDENCE,
            'gap.no_evidence',
            "Collect evidence for requirement {$code}",
            "جمع دليل للمتطلب {$code}",
            "No evidence is currently linked to requirement {$code}. Collect, assign an owner for, and schedule the required evidence.",
            "لا يوجد حاليًا دليل مرتبط بالمتطلب {$code}. اجمع الدليل المطلوب وعيّن له مالكًا وجدوِل جمعه.",
            $code, $statusEn, $statusAr, $rule, $actions,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function pendingValidation(string $code, string $statusEn, string $statusAr, string $rule): array
    {
        $actions = [
            $this->action('review_evidence', 'Review the pending evidence for completeness and relevance.', 'راجع الدليل المعلّق للتأكد من اكتماله وملاءمته.'),
            $this->action('approve_or_reject', 'Approve or reject the evidence via the validation workflow.', 'اعتمد الدليل أو ارفضه عبر سير عمل التحقق.'),
        ];

        return $this->spec(
            self::TYPE_VALIDATE_EVIDENCE,
            'gap.evidence_pending_validation',
            "Validate pending evidence for requirement {$code}",
            "التحقق من الدليل المعلّق للمتطلب {$code}",
            "Evidence exists for requirement {$code} but is awaiting validation. Route it through the review and approval workflow.",
            "يوجد دليل للمتطلب {$code} لكنه بانتظار التحقق. مرِّره عبر سير عمل المراجعة والاعتماد.",
            $code, $statusEn, $statusAr, $rule, $actions,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function expired(string $code, string $statusEn, string $statusAr, string $rule): array
    {
        $actions = [
            $this->action('collect_current_evidence', 'Collect current, in-date evidence.', 'اجمع دليلًا حاليًا وساري المفعول.'),
            $this->action('supersede_expired', 'Supersede the expired evidence with the refreshed item.', 'استبدل الدليل منتهي الصلاحية بالدليل المُحدَّث.'),
        ];

        return $this->spec(
            self::TYPE_REFRESH_EVIDENCE,
            'gap.evidence_expired',
            "Refresh expired evidence for requirement {$code}",
            "تحديث الدليل منتهي الصلاحية للمتطلب {$code}",
            "All applicable evidence for requirement {$code} is past its expiry date. Collect current evidence and supersede the expired items.",
            "جميع الأدلة المنطبقة على المتطلب {$code} منتهية الصلاحية. اجمع دليلًا حاليًا واستبدل العناصر المنتهية.",
            $code, $statusEn, $statusAr, $rule, $actions,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function rejected(string $code, string $statusEn, string $statusAr, string $rule): array
    {
        $actions = [
            $this->action('provide_replacement_evidence', 'Provide replacement evidence that addresses the rejection reason.', 'قدّم دليلًا بديلًا يعالج سبب الرفض.'),
            $this->action('remediation_review', 'Conduct a remediation review of the underlying control.', 'أجرِ مراجعة معالجة للضابط المرتبط.'),
        ];

        return $this->spec(
            self::TYPE_REPLACE_EVIDENCE,
            'gap.evidence_rejected',
            "Replace rejected evidence for requirement {$code}",
            "استبدال الدليل المرفوض للمتطلب {$code}",
            "The applicable evidence for requirement {$code} was rejected. Provide replacement evidence or conduct a remediation review.",
            "الدليل المنطبق على المتطلب {$code} مرفوض. قدّم دليلًا بديلًا أو أجرِ مراجعة معالجة.",
            $code, $statusEn, $statusAr, $rule, $actions,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function partial(string $code, string $statusEn, string $statusAr, string $rule): array
    {
        $actions = [
            $this->action('identify_missing_expectations', 'Identify which required evidence expectations are not yet satisfied.', 'حدّد متطلبات الأدلة المطلوبة غير المُستوفاة بعد.'),
            $this->action('collect_remaining_evidence', 'Collect and link the remaining required evidence.', 'اجمع واربط الأدلة المطلوبة المتبقية.'),
        ];

        return $this->spec(
            self::TYPE_COMPLETE_COVERAGE,
            'gap.partially_compliant',
            "Complete evidence coverage for requirement {$code}",
            "استكمال تغطية الأدلة للمتطلب {$code}",
            "Some but not all required evidence expectations for requirement {$code} are satisfied. Provide the remaining required evidence.",
            "بعض متطلبات الأدلة المطلوبة للمتطلب {$code} مُستوفاة وليست كلها. قدّم الأدلة المطلوبة المتبقية.",
            $code, $statusEn, $statusAr, $rule, $actions,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function nonCompliant(string $code, string $statusEn, string $statusAr, string $rule): array
    {
        $actions = [
            $this->action('review_requirement', 'Review the requirement and its current evidence state.', 'راجع المتطلب وحالة أدلته الحالية.'),
            $this->action('collect_required_evidence', 'Collect and link the required evidence.', 'اجمع واربط الأدلة المطلوبة.'),
        ];

        return $this->spec(
            self::TYPE_COMPLETE_COVERAGE,
            'gap.non_compliant',
            "Remediate non-compliant requirement {$code}",
            "معالجة المتطلب غير المُمتثِل {$code}",
            "Requirement {$code} is not satisfied by any approved, in-date evidence. Review and collect the required evidence.",
            "المتطلب {$code} غير مُستوفى بأي دليل معتمد وساري المفعول. راجع واجمع الأدلة المطلوبة.",
            $code, $statusEn, $statusAr, $rule, $actions,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function unknown(string $code, string $statusEn, string $statusAr, string $rule): array
    {
        $actions = [
            $this->action('manual_review', 'Perform a manual review to determine the requirement status.', 'أجرِ مراجعة يدوية لتحديد حالة المتطلب.'),
        ];

        return $this->spec(
            self::TYPE_MANUAL_REVIEW,
            'gap.unknown',
            "Manually review requirement {$code}",
            "مراجعة يدوية للمتطلب {$code}",
            "The evidence for requirement {$code} could not be deterministically classified. Perform a manual review.",
            "تعذّر تصنيف أدلة المتطلب {$code} بشكل حتمي. أجرِ مراجعة يدوية.",
            $code, $statusEn, $statusAr, $rule, $actions,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function maintain(string $code, string $statusEn, string $statusAr, string $rule): array
    {
        $actions = [
            $this->action('monitor_expiry', 'Monitor evidence expiry dates and refresh before they lapse.', 'راقب تواريخ انتهاء الأدلة وحدّثها قبل انقضائها.'),
        ];

        return $this->spec(
            self::TYPE_MAINTAIN_EVIDENCE,
            'gap.compliant_maintain',
            "Maintain evidence for requirement {$code}",
            "الحفاظ على دليل المتطلب {$code}",
            "Requirement {$code} is currently compliant. Maintain the evidence and monitor for expiry.",
            "المتطلب {$code} مُمتثِل حاليًا. حافظ على الدليل وراقب انتهاء صلاحيته.",
            $code, $statusEn, $statusAr, $rule, $actions,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $actions
     * @return array<string, mixed>
     */
    private function spec(
        string $type,
        string $sourceRule,
        string $titleEn,
        string $titleAr,
        string $descEn,
        string $descAr,
        string $code,
        string $statusEn,
        string $statusAr,
        string $gapRule,
        array $actions,
    ): array {
        return [
            'recommendation_type' => $type,
            'source_rule' => $sourceRule,
            'title_en' => $titleEn,
            'title_ar' => $titleAr,
            'description_en' => $descEn,
            'description_ar' => $descAr,
            'rationale_en' => "Generated from gap status '{$statusEn}' for requirement {$code} (gap rule: {$gapRule}; recommendation rule: {$sourceRule}).",
            'rationale_ar' => "تم التوليد من حالة الفجوة '{$statusAr}' للمتطلب {$code} (قاعدة الفجوة: {$gapRule}؛ قاعدة التوصية: {$sourceRule}).",
            'action_items' => $actions,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function action(string $key, string $labelEn, string $labelAr): array
    {
        return ['action_key' => $key, 'label_en' => $labelEn, 'label_ar' => $labelAr];
    }

    /**
     * @param  list<array<string, mixed>>  $types
     */
    private function typeListLabel(array $types, string $field): string
    {
        $labels = [];
        foreach ($types as $type) {
            $label = $type[$field] ?? null;
            if (is_string($label) && $label !== '') {
                $labels[] = $label;
            }
        }

        return implode(', ', $labels);
    }
}
