<?php

namespace App\Services\Compliance\Recommendation;

use App\Enums\Compliance\Gap\ComplianceGapSeverity;
use App\Enums\Compliance\Gap\ComplianceGapStatus;
use App\Enums\Compliance\Recommendation\ComplianceRecommendationPriority;

/**
 * Deterministic priority derivation (QCIF Sprint 13).
 *
 * Priority is a CATEGORY (critical|high|medium|low|informational) computed by fixed rules from the
 * gap severity, the gap status, the evidence state, and (if available) the domain criticality.
 * There is no numeric score, no risk prediction, and no probability. When inputs are unavailable
 * the engine defaults conservatively.
 *
 * Base map (from the gap severity, which itself is a fixed map of gap status):
 *   critical → Critical · high → High · medium → Medium · low → Low · info/none → Informational
 *
 * Escalation (deterministic, takes the MAX):
 *   - No Evidence / Evidence Expired / Evidence Rejected  → at least High
 *   - Domain criticality 'critical'                        → Critical
 *   - Domain criticality 'high'                            → at least High
 */
class RecommendationPrioritizationService
{
    /**
     * @param  array<string, mixed>  $finding  A gap finding from GapAssessmentService
     * @return array{priority: ComplianceRecommendationPriority, basis_en: string, basis_ar: string}
     */
    public function priorityFor(array $finding, ?string $domainCriticality = null): array
    {
        $severityValue = (string) ($finding['severity'] ?? ComplianceGapSeverity::Info->value);
        $statusValue = $finding['status'] instanceof ComplianceGapStatus
            ? $finding['status']->value
            : (string) ($finding['status'] ?? '');

        $priority = $this->base($severityValue);
        $basis = ["gap severity '{$severityValue}' (status '{$statusValue}')"];
        $basisAr = ["خطورة الفجوة '{$severityValue}' (الحالة '{$statusValue}')"];

        if (in_array($statusValue, [
            ComplianceGapStatus::NoEvidence->value,
            ComplianceGapStatus::EvidenceExpired->value,
            ComplianceGapStatus::EvidenceRejected->value,
            ComplianceGapStatus::NonCompliant->value,
        ], true)) {
            $before = $priority;
            $priority = $priority->atLeast(ComplianceRecommendationPriority::High);
            if ($priority !== $before) {
                $basis[] = 'escalated to at least High due to missing/expired/rejected evidence';
                $basisAr[] = 'رُفعت إلى عالٍ على الأقل بسبب دليل مفقود/منتهٍ/مرفوض';
            }
        }

        $criticality = $domainCriticality !== null ? strtolower(trim($domainCriticality)) : null;
        if ($criticality === 'critical') {
            $priority = ComplianceRecommendationPriority::Critical;
            $basis[] = "domain criticality 'critical'";
            $basisAr[] = "حرجية النطاق 'حرج'";
        } elseif ($criticality === 'high') {
            $before = $priority;
            $priority = $priority->atLeast(ComplianceRecommendationPriority::High);
            if ($priority !== $before) {
                $basis[] = "domain criticality 'high'";
                $basisAr[] = "حرجية النطاق 'عالٍ'";
            }
        }

        return [
            'priority' => $priority,
            'basis_en' => implode('; ', $basis).'.',
            'basis_ar' => implode('؛ ', $basisAr).'.',
        ];
    }

    private function base(string $severityValue): ComplianceRecommendationPriority
    {
        return match ($severityValue) {
            ComplianceGapSeverity::Critical->value => ComplianceRecommendationPriority::Critical,
            ComplianceGapSeverity::High->value => ComplianceRecommendationPriority::High,
            ComplianceGapSeverity::Medium->value => ComplianceRecommendationPriority::Medium,
            ComplianceGapSeverity::Low->value => ComplianceRecommendationPriority::Low,
            default => ComplianceRecommendationPriority::Informational,
        };
    }
}
