<?php

namespace App\Enums\Compliance\Recommendation;

/**
 * Lifecycle state of a recommendation (QCIF Sprint 13). Deterministically GENERATED
 * recommendations are always created as `proposed`. The remaining states exist for a future
 * remediation workflow (acknowledge / progress / complete / dismiss) and for superseding when a
 * newer assessment regenerates a recommendation — they are NOT driven by AI.
 */
enum ComplianceRecommendationStatus: string
{
    case Proposed = 'proposed';
    case Acknowledged = 'acknowledged';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Dismissed = 'dismissed';
    case Superseded = 'superseded';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    public function labelEn(): string
    {
        return match ($this) {
            self::Proposed => 'Proposed',
            self::Acknowledged => 'Acknowledged',
            self::InProgress => 'In Progress',
            self::Completed => 'Completed',
            self::Dismissed => 'Dismissed',
            self::Superseded => 'Superseded',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::Proposed => 'مقترح',
            self::Acknowledged => 'مُقَر',
            self::InProgress => 'قيد التنفيذ',
            self::Completed => 'مكتمل',
            self::Dismissed => 'مرفوض',
            self::Superseded => 'مُستبدَل',
        };
    }
}
