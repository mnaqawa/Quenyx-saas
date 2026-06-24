<?php

namespace App\Enums\Compliance\Gap;

/**
 * Deterministic gap evaluation states (QCIF Sprint 12). A status is a discrete, rule-derived
 * outcome — never an AI decision, a probability, or a confidence percentage. The same inputs
 * always yield the same status.
 */
enum ComplianceGapStatus: string
{
    case Compliant = 'compliant';
    case PartiallyCompliant = 'partially_compliant';
    case NonCompliant = 'non_compliant';
    case NoEvidence = 'no_evidence';
    case EvidenceExpired = 'evidence_expired';
    case EvidenceRejected = 'evidence_rejected';
    case EvidencePendingValidation = 'evidence_pending_validation';
    case NotAssessed = 'not_assessed';
    case Unknown = 'unknown';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    public function isSatisfied(): bool
    {
        return $this === self::Compliant;
    }

    public function labelEn(): string
    {
        return match ($this) {
            self::Compliant => 'Compliant',
            self::PartiallyCompliant => 'Partially Compliant',
            self::NonCompliant => 'Non-Compliant',
            self::NoEvidence => 'No Evidence',
            self::EvidenceExpired => 'Evidence Expired',
            self::EvidenceRejected => 'Evidence Rejected',
            self::EvidencePendingValidation => 'Evidence Pending Validation',
            self::NotAssessed => 'Not Assessed',
            self::Unknown => 'Unknown',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::Compliant => 'مُمتثِل',
            self::PartiallyCompliant => 'ممتثل جزئيًا',
            self::NonCompliant => 'غير مُمتثِل',
            self::NoEvidence => 'لا يوجد دليل',
            self::EvidenceExpired => 'دليل منتهي الصلاحية',
            self::EvidenceRejected => 'دليل مرفوض',
            self::EvidencePendingValidation => 'دليل بانتظار التحقق',
            self::NotAssessed => 'لم يُقيَّم',
            self::Unknown => 'غير معروف',
        };
    }
}
