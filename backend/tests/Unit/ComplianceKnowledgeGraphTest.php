<?php

namespace Tests\Unit;

use App\Contracts\Compliance\CrossFrameworkMappingProviderInterface;
use App\Models\Compliance\ComplianceControl;
use App\Models\Compliance\ComplianceDomain;
use App\Models\Compliance\ComplianceRequirement;
use App\Models\Compliance\ComplianceSourceDocument;
use App\Services\Compliance\Graph\ComplianceCrossReferenceService;
use App\Services\Compliance\Graph\ComplianceKnowledgeGraphService;
use ReflectionMethod;
use Tests\TestCase;

/**
 * DB-free unit tests for the Knowledge Graph Layer. These assert the deterministic
 * contract invariants: empty/disabled cross-framework seam, UUID-only graph nodes,
 * bilingual titles, and provenance — without touching the database.
 */
class ComplianceKnowledgeGraphTest extends TestCase
{
    public function test_cross_framework_seam_is_unbound_this_sprint(): void
    {
        $this->assertFalse(app()->bound(CrossFrameworkMappingProviderInterface::class));
    }

    public function test_cross_reference_block_is_empty_and_disabled(): void
    {
        $service = new ComplianceCrossReferenceService();

        $block = $service->crossReferencesFor('control', 'ctrl-uuid-1', 'nca-ecc', '2-2024');

        $this->assertSame(['intra_framework' => [], 'cross_framework' => []], $block);
        $this->assertSame([], $service->crossFrameworkMappings('control', 'ctrl-uuid-1', 'nca-ecc', '2-2024'));
        $this->assertFalse($service->crossFrameworkSupportEnabled());
    }

    public function test_control_node_is_bilingual_and_uuid_only(): void
    {
        $node = $this->invokeNode('controlNode', $this->makeControl());

        foreach (['entity_type', 'uuid', 'code', 'display_code', 'normalized_code', 'title_en', 'title_ar', 'provenance'] as $key) {
            $this->assertArrayHasKey($key, $node);
        }

        $this->assertSame('control', $node['entity_type']);
        $this->assertSame('ctrl-uuid-1', $node['uuid']);
        $this->assertSame('1-1-1', $node['code']);
        $this->assertSame('Control EN', $node['title_en']);
        $this->assertSame('تحكم', $node['title_ar']);
        $this->assertSame('nca-ecc-2-2024', $node['provenance']['source_document_key']);
        $this->assertArrayNotHasKey('id', $node);
        $this->assertSame([], $this->scanNumericIdKeys($node));
    }

    public function test_requirement_node_is_bilingual_and_uuid_only(): void
    {
        $requirement = new ComplianceRequirement([
            'code' => '1-1-1-1', 'display_code' => '1-1-1-1', 'normalized_code' => '1-1-1-1',
            'title_en' => 'R EN', 'title_ar' => 'R AR',
            'requirement_text_en' => 'do', 'requirement_text_ar' => 'افعل',
            'official_reference' => 'ECC 1-1-1-1', 'source_reference' => 'p.14', 'source_page' => '14',
        ]);
        $requirement->uuid = 'req-uuid-1';
        $requirement->setRelation('sourceDocument', $this->makeSourceDocument());

        $node = $this->invokeNode('requirementNode', $requirement);

        $this->assertSame('requirement', $node['entity_type']);
        $this->assertSame('req-uuid-1', $node['uuid']);
        $this->assertSame('افعل', $node['requirement_text_ar']);
        $this->assertSame('nca-ecc-2-2024', $node['provenance']['source_document_key']);
        $this->assertSame([], $this->scanNumericIdKeys($node));
    }

    public function test_domain_node_is_bilingual_and_uuid_only(): void
    {
        $domain = new ComplianceDomain([
            'code' => '1', 'display_code' => '1', 'normalized_code' => '1',
            'title_en' => 'D EN', 'title_ar' => 'D AR',
            'official_reference' => 'ECC 1', 'source_reference' => 'p.8', 'source_page' => '8',
        ]);
        $domain->uuid = 'dom-uuid-1';
        $domain->setRelation('sourceDocument', $this->makeSourceDocument());

        $node = $this->invokeNode('domainNode', $domain);

        $this->assertSame('domain', $node['entity_type']);
        $this->assertSame('dom-uuid-1', $node['uuid']);
        $this->assertSame('D AR', $node['title_ar']);
        $this->assertSame('nca-ecc-2-2024', $node['provenance']['source_document_key']);
        $this->assertSame([], $this->scanNumericIdKeys($node));
    }

    /**
     * Invoke a private node formatter (withCounts=false → DB-free).
     *
     * @return array<string, mixed>
     */
    private function invokeNode(string $method, object $entity): array
    {
        $service = new ComplianceKnowledgeGraphService();
        $reflection = new ReflectionMethod($service, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($service, $entity, false);
    }

    private function makeControl(): ComplianceControl
    {
        $control = new ComplianceControl([
            'code' => '1-1-1', 'display_code' => '1-1-1', 'normalized_code' => '1-1-1',
            'title_en' => 'Control EN', 'title_ar' => 'تحكم',
            'official_reference' => 'ECC 1-1-1', 'source_reference' => 'p.12', 'source_page' => '12',
        ]);
        $control->uuid = 'ctrl-uuid-1';
        $control->setRelation('sourceDocument', $this->makeSourceDocument());

        return $control;
    }

    private function makeSourceDocument(): ComplianceSourceDocument
    {
        $doc = new ComplianceSourceDocument([
            'key' => 'nca-ecc-2-2024', 'title_en' => 'ECC Document', 'title_ar' => 'وثيقة الضوابط', 'source_reference' => 'NCA',
        ]);
        $doc->uuid = 'doc-uuid-1';

        return $doc;
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
