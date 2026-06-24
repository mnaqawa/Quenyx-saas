<?php

namespace App\Services\Compliance\Ai;

use App\Models\Compliance\ComplianceControl;
use App\Models\Compliance\ComplianceCorpusRevision;
use App\Models\Compliance\ComplianceDomain;
use App\Models\Compliance\ComplianceFramework;
use App\Models\Compliance\ComplianceFrameworkRelease;
use App\Models\Compliance\ComplianceRequirement;
use App\Models\Compliance\ComplianceSourceDocument;
use Illuminate\Support\Collection;

/**
 * Assembles deterministic, self-contained "AI-ready" payloads from corpus entities.
 *
 * Each payload is a structured JSON object that a FUTURE AI/RAG consumer can read as a
 * single unit of grounded context. No AI call is made here. Payloads contain only the
 * official corpus content (bilingual text + provenance), the active revision, the source
 * documents, the guardrails block, and a generated_at stamp. They contain NO tenant data
 * and NO evidence.
 */
class ComplianceAiPromptContextBuilder
{
    /**
     * @param  array<string, bool>  $guardrails
     * @param  iterable<ComplianceSourceDocument>  $sourceDocuments
     * @return array<string, mixed>
     */
    public function controlProfile(
        ComplianceFramework $framework,
        ComplianceFrameworkRelease $release,
        ComplianceCorpusRevision $revision,
        ComplianceDomain $domain,
        ComplianceControl $control,
        Collection $requirements,
        iterable $sourceDocuments,
        array $guardrails,
        string $generatedAt,
    ): array {
        return [
            'context_type' => ComplianceAiGuardrailService::CONTEXT_CONTROL_PROFILE,
            'framework' => $this->frameworkBlock($framework),
            'release' => $this->releaseBlock($release),
            'active_revision' => $this->revisionBlock($revision),
            'domain' => $this->domainText($domain),
            'control' => $this->controlText($control),
            'requirements' => $requirements->map(fn (ComplianceRequirement $r) => $this->requirementText($r))->values()->all(),
            'source_documents' => $this->sourceDocumentBlocks($sourceDocuments),
            'entities' => $this->entityIndex([
                ['type' => 'domain', 'entity' => $domain],
                ['type' => 'control', 'entity' => $control],
                ...$requirements->map(fn (ComplianceRequirement $r) => ['type' => 'requirement', 'entity' => $r])->all(),
            ]),
            'provenance' => $this->provenanceBlock($framework, $release, $revision, $generatedAt),
            'guardrails' => $guardrails,
            'generated_at' => $generatedAt,
        ];
    }

    /**
     * @param  array<string, bool>  $guardrails
     * @param  iterable<ComplianceSourceDocument>  $sourceDocuments
     * @return array<string, mixed>
     */
    public function domainProfile(
        ComplianceFramework $framework,
        ComplianceFrameworkRelease $release,
        ComplianceCorpusRevision $revision,
        ComplianceDomain $domain,
        Collection $controls,
        iterable $sourceDocuments,
        array $guardrails,
        string $generatedAt,
    ): array {
        return [
            'context_type' => ComplianceAiGuardrailService::CONTEXT_DOMAIN_PROFILE,
            'framework' => $this->frameworkBlock($framework),
            'release' => $this->releaseBlock($release),
            'active_revision' => $this->revisionBlock($revision),
            'domain' => $this->domainText($domain),
            'controls' => $controls->map(fn (ComplianceControl $c) => $this->controlText($c))->values()->all(),
            'source_documents' => $this->sourceDocumentBlocks($sourceDocuments),
            'entities' => $this->entityIndex([
                ['type' => 'domain', 'entity' => $domain],
                ...$controls->map(fn (ComplianceControl $c) => ['type' => 'control', 'entity' => $c])->all(),
            ]),
            'provenance' => $this->provenanceBlock($framework, $release, $revision, $generatedAt),
            'guardrails' => $guardrails,
            'generated_at' => $generatedAt,
        ];
    }

