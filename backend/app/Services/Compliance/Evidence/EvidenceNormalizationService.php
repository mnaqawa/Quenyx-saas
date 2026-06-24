<?php

namespace App\Services\Compliance\Evidence;

use App\Enums\Compliance\Evidence\ComplianceEvidenceStatus;
use App\Models\Compliance\ComplianceEvidenceType;
use App\Models\Compliance\Evidence\ComplianceEvidence;
use Illuminate\Database\Eloquent\Collection;

/**
 * Turns stored evidence rows into normalized, UUID-only nodes and provides the supporting
 * catalogs (evidence types). This is the single DB read boundary for evidence retrieval — the
 * skill and controller never query evidence directly. No file/blob/OCR access, no AI.
 */
class EvidenceNormalizationService
{
    /**
     * Retrieve workspace-scoped evidence with optional filters.
     *
     * Supported filters: status, evidence_type (key|code), framework_release_id, evidence_uuid,
     * control_id, requirement_id, limit.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, ComplianceEvidence>
     */
    public function workspaceEvidence(int $projectId, array $filters = []): Collection
    {
        $query = ComplianceEvidence::query()
            ->where('project_id', $projectId)
            ->with(['evidenceType', 'relationships'])
            ->orderByDesc('id');

        if (! empty($filters['status']) && in_array($filters['status'], ComplianceEvidenceStatus::values(), true)) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['evidence_uuid'])) {
            $query->where('uuid', (string) $filters['evidence_uuid']);
        }

        if (! empty($filters['framework_release_id'])) {
            $query->where('framework_release_id', (int) $filters['framework_release_id']);
        }

        if (! empty($filters['control_id'])) {
            $query->where('control_id', (int) $filters['control_id']);
        }

        if (! empty($filters['requirement_id'])) {
            $query->where('requirement_id', (int) $filters['requirement_id']);
        }

        if (! empty($filters['evidence_type'])) {
            $typeId = ComplianceEvidenceType::query()
                ->where('key', $filters['evidence_type'])
                ->orWhere('code', $filters['evidence_type'])
                ->value('id');
            $query->where('evidence_type_id', $typeId);
        }

        $limit = min(max((int) ($filters['limit'] ?? 50), 1), 200);

        return $query->limit($limit)->get();
    }

    /**
     * Normalize one evidence record into a UUID-only node (no numeric ids exposed).
     *
     * @return array<string, mixed>
     */
    public function evidenceNode(ComplianceEvidence $evidence): array
    {
        $status = $evidence->status;
        $type = $evidence->relationLoaded('evidenceType') ? $evidence->evidenceType : $evidence->evidenceType()->getResults();

        return [
            'entity_type' => 'evidence',
            'uuid' => $evidence->uuid,
            'title' => $evidence->title,
            'description' => $evidence->description,
            'source' => $evidence->source,
            'source_reference' => $evidence->source_reference,
            'status' => [
                'value' => $status?->value,
                'label_en' => $status?->labelEn(),
                'label_ar' => $status?->labelAr(),
            ],
            'evidence_type' => $type === null ? null : [
                'uuid' => $type->uuid,
                'key' => $type->key,
                'code' => $type->code,
                'title_en' => $type->title_en,
                'title_ar' => $type->title_ar,
            ],
            'timestamps' => [
                'collected_at' => $evidence->collected_at?->toIso8601String(),
                'validated_at' => $evidence->validated_at?->toIso8601String(),
                'approved_at' => $evidence->approved_at?->toIso8601String(),
                'valid_from' => $evidence->valid_from?->toIso8601String(),
                'expires_at' => $evidence->expires_at?->toIso8601String(),
                'created_at' => $evidence->created_at?->toIso8601String(),
                'updated_at' => $evidence->updated_at?->toIso8601String(),
            ],
            'metadata' => $evidence->metadata,
        ];
    }

    /**
     * Catalog of evidence types (reuses the corpus catalog). UUID-only.
     *
     * @return list<array<string, mixed>>
     */
    public function typeCatalog(): array
    {
        return ComplianceEvidenceType::query()
            ->orderBy('sort_order')
            ->orderBy('key')
            ->get()
            ->map(fn (ComplianceEvidenceType $type) => [
                'uuid' => $type->uuid,
                'key' => $type->key,
                'code' => $type->code,
                'title_en' => $type->title_en,
                'title_ar' => $type->title_ar,
                'description_en' => $type->description_en,
                'description_ar' => $type->description_ar,
            ])
            ->all();
    }
}
