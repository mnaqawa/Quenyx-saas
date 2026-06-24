<?php

namespace App\Enums\Compliance\Gap;

/**
 * Deterministic severity label for a gap finding. Derived solely from the gap status via a fixed
 * mapping — NOT an AI/risk score and NOT a probability. Severity is a category, never a number.
 */
enum ComplianceGapSeverity: string
{
    case None = 'none';
    case Info = 'info';
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';

    /**
     * Fixed, explainable mapping from gap status to severity.
     */
    public static function forStatus(ComplianceGapStatus $status): self
    {
        return match ($status) {
            ComplianceGapStatus::Compliant => self::None,
            ComplianceGapStatus::PartiallyCompliant => self::Medium,
            ComplianceGapStatus::EvidencePendingValidation => self::Low,
            ComplianceGapStatus::EvidenceExpired => self::High,
            ComplianceGapStatus::EvidenceRejected => self::High,
            ComplianceGapStatus::NoEvidence => self::High,
            ComplianceGapStatus::NonCompliant => self::High,
            ComplianceGapStatus::NotAssessed => self::Info,
            ComplianceGapStatus::Unknown => self::Info,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
