<?php

namespace App\Services\Compliance\Ai;

use App\Models\Compliance\ComplianceControl;
use App\Models\Compliance\ComplianceDomain;
use App\Models\Compliance\ComplianceRequirement;
use App\Models\Compliance\ComplianceSourceDocument;

/**
 * Builds deterministic citation records for AI-ready payloads.
 *
 * A citation links a corpus entity (domain/control/requirement) or a source document to
 * its official provenance. The future AI consumer MUST attach a citation to every claim;
 * this builder produces the canonical citation shape and never invents references.
 *
 * Citation shape (all keys always present):
 *  - source_document_key   string|null  (key of the official source document)
 *  - source_title_en       string|null
 *  - source_title_ar       string|null
 *  - official_reference    string|null  (clause/article reference inside the document)
 *  - source_reference      string|null  (internal corpus reference)
 *  - source_page           string|null
 *  - entity_uuid           string       (stable identifier, never a numeric id)
 *  - entity_type           string       (domain|control|requirement|source_document)
 *  - entity_code           string|null
 */
class ComplianceAiCitationBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function forDomain(ComplianceDomain $domain): array
    {
        return $this->entityCitation('domain', $domain, $this->resolveSourceDocument($domain));
    }

    /**
     * @return array<string, mixed>
     */
    public function forControl(ComplianceControl $control): array
    {
        return $this->entityCitation('control', $control, $this->resolveSourceDocument($control));
    }

    /**
     * @return array<string, mixed>
     */
    public function forRequirement(ComplianceRequirement $requirement): array
    {
        return $this->entityCitation('requirement', $requirement, $this->resolveSourceDocument($requirement));
    }

    /**
     * @return array<string, mixed>
     */
    public function forSourceDocument(ComplianceSourceDocument $document): array
    {
        return [
            'source_document_key' => $document->key,
            'source_title_en' => $document->title_en,
            'source_title_ar' => $document->title_ar,
            'official_reference' => null,
            'source_reference' => $document->source_reference,
            'source_page' => null,
            'entity_uuid' => $document->uuid,
            'entity_type' => 'source_document',
            'entity_code' => $document->key,
        ];
    }

    public function entityHasSourceDocument(ComplianceControl|ComplianceDomain|ComplianceRequirement $entity): bool
    {
        return $this->resolveSourceDocument($entity) !== null;
    }

    /**
     * @return array<string, mixed>
     */
    private function entityCitation(
        string $entityType,
        ComplianceControl|ComplianceDomain|ComplianceRequirement $entity,
        ?ComplianceSourceDocument $document,
    ): array {
        return [
            'source_document_key' => $document?->key,
            'source_title_en' => $document?->title_en,
            'source_title_ar' => $document?->title_ar,
            'official_reference' => $entity->official_reference,
            'source_reference' => $entity->source_reference,
            'source_page' => $entity->source_page,
            'entity_uuid' => $entity->uuid,
            'entity_type' => $entityType,
            'entity_code' => $entity->display_code ?? $entity->code,
        ];
    }

    private function resolveSourceDocument(
        ComplianceControl|ComplianceDomain|ComplianceRequirement $entity,
    ): ?ComplianceSourceDocument {
        if ($entity->relationLoaded('sourceDocument')) {
            return $entity->sourceDocument;
        }

        return $entity->sourceDocument()->getResults();
    }
}
