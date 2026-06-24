<?php

namespace App\DataTransferObjects\Compliance;

use App\Enums\Compliance\MappingConfidence;
use App\Enums\Compliance\ObjectiveMappingType;

/**
 * Canonical, UUID-only data carrier for a single cross-framework control mapping.
 *
 * This is the shape a future per-framework provider (see
 * App\Contracts\Compliance\Mapping\FrameworkMappingProviderInterface) will return. QCIF
 * Sprint 8 produces NO instances of this DTO (no cross-framework data exists yet); it defines
 * the contract so onboarding a framework does not change the API shape.
 *
 * Invariants: identifiers are UUIDs/codes only (never numeric ids); confidence is a BASIS
 * (official|manual|derived), never a numeric score.
 */
final readonly class CrossFrameworkControlMapping
{
    /**
     * @param  array<string, mixed>  $provenance
     */
    public function __construct(
        public string $sourceFrameworkKey,
        public string $sourceReleaseCode,
        public string $sourceControlUuid,
        public string $sourceControlCode,
        public string $targetFrameworkKey,
        public string $targetReleaseCode,
        public string $targetControlUuid,
        public string $targetControlCode,
        public ?string $objectiveCode,
        public ObjectiveMappingType $mappingType,
        public MappingConfidence $confidence,
        public array $provenance = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source' => [
                'framework_key' => $this->sourceFrameworkKey,
                'release_code' => $this->sourceReleaseCode,
                'control_uuid' => $this->sourceControlUuid,
                'control_code' => $this->sourceControlCode,
            ],
            'target' => [
                'framework_key' => $this->targetFrameworkKey,
                'release_code' => $this->targetReleaseCode,
                'control_uuid' => $this->targetControlUuid,
                'control_code' => $this->targetControlCode,
            ],
            'objective_code' => $this->objectiveCode,
            'mapping_type' => $this->mappingType->value,
            'confidence' => $this->confidence->value,
            'provenance' => $this->provenance,
        ];
    }
}