    /**
     * @param  array<string, bool>  $guardrails
     * @param  iterable<ComplianceSourceDocument>  $sourceDocuments
     * @return array<string, mixed>
     */
    public function requirementProfile(
        ComplianceFramework $framework,
        ComplianceFrameworkRelease $release,
        ComplianceCorpusRevision $revision,
        ComplianceRequirement $requirement,
        ?ComplianceControl $control,
        ?ComplianceDomain $domain,
        iterable $sourceDocuments,
        array $guardrails,
        string $generatedAt,
    ): array {
        $entities = [['type' => 'requirement', 'entity' => $requirement]];
        if ($control !== null) {
            $entities[] = ['type' => 'control', 'entity' => $control];
        }
        if ($domain !== null) {
            $entities[] = ['type' => 'domain', 'entity' => $domain];
        }

        return [
            'context_type' => ComplianceAiGuardrailService::CONTEXT_REQUIREMENT_PROFILE,
            'framework' => $this->frameworkBlock($framework),
            'release' => $this->releaseBlock($release),
            'active_revision' => $this->revisionBlock($revision),
            'domain' => $domain !== null ? $this->domainText($domain) : null,
            'control' => $control !== null ? $this->controlText($control) : null,
            'requirement' => $this->requirementText($requirement),
            'source_documents' => $this->sourceDocumentBlocks($sourceDocuments),
            'entities' => $this->entityIndex($entities),
            'provenance' => $this->provenanceBlock($framework, $release, $revision, $generatedAt),
            'guardrails' => $guardrails,
            'generated_at' => $generatedAt,
        ];
    }

    /**
     * @param  array<string, int>  $counts
     * @param  array<string, bool>  $guardrails
     * @param  iterable<ComplianceSourceDocument>  $sourceDocuments
     * @return array<string, mixed>
     */
    public function corpusSummary(
        ComplianceFramework $framework,
        ComplianceFrameworkRelease $release,
        ComplianceCorpusRevision $revision,
        array $counts,
        iterable $sourceDocuments,
        array $guardrails,
        string $generatedAt,
    ): array {
        return [
            'context_type' => ComplianceAiGuardrailService::CONTEXT_CORPUS_SUMMARY,
            'framework' => $this->frameworkBlock($framework),
            'release' => $this->releaseBlock($release),
            'active_revision' => $this->revisionBlock($revision),
            'counts' => $counts,
            'source_documents' => $this->sourceDocumentBlocks($sourceDocuments),
            'entities' => [],
            'provenance' => $this->provenanceBlock($framework, $release, $revision, $generatedAt),
            'guardrails' => $guardrails,
            'generated_at' => $generatedAt,
        ];
    }

    /**
     * @param  array{query: string, limit: int, domains: Collection, controls: Collection, requirements: Collection}  $results
     * @param  array<string, bool>  $guardrails
     * @param  iterable<ComplianceSourceDocument>  $sourceDocuments
     * @return array<string, mixed>
     */
    public function searchContext(
        ComplianceFramework $framework,
        ComplianceFrameworkRelease $release,
        ComplianceCorpusRevision $revision,
        array $results,
        iterable $sourceDocuments,
        array $guardrails,
        string $generatedAt,
    ): array {
        /** @var Collection<int, ComplianceDomain> $domains */
        $domains = $results['domains'];
        /** @var Collection<int, ComplianceControl> $controls */
        $controls = $results['controls'];
        /** @var Collection<int, ComplianceRequirement> $requirements */
        $requirements = $results['requirements'];

        $entities = [
            ...$domains->map(fn (ComplianceDomain $d) => ['type' => 'domain', 'entity' => $d])->all(),
            ...$controls->map(fn (ComplianceControl $c) => ['type' => 'control', 'entity' => $c])->all(),
            ...$requirements->map(fn (ComplianceRequirement $r) => ['type' => 'requirement', 'entity' => $r])->all(),
        ];

        return [
            'context_type' => ComplianceAiGuardrailService::CONTEXT_SEARCH_CONTEXT,
            'framework' => $this->frameworkBlock($framework),
            'release' => $this->releaseBlock($release),
            'active_revision' => $this->revisionBlock($revision),
            'query' => $results['query'],
            'limit' => $results['limit'],
            'result_counts' => [
                'domains' => $domains->count(),
                'controls' => $controls->count(),
                'requirements' => $requirements->count(),
            ],
            'results' => [
                'domains' => $domains->map(fn (ComplianceDomain $d) => $this->domainText($d))->values()->all(),
                'controls' => $controls->map(fn (ComplianceControl $c) => $this->controlText($c))->values()->all(),
                'requirements' => $requirements->map(fn (ComplianceRequirement $r) => $this->requirementText($r))->values()->all(),
            ],
            'source_documents' => $this->sourceDocumentBlocks($sourceDocuments),
            'entities' => $this->entityIndex($entities),
            'provenance' => $this->provenanceBlock($framework, $release, $revision, $generatedAt),
            'guardrails' => $guardrails,
            'generated_at' => $generatedAt,
        ];
    }

