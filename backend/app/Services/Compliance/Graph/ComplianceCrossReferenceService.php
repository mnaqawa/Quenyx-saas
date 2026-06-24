<?php

namespace App\Services\Compliance\Graph;

use App\Contracts\Compliance\CrossFrameworkMappingProviderInterface;

/**
 * Surfaces "cross_references" for a graph node.
 *
 * Phase 1 (this sprint) is intra-framework only, so:
 *  - `intra_framework` is intentionally empty — deterministic structural relationships are
 *    already expressed through ancestors / descendants / siblings on each context response.
 *  - `cross_framework` is delegated to an OPTIONAL provider implementing
 *    CrossFrameworkMappingProviderInterface. No provider is bound in this sprint, so the
 *    result is always an empty list. The seam exists so a future sprint can register a
 *    provider WITHOUT changing the graph services or API contract.
 *
 * This service performs NO AI execution, vector search, or external calls.
 */
class ComplianceCrossReferenceService
{
    /**
     * @return array{intra_framework: list<array<string, mixed>>, cross_framework: list<array<string, mixed>>}
     */
    public function crossReferencesFor(
        string $entityType,
        string $entityUuid,
        string $frameworkKey,
        string $releaseCode,
    ): array {
        return [
            'intra_framework' => [],
            'cross_framework' => $this->crossFrameworkMappings($entityType, $entityUuid, $frameworkKey, $releaseCode),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function crossFrameworkMappings(
        string $entityType,
        string $entityUuid,
        string $frameworkKey,
        string $releaseCode,
    ): array {
        $provider = $this->resolveProvider();

        if ($provider === null || ! $provider->supportsFramework($frameworkKey)) {
            return [];
        }

        return $provider->mappingsFor($entityType, $entityUuid, $frameworkKey, $releaseCode);
    }

    /**
     * Whether a cross-framework mapping provider is currently registered.
     * Always false in Sprint 7 (no provider bound).
     */
    public function crossFrameworkSupportEnabled(): bool
    {
        return $this->resolveProvider() !== null;
    }

    private function resolveProvider(): ?CrossFrameworkMappingProviderInterface
    {
        if (! app()->bound(CrossFrameworkMappingProviderInterface::class)) {
            return null;
        }

        $provider = app(CrossFrameworkMappingProviderInterface::class);

        return $provider instanceof CrossFrameworkMappingProviderInterface ? $provider : null;
    }
}
