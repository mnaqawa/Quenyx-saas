<?php

namespace Tests\Unit;

use App\Contracts\Compliance\CrossFrameworkMappingProviderInterface;
use App\Contracts\Compliance\Mapping\CstMappingProviderInterface;
use App\Contracts\Compliance\Mapping\FrameworkMappingProviderInterface;
use App\Contracts\Compliance\Mapping\Iso27001MappingProviderInterface;
use App\Contracts\Compliance\Mapping\PdplMappingProviderInterface;
use App\Contracts\Compliance\Mapping\SamaMappingProviderInterface;
use App\Contracts\Compliance\Mapping\Soc2MappingProviderInterface;
use App\DataTransferObjects\Compliance\CrossFrameworkControlMapping;
use App\Enums\Compliance\MappingConfidence;
use App\Enums\Compliance\ObjectiveMappingType;
use Tests\TestCase;

/**
 * DB-free unit tests for the Cross-Framework Mapping Foundation contract: confidence basis
 * enum, the cross-framework DTO (UUID-only, no numeric scores), and the future provider
 * interfaces (declared but not bound this sprint).
 */
class ComplianceMappingFoundationTest extends TestCase
{
    public function test_confidence_is_a_basis_not_a_score(): void
    {
        $this->assertSame(['official', 'manual', 'derived'], array_map(
            fn (MappingConfidence $c) => $c->value,
            MappingConfidence::cases(),
        ));
    }

    public function test_no_future_mapping_provider_is_bound_this_sprint(): void
    {
        foreach ([
            CrossFrameworkMappingProviderInterface::class,
            FrameworkMappingProviderInterface::class,
            Iso27001MappingProviderInterface::class,
            SamaMappingProviderInterface::class,
            CstMappingProviderInterface::class,
            PdplMappingProviderInterface::class,
            Soc2MappingProviderInterface::class,
        ] as $contract) {
            $this->assertFalse(app()->bound($contract), "{$contract} must not be bound in Sprint 8.");
        }
    }

    public function test_per_framework_interfaces_extend_the_base_provider(): void
    {
        foreach ([
            Iso27001MappingProviderInterface::class,
            SamaMappingProviderInterface::class,
            CstMappingProviderInterface::class,
            PdplMappingProviderInterface::class,
            Soc2MappingProviderInterface::class,
        ] as $contract) {
            $this->assertContains(
                FrameworkMappingProviderInterface::class,
                class_implements($contract),
                "{$contract} must extend FrameworkMappingProviderInterface.",
            );
        }
    }

    public function test_cross_framework_mapping_dto_is_uuid_only_and_uses_confidence_basis(): void
    {
        $dto = new CrossFrameworkControlMapping(
            sourceFrameworkKey: 'nca-ecc',
            sourceReleaseCode: '2-2024',
            sourceControlUuid: 'src-uuid-1',
            sourceControlCode: '1-1-1',
            targetFrameworkKey: 'iso-27001',
            targetReleaseCode: '2022',
            targetControlUuid: 'tgt-uuid-1',
            targetControlCode: 'A.5.1',
            objectiveCode: 'OBJ-1',
            mappingType: ObjectiveMappingType::Equivalent,
            confidence: MappingConfidence::Manual,
            provenance: ['source_reference' => 'curated'],
        );

        $array = $dto->toArray();

        $this->assertSame('src-uuid-1', $array['source']['control_uuid']);
        $this->assertSame('tgt-uuid-1', $array['target']['control_uuid']);
        $this->assertSame('equivalent', $array['mapping_type']);
        $this->assertSame('manual', $array['confidence']);
        $this->assertContains($array['confidence'], ['official', 'manual', 'derived']);
        $this->assertSame([], $this->scanNumericIdKeys($array));
    }

    /**
     * @param  array<mixed>  $data
     * @return list<string>
     */
    private function scanNumericIdKeys(array $data, string $path = ''): array
    {
        $hits = [];
        foreach ($data as $key => $value) {
            $current = $path === '' ? (string) $key : $path.'.'.$key;
            if ($key === 'id' && (is_int($value) || (is_string($value) && ctype_digit($value)))) {
                $hits[] = $current;
            }
            if (is_array($value)) {
                $hits = array_merge($hits, $this->scanNumericIdKeys($value, $current));
            }
        }

        return $hits;
    }
}