    // -------------------------------------------------------------------------
    // Entity text blocks (bilingual + provenance)
    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function frameworkBlock(ComplianceFramework $framework): array
    {
        return [
            'uuid' => $framework->uuid,
            'key' => $framework->key,
            'code' => $framework->code,
            'title_en' => $framework->title_en,
            'title_ar' => $framework->title_ar,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function releaseBlock(ComplianceFrameworkRelease $release): array
    {
        return [
            'uuid' => $release->uuid,
            'release_code' => $release->release_code,
            'version_code' => $release->version_code,
            'title_en' => $release->title_en,
            'title_ar' => $release->title_ar,
            'stable_ref' => $release->stableRef(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function revisionBlock(ComplianceCorpusRevision $revision): array
    {
        return [
            'uuid' => $revision->uuid,
            'revision_number' => $revision->revision_number,
            'status' => $revision->status?->value,
            'checksum_sha256' => $revision->checksum_sha256,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function domainText(ComplianceDomain $domain): array
    {
        return [
            'uuid' => $domain->uuid,
            'code' => $domain->code,
            'display_code' => $domain->display_code,
            'title_en' => $domain->title_en,
            'title_ar' => $domain->title_ar,
            'description_en' => $domain->description_en,
            'description_ar' => $domain->description_ar,
            'provenance' => $this->entityProvenance($domain),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function controlText(ComplianceControl $control): array
    {
        return [
            'uuid' => $control->uuid,
            'code' => $control->code,
            'display_code' => $control->display_code,
            'title_en' => $control->title_en,
            'title_ar' => $control->title_ar,
            'description_en' => $control->description_en,
            'description_ar' => $control->description_ar,
            'control_type' => $control->control_type?->value,
            'provenance' => $this->entityProvenance($control),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function requirementText(ComplianceRequirement $requirement): array
    {
        return [
            'uuid' => $requirement->uuid,
            'code' => $requirement->code,
            'display_code' => $requirement->display_code,
            'title_en' => $requirement->title_en,
            'title_ar' => $requirement->title_ar,
            'description_en' => $requirement->description_en,
            'description_ar' => $requirement->description_ar,
            'requirement_text_en' => $requirement->requirement_text_en,
            'requirement_text_ar' => $requirement->requirement_text_ar,
            'provenance' => $this->entityProvenance($requirement),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function entityProvenance(
        ComplianceControl|ComplianceDomain|ComplianceRequirement $entity,
    ): array {
        $document = $entity->relationLoaded('sourceDocument')
            ? $entity->sourceDocument
            : $entity->sourceDocument()->getResults();

        return [
            'source_document_key' => $document?->key,
            'source_reference' => $entity->source_reference,
            'source_page' => $entity->source_page,
            'official_reference' => $entity->official_reference,
        ];
    }

    /**
     * @param  iterable<ComplianceSourceDocument>  $sourceDocuments
     * @return list<array<string, mixed>>
     */
    private function sourceDocumentBlocks(iterable $sourceDocuments): array
    {
        $blocks = [];
        foreach ($sourceDocuments as $document) {
            $blocks[] = [
                'uuid' => $document->uuid,
                'key' => $document->key,
                'title_en' => $document->title_en,
                'title_ar' => $document->title_ar,
                'document_type' => $document->document_type?->value,
                'language' => $document->language?->value,
                'source_url' => $document->source_url,
                'official_file_name' => $document->official_file_name,
                'checksum_sha256' => $document->checksum_sha256,
                'source_reference' => $document->source_reference,
                'publication_date' => $document->publication_date?->toDateString(),
                'effective_date' => $document->effective_date?->toDateString(),
            ];
        }

        return $blocks;
    }

    /**
     * @param  list<array{type: string, entity: ComplianceControl|ComplianceDomain|ComplianceRequirement}>  $items
     * @return list<array<string, mixed>>
     */
    private function entityIndex(array $items): array
    {
        $index = [];
        foreach ($items as $item) {
            $entity = $item['entity'];
            $index[] = [
                'entity_type' => $item['type'],
                'entity_uuid' => $entity->uuid,
                'entity_code' => $entity->display_code ?? $entity->code,
            ];
        }

        return $index;
    }

    /**
     * @return array<string, mixed>
     */
    private function provenanceBlock(
        ComplianceFramework $framework,
        ComplianceFrameworkRelease $release,
        ComplianceCorpusRevision $revision,
        string $generatedAt,
    ): array {
        return [
            'framework_key' => $framework->key,
            'release_code' => $release->version_code,
            'revision_uuid' => $revision->uuid,
            'revision_number' => $revision->revision_number,
            'checksum_sha256' => $revision->checksum_sha256,
            'generated_at' => $generatedAt,
            'tenant_data_included' => false,
            'evidence_included' => false,
            'ai_executed' => false,
        ];
    }
}
