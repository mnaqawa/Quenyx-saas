<?php

namespace App\Contracts\Compliance\Mapping;

use App\Contracts\Compliance\CrossFrameworkMappingProviderInterface;

/**
 * Base contract for a future per-framework cross-mapping provider.
 *
 * Extends the Sprint 7 graph seam (CrossFrameworkMappingProviderInterface) so the Knowledge
 * Graph and the Sprint 8 Mapping Foundation share a single provider abstraction. A provider
 * declares which framework it maps FROM and returns deterministic, UUID-only, citable
 * mappings to other frameworks.
 *
 * QCIF Sprint 8 ships NO implementation. These contracts exist so a future sprint can bind a
 * concrete provider per framework WITHOUT changing the mapping services, controller, routes,
 * or response contract. Implementations MUST be deterministic and MUST NOT perform AI
 * execution, vector search, or external network calls.
 */
interface FrameworkMappingProviderInterface extends CrossFrameworkMappingProviderInterface
{
    /**
     * The framework key this provider supplies mappings for (e.g. "iso-27001").
     */
    public function frameworkKey(): string;

    /**
     * Deterministic cross-framework control mappings for a single source control.
     *
     * @return list<\App\DataTransferObjects\Compliance\CrossFrameworkControlMapping>
     */
    public function controlMappings(
        string $sourceControlUuid,
        string $sourceFrameworkKey,
        string $sourceReleaseCode,
    ): array;
}
