<?php

namespace Tests\Unit;

use App\Exceptions\ComplianceAiContextException;
use App\Models\Compliance\ComplianceControl;
use App\Models\Compliance\ComplianceCorpusRevision;
use App\Models\Compliance\ComplianceDomain;
use App\Models\Compliance\ComplianceFramework;
use App\Models\Compliance\ComplianceFrameworkRelease;
use App\Models\Compliance\ComplianceRequirement;
use App\Models\Compliance\ComplianceSourceDocument;
use App\Services\Compliance\Ai\ComplianceAiCitationBuilder;
use App\Services\Compliance\Ai\ComplianceAiGuardrailService;
use App\Services\Compliance\Ai\ComplianceAiPromptContextBuilder;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Unit tests for the AI Consumption Contract Layer guardrails, citation builder, and
 * prompt context builder. These exercise the deterministic, DB-free contract logic and
 * assert the invariants: standard guardrails, citation completeness, bilingual text
 * enforcement, UUID-only output, and "no AI execution" provenance flags.
 */
class ComplianceAiContractTest extends TestCase
{
    public function test_standard_guardrails_block_is_complete_and_all_true(): void
    {
        $guardrails = new ComplianceAiGuardrailService();
        $block = $guardrails->standardGuardrails();

        $this->assertSame([
            'use_only_provided_context',
            'do_not_invent_controls',
            'cite_every_claim',
            'preserve_official_wording',
            'bilingual_required',
            'no_legal_advice_disclaimer_required',
            'tenant_data_not_included',
            'evidence_not_included',
        ], array_keys($block));

        foreach ($block as $value) {
            $this->assertTrue($value);
        }
    }

    public function test_unsupported_context_type_is_rejected(): void
    {
        $this->expectException(ComplianceAiContextException::class);
        (new ComplianceAiGuardrailService())->assertSupportedContextType('bogus_type');
    }

    public function test_empty_citations_are_rejected(): void
    {
        $this->expectException(ComplianceAiContextException::class);
        (new ComplianceAiGuardrailService())->assertCitationsValid([]);
    }

    public function test_citation_without_source_document_is_rejected(): void
    {
        $this->expectException(ComplianceAiContextException::class);
        (new ComplianceAiGuardrailService())->assertCitationsValid([
            ['source_document_key' => null, 'entity_code' => '1-1-1'],
        ]);
    }

    public function test_missing_arabic_text_is_rejected(): void
    {
        $this->expectException(ComplianceAiContextException::class);
        (new ComplianceAiGuardrailService())->assertBilingualText([
            'control.title' => ['English only', null],
        ]);
    }

    public function test_valid_bilingual_text_passes(): void
    {
        (new ComplianceAiGuardrailService())->assertBilingualText([
            'control.title' => ['English', 'عربى'],
        ]);

        $this->assertTrue(true);
    }

    public function test_control_citation_contains_all_required_fields(): void
    {
        $control = $this->makeControl();
        $citation = (new ComplianceAiCitationBuilder())->forControl($control);

        foreach ([
            'source_document_key', 'source_title_en', 'source_title_ar', 'official_reference',
            'source_reference', 'source_page', 'entity_uuid', 'entity_type', 'entity_code',
        ] as $field) {
            $this->assertArrayHasKey($field, $citation);
        }

        $this->assertSame('nca-ecc-2-2024', $citation['source_document_key']);
        $this->assertSame('control', $citation['entity_type']);
        $this->assertSame('ctrl-uuid-1', $citation['entity_uuid']);
        $this->assertSame('1-1-1', $citation['entity_code']);
    }

    public function test_control_profile_payload_is_bilingual_and_uuid_only(): void
    {
        $guardrails = new ComplianceAiGuardrailService();
        $prompt = new ComplianceAiPromptContextBuilder();

        $framework = new ComplianceFramework(['key' => 'nca-ecc', 'code' => 'ECC', 'title_en' => 'EN', 'title_ar' => 'AR']);
        $framework->uuid = 'fw-1';
        $release = new ComplianceFrameworkRelease(['release_code' => 'ecc-2-2024', 'version_code' => '2:2024', 'title_en' => 'EN', 'title_ar' => 'AR']);
        $release->uuid = 'rel-1';
        $release->setRelation('framework', $framework);
        $revision = new ComplianceCorpusRevision(['revision_number' => 1, 'checksum_sha256' => 'abc']);
        $revision->uuid = 'rev-1';

        $doc = $this->makeSourceDocument();
        $domain = new ComplianceDomain(['code' => '1', 'display_code' => '1', 'title_en' => 'D EN', 'title_ar' => 'D AR']);
        $domain->uuid = 'dom-1';
        $domain->setRelation('sourceDocument', $doc);
        $control = $this->makeControl();
        $requirement = new ComplianceRequirement([
            'code' => '1-1-1-1', 'display_code' => '1-1-1-1', 'title_en' => 'R EN', 'title_ar' => 'R AR',
            'requirement_text_en' => 'do', 'requirement_text_ar' => 'افعل',
        ]);
        $requirement->uuid = 'req-1';
        $requirement->setRelation('sourceDocument', $doc);

        $payload = $prompt->controlProfile(
            $framework,
            $release,
            $revision,
            $domain,
            $control,
            new Collection([$requirement]),
            new Collection([$doc]),
            $guardrails->standardGuardrails(),
            now()->toIso8601String(),
        );

        $this->assertSame('control_profile', $payload['context_type']);
        $this->assertArrayHasKey('title_en', $payload['control']);
        $this->assertArrayHasKey('title_ar', $payload['control']);
        $this->assertFalse($payload['provenance']['ai_executed']);
        $this->assertFalse($payload['provenance']['tenant_data_included']);
        $this->assertFalse($payload['provenance']['evidence_included']);
        $this->assertSame([], $this->scanNumericIdKeys($payload));
    }

    private function makeControl(): ComplianceControl
    {
        $control = new ComplianceControl([
            'code' => '1-1-1', 'display_code' => '1-1-1', 'title_en' => 'Control EN', 'title_ar' => 'تحكم',
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
