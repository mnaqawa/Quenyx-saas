<?php

namespace App\Enums\Compliance\Recommendation;

/**
 * Deterministic recommendation priority (QCIF Sprint 13). A category derived by fixed rules from
 * gap status / severity / evidence state / domain criticality — NEVER a numeric score, risk
 * prediction, or probability.
 */
enum ComplianceRecommendationPriority: string
{
    case Critical = 'critical';
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
    case Informational = 'informational';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    /**
     * Higher rank = more urgent. Used only to take the MAX of two deterministic inputs; it is an
     * ordering helper, not a score exposed anywhere.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Critical => 5,
            self::High => 4,
            self::Medium => 3,
            self::Low => 2,
            self::Informational => 1,
        };
    }

    public function atLeast(self $floor): self
    {
        return $this->rank() >= $floor->rank() ? $this : $floor;
    }

    public function labelEn(): string
    {
        return match ($this) {
            self::Critical => 'Critical',
            self::High => 'High',
            self::Medium => 'Medium',
            self::Low => 'Low',
            self::Informational => 'Informational',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::Critical => 'حرج',
            self::High => 'عالٍ',
            self::Medium => 'متوسط',
            self::Low => 'منخفض',
            self::Informational => 'معلوماتي',
        };
    }
}
