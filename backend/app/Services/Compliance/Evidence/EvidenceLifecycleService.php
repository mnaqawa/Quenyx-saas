<?php

namespace App\Services\Compliance\Evidence;

use App\Enums\Compliance\Evidence\ComplianceEvidenceStatus;
use App\Models\Compliance\Evidence\ComplianceEvidence;
use App\Models\Compliance\Evidence\ComplianceEvidenceLifecycle;
use App\Models\User;
use InvalidArgumentException;

/**
 * Owns the evidence lifecycle: the allowed state transitions, the status catalog, and the
 * append-only transition history. The read-only Sprint 11 API only reads the catalog; the
 * write methods exist for future sprints (collection, validation, approval workflows).
 *
 * Performs NO AI execution.
 */
class EvidenceLifecycleService
{
    /**
     * Allowed transitions. Keys are current states; values are permissible next states.
     *
     * @return array<string, list<string>>
     */
    public function allowedTransitions(): array
    {
        return [
            ComplianceEvidenceStatus::Registered->value => [
                ComplianceEvidenceStatus::Collected->value,
                ComplianceEvidenceStatus::Rejected->value,
                ComplianceEvidenceStatus::Archived->value,
            ],
            ComplianceEvidenceStatus::Collected->value => [
                ComplianceEvidenceStatus::Validated->value,
                ComplianceEvidenceStatus::Rejected->value,
                ComplianceEvidenceStatus::Archived->value,
            ],
            ComplianceEvidenceStatus::Validated->value => [
                ComplianceEvidenceStatus::Approved->value,
                ComplianceEvidenceStatus::Rejected->value,
                ComplianceEvidenceStatus::Expired->value,
                ComplianceEvidenceStatus::Archived->value,
            ],
            ComplianceEvidenceStatus::Approved->value => [
                ComplianceEvidenceStatus::Expired->value,
                ComplianceEvidenceStatus::Rejected->value,
                ComplianceEvidenceStatus::Archived->value,
            ],
            ComplianceEvidenceStatus::Expired->value => [
                ComplianceEvidenceStatus::Collected->value,
                ComplianceEvidenceStatus::Archived->value,
            ],
            ComplianceEvidenceStatus::Rejected->value => [
                ComplianceEvidenceStatus::Collected->value,
                ComplianceEvidenceStatus::Archived->value,
            ],
            ComplianceEvidenceStatus::Archived->value => [],
        ];
    }

    public function canTransition(ComplianceEvidenceStatus $from, ComplianceEvidenceStatus $to): bool
    {
        return in_array($to->value, $this->allowedTransitions()[$from->value] ?? [], true);
    }

    /**
     * Catalog of states for the API: value, labels, terminal flag, and allowed next states.
     *
     * @return list<array<string, mixed>>
     */
    public function statusCatalog(): array
    {
        $transitions = $this->allowedTransitions();

        return array_map(fn (ComplianceEvidenceStatus $status) => [
            'value' => $status->value,
            'label_en' => $status->labelEn(),
            'label_ar' => $status->labelAr(),
            'is_terminal' => $status->isTerminal(),
            'allowed_transitions' => $transitions[$status->value] ?? [],
        ], ComplianceEvidenceStatus::cases());
    }

    /**
     * Apply a transition and record it in the append-only lifecycle log. Used by future
     * workflow sprints; the Sprint 11 read-only API never calls this.
     */
    public function transition(
        ComplianceEvidence $evidence,
        ComplianceEvidenceStatus $to,
        ?User $actor = null,
        ?string $reason = null,
    ): ComplianceEvidenceLifecycle {
        $from = $evidence->status;
        if ($from !== null && ! $this->canTransition($from, $to)) {
            throw new InvalidArgumentException("Illegal evidence transition: {$from->value} → {$to->value}.");
        }

        $event = $evidence->lifecycleEvents()->create([
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'reason' => $reason,
            'actor_id' => $actor?->id,
        ]);

        $evidence->status = $to;
        $this->stampTimestamp($evidence, $to);
        $evidence->save();

        return $event;
    }

    private function stampTimestamp(ComplianceEvidence $evidence, ComplianceEvidenceStatus $to): void
    {
        match ($to) {
            ComplianceEvidenceStatus::Collected => $evidence->collected_at = now(),
            ComplianceEvidenceStatus::Validated => $evidence->validated_at = now(),
            ComplianceEvidenceStatus::Approved => $evidence->approved_at = now(),
            default => null,
        };
    }
}
