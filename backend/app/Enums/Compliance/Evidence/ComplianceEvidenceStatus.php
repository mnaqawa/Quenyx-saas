<?php

namespace App\Enums\Compliance\Evidence;

/**
 * Lifecycle states of a piece of tenant evidence inside QCIF. Status is a discrete state — never
 * a score. Allowed transitions between states are owned by EvidenceLifecycleService.
 */
enum ComplianceEvidenceStatus: string
{
    case Registered = 'registered';
    case Collected = 'collected';
    case Validated = 'validated';
    case Approved = 'approved';
    case Expired = 'expired';
    case Rejected = 'rejected';
    case Archived = 'archived';

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
            self::Registered => 'Registered',
            self::Collected => 'Collected',
            self::Validated => 'Validated',
            self::Approved => 'Approved',
            self::Expired => 'Expired',
            self::Rejected => 'Rejected',
            self::Archived => 'Archived',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::Registered => 'مُسجَّل',
            self::Collected => 'مُجمَّع',
            self::Validated => 'مُتحقَّق منه',
            self::Approved => 'مُعتمَد',
            self::Expired => 'مُنتهي الصلاحية',
            self::Rejected => 'مرفوض',
            self::Archived => 'مؤرشف',
        };
    }

    /**
     * Terminal states accept no further transitions.
     */
    public function isTerminal(): bool
    {
        return $this === self::Archived;
    }
}
