<?php

namespace App\Services\Compliance\Evidence;

use App\Enums\Compliance\Evidence\ComplianceEvidenceStatus;
use App\Models\Compliance\Evidence\ComplianceEvidence;

/**
 * Deterministic, read-only validation of an evidence record's metadata completeness and
 * temporal validity. Does NOT inspect file contents (no OCR / parsing) and performs NO AI —
 * it only reasons over the structured record and its relationships.
 */
class EvidenceValidationService
{
    /**
     * @return array{is_valid: bool, issues: list<array<string, string>>, warnings: list<array<string, string>>}
     */
    public function validate(ComplianceEvidence $evidence): array
    {
        $issues = [];
        $warnings = [];

        if (trim((string) $evidence->title) === '') {
            $issues[] = ['code' => 'missing_title', 'message' => 'Evidence has no title.'];
        }

        if ($evidence->evidence_type_id === null) {
            $warnings[] = ['code' => 'missing_type', 'message' => 'Evidence has no evidence type assigned.'];
        }

        if ($evidence->source === null || trim((string) $evidence->source) === '') {
            $warnings[] = ['code' => 'missing_source', 'message' => 'Evidence has no source recorded.'];
        }

        $hasPrimaryLink = $evidence->control_id !== null || $evidence->requirement_id !== null;
        $hasRelationships = $evidence->relationLoaded('relationships')
            ? $evidence->relationships->isNotEmpty()
            : $evidence->relationships()->exists();

        if (! $hasPrimaryLink && ! $hasRelationships) {
            $issues[] = ['code' => 'no_relationships', 'message' => 'Evidence is not linked to any requirement or control.'];
        }

        if ($this->isExpired($evidence)) {
            $warnings[] = ['code' => 'expired', 'message' => 'Evidence is past its expiry date.'];
        }

        if ($evidence->valid_from !== null && $evidence->expires_at !== null && $evidence->valid_from->greaterThan($evidence->expires_at)) {
            $issues[] = ['code' => 'invalid_validity_window', 'message' => 'valid_from is after expires_at.'];
        }

        return [
            'is_valid' => $issues === [],
            'issues' => $issues,
            'warnings' => $warnings,
        ];
    }

    /**
     * Whether the evidence is past its expiry. Status `expired` is always treated as expired.
     */
    public function isExpired(ComplianceEvidence $evidence): bool
    {
        if ($evidence->status === ComplianceEvidenceStatus::Expired) {
            return true;
        }

        return $evidence->expires_at !== null && $evidence->expires_at->isPast();
    }
}
