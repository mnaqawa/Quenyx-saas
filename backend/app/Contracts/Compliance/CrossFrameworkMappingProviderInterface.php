<?php

namespace App\Contracts\Compliance;

/**
 * Future seam for cross-framework relationship mappings (e.g. NCA ECC control ⇄ ISO 27001
 * control ⇄ NIST CSF subcategory).
 *
 * QCIF Sprint 7 intentionally ships NO implementation and NO mappings. The Knowledge Graph
 * Layer is intra-framework only. This interface exists so a later sprint can register a
 * provider (via the service container) without changing the graph services or API contract.
 *
 * Implementations MUST be deterministic and MUST NOT perform any AI execution, vector
 * search, or external network calls when fulfilling a mapping lookup for the graph layer.
 */
interface CrossFrameworkMappingProviderInterface
{
    /**
     * Whether this provider can supply mappings for the given source framework.
     */
    public function supportsFramework(string $frameworkKey): bool;

    /**
     * Return deterministic cross-framework mappings for a single corpus entity.
     *
     * Each mapping SHOULD be a structured array carrying at minimum:
     *  - target_framework_key (string)
     *  - target_release_code  (string)
     *  - target_entity_type   (string: domain|control|requirement)
     *  - target_entity_uuid   (string, UUID — never a numeric id)
     *  - target_entity_code   (string)
     *  - relationship_type    (string: equivalent|related|partial|supersedes|...)
     *  - confidence           (string|null)
     *  - provenance           (array)
     *
     * @param  string  $entityType  domain|control|requirement
     * @return list<array<string, mixed>>
     */
    public function mappingsFor(
        string $entityType,
        string $entityUuid,
        string $frameworkKey,
        string $releaseCode,
    ): array;
}
