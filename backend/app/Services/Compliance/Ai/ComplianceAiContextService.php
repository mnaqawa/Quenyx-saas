<?php

namespace App\Services\Compliance\Ai;

use App\Exceptions\ComplianceAiContextException;
use App\Exceptions\ComplianceCorpusNotFoundException;
use App\Http\Resources\Compliance\ComplianceCorpusRevisionResource;
use App\Http\Resources\Compliance\ComplianceFrameworkReleaseResource;
use App\Http\Resources\Compliance\ComplianceFrameworkResource;
use App\Models\Compliance\ComplianceControl;
use App\Models\Compliance\ComplianceCorpusRevision;
use App\Models\Compliance\ComplianceDomain;
use App\Models\Compliance\ComplianceFrameworkRelease;
use App\Models\Compliance\ComplianceRequirement;
use App\Models\Compliance\ComplianceSourceDocument;
use App\Services\Compliance\ComplianceCorpusNavigationService;
use App\Services\Compliance\ComplianceCorpusQueryService;
use App\Services\Compliance\ComplianceCorpusSearchService;
use Illuminate\Support\Collection;

/**
 * Orchestrates assembly of deterministic AI Consumption Contract payloads.
 *
 * This is the single entry point for the contract layer. It resolves the active corpus
 * revision, builds a self-contained prompt context payload, attaches citations, enforces
 * guardrails/validation, and returns a structured envelope ready for a FUTURE AI/RAG
 * consumer.
 *
 * GUARANTEES:
 *  - No OpenAI / LLM / RAG / embedding / vector call is ever made.
 *  - No tenant data and no evidence is ever included.
 *  - Every payload is rejected unless it has valid citations and bilingual text.
 *  - Output uses UUIDs only — never numeric database identifiers.
 */
class ComplianceAiContextService
{
    public function __construct(
        private readonly ComplianceCorpusQueryService $queryService = new ComplianceCorpusQueryService(),
        private readonly ComplianceCorpusNavigationService $navigationService = new ComplianceCorpusNavigationService(),
        private readonly ComplianceCorpusSearchService $searchService = new ComplianceCorpusSearchService(),
        private readonly ComplianceAiPromptContextBuilder $promptBuilder = new ComplianceAiPromptContextBuilder(),
        private readonly ComplianceAiCitationBuilder $citationBuilder = new ComplianceAiCitationBuilder(),
        private readonly ComplianceAiGuardrailService $guardrails = new ComplianceAiGuardrailService(),
    ) {}

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     *
     * @throws ComplianceAiContextException
     * @throws ComplianceCorpusNotFoundException
     * @throws \InvalidArgumentException
     */
    public function build(string $contextType, string $frameworkKey, string $releaseCode, array $params = []): array
    {
        $this->guardrails->assertSupportedContextType($contextType);

        return match ($contextType) {
            ComplianceAiGuardrailService::CONTEXT_CORPUS_SUMMARY => $this->buildCorpusSummary($frameworkKey, $releaseCode),
            ComplianceAiGuardrailService::CONTEXT_DOMAIN_PROFILE => $this->buildDomainProfile($frameworkKey, $releaseCode, (string) ($params['domainCode'] ?? '')),
            ComplianceAiGuardrailService::CONTEXT_CONTROL_PROFILE => $this->buildControlProfile($frameworkKey, $releaseCode, (string) ($params['controlCode'] ?? '')),
            ComplianceAiGuardrailService::CONTEXT_REQUIREMENT_PROFILE => $this->buildRequirementProfile($frameworkKey, $releaseCode, (string) ($params['requirementCode'] ?? '')),
            ComplianceAiGuardrailService::CONTEXT_SEARCH_CONTEXT => $this->buildSearchContext($frameworkKey, $releaseCode, (string) ($params['query'] ?? ''), $params['limit'] ?? null),
            default => throw new ComplianceAiContextException("Unsupported AI context type: {$contextType}.", 'unsupported_context_type'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCorpusSummary(string $frameworkKey, string $releaseCode): array
    {
        $generatedAt = $this->now();
        $summary = $this->queryService->getSummary($frameworkKey, $releaseCode);

        /** @var ComplianceFrameworkRelease $release */
        $release = $summary['release'];
        /** @var ComplianceCorpusRevision $revision */
        $revision = $summary['active_revision'];
        $framework = $release->framework;
        /** @var Collection<int, ComplianceSourceDocument> $sourceDocuments */
        $sourceDocuments = $summary['source_documents'];

        $payload = $this->promptBuilder->corpusSummary(
            $framework,
            $release,
            $revision,
            $summary['counts'],
            $sourceDocuments,
            $this->guardrails->standardGuardrails(),
            $generatedAt,
        );

        $citations = $sourceDocuments
            ->map(fn (ComplianceSourceDocument $doc) => $this->citationBuilder->forSourceDocument($doc))
            ->values()
            ->all();

        $this->guardrails->assertBilingualText([
            'framework.title' => [$framework->title_en, $framework->title_ar],
        ]);
        $this->guardrails->assertCitationsValid($citations);

        return $this->envelope(ComplianceAiGuardrailService::CONTEXT_CORPUS_SUMMARY, $framework, $release, $revision, $payload, $citations, $generatedAt);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDomainProfile(string $frameworkKey, string $releaseCode, string $domainCode): array
    {
        $generatedAt = $this->now();
        $release = $this->queryService->resolveRelease($frameworkKey, $releaseCode);
        $revision = $this->queryService->getActiveRevision($release);
        $framework = $release->framework;

        $payload = $this->navigationService->getDomainWithControls($frameworkKey, $releaseCode, $domainCode);
        /** @var ComplianceDomain $domain */
        $domain = $payload['domain'];
        /** @var Collection<int, ComplianceControl> $controls */
        $controls = $payload['controls'];
        $sourceDocuments = $this->releaseSourceDocuments($release);

        $contextPayload = $this->promptBuilder->domainProfile(
            $framework,
            $release,
            $revision,
            $domain,
            $controls,
            $sourceDocuments,
            $this->guardrails->standardGuardrails(),
            $generatedAt,
        );

        $citations = [$this->citationBuilder->forDomain($domain)];
        foreach ($controls as $control) {
            if ($this->citationBuilder->entityHasSourceDocument($control)) {
                $citations[] = $this->citationBuilder->forControl($control);
            }
        }

        $this->guardrails->assertBilingualText([
            'domain.title' => [$domain->title_en, $domain->title_ar],
        ]);
        $this->guardrails->assertCitationsValid($citations);

        return $this->envelope(ComplianceAiGuardrailService::CONTEXT_DOMAIN_PROFILE, $framework, $release, $revision, $contextPayload, $citations, $generatedAt);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildControlProfile(string $frameworkKey, string $releaseCode, string $controlCode): array
    {
        $generatedAt = $this->now();
        $profile = $this->queryService->getControlProfile($frameworkKey, $releaseCode, $controlCode);

        /** @var ComplianceFrameworkRelease $release */
        $release = $profile['release'];
        /** @var ComplianceCorpusRevision $revision */
        $revision = $profile['revision'];
        $framework = $release->framework;
        /** @var ComplianceDomain $domain */
        $domain = $profile['domain'];
        /** @var ComplianceControl $control */
        $control = $profile['control'];
        /** @var Collection<int, ComplianceRequirement> $requirements */
        $requirements = $profile['requirements'];
        $sourceDocuments = $this->releaseSourceDocuments($release);

        $contextPayload = $this->promptBuilder->controlProfile(
            $framework,
            $release,
            $revision,
            $domain,
            $control,
            $requirements,
            $sourceDocuments,
            $this->guardrails->standardGuardrails(),
            $generatedAt,
        );

        $citations = [$this->citationBuilder->forControl($control)];
        if ($this->citationBuilder->entityHasSourceDocument($domain)) {
            $citations[] = $this->citationBuilder->forDomain($domain);
        }
        foreach ($requirements as $requirement) {
            if ($this->citationBuilder->entityHasSourceDocument($requirement)) {
                $citations[] = $this->citationBuilder->forRequirement($requirement);
            }
        }

        $this->guardrails->assertBilingualText([
            'control.title' => [$control->title_en, $control->title_ar],
        ]);
        $this->guardrails->assertCitationsValid($citations);

        return $this->envelope(ComplianceAiGuardrailService::CONTEXT_CONTROL_PROFILE, $framework, $release, $revision, $contextPayload, $citations, $generatedAt);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRequirementProfile(string $frameworkKey, string $releaseCode, string $requirementCode): array
    {
        $generatedAt = $this->now();
        $release = $this->queryService->resolveRelease($frameworkKey, $releaseCode);
        $revision = $this->queryService->getActiveRevision($release);
        $framework = $release->framework;

        $requirement = $this->findRequirement($release, $requirementCode);
        $control = $requirement->control;
        $domain = $control?->domain;
        $sourceDocuments = $this->releaseSourceDocuments($release);

        $contextPayload = $this->promptBuilder->requirementProfile(
            $framework,
            $release,
            $revision,
            $requirement,
            $control,
            $domain,
            $sourceDocuments,
            $this->guardrails->standardGuardrails(),
            $generatedAt,
        );

        $citations = [$this->citationBuilder->forRequirement($requirement)];
        if ($control !== null && $this->citationBuilder->entityHasSourceDocument($control)) {
            $citations[] = $this->citationBuilder->forControl($control);
        }
        if ($domain !== null && $this->citationBuilder->entityHasSourceDocument($domain)) {
            $citations[] = $this->citationBuilder->forDomain($domain);
        }

        $this->guardrails->assertBilingualText([
            'requirement.text' => [$requirement->requirement_text_en, $requirement->requirement_text_ar],
        ]);
        $this->guardrails->assertCitationsValid($citations);

        return $this->envelope(ComplianceAiGuardrailService::CONTEXT_REQUIREMENT_PROFILE, $framework, $release, $revision, $contextPayload, $citations, $generatedAt);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSearchContext(string $frameworkKey, string $releaseCode, string $query, mixed $limit): array
    {
        $generatedAt = $this->now();
        $limitInt = $limit !== null ? (int) $limit : null;

        // Throws InvalidArgumentException for queries that are too short.
        $results = $this->searchService->search($frameworkKey, $releaseCode, $query, $limitInt);

        $release = $this->queryService->resolveRelease($frameworkKey, $releaseCode);
        $revision = $this->queryService->getActiveRevision($release);
        $framework = $release->framework;
        $sourceDocuments = $this->releaseSourceDocuments($release);

        $contextPayload = $this->promptBuilder->searchContext(
            $framework,
            $release,
            $revision,
            $results,
            $sourceDocuments,
            $this->guardrails->standardGuardrails(),
            $generatedAt,
        );

        $citations = [];
        foreach ($results['domains'] as $domain) {
            if ($this->citationBuilder->entityHasSourceDocument($domain)) {
                $citations[] = $this->citationBuilder->forDomain($domain);
            }
        }
        foreach ($results['controls'] as $control) {
            if ($this->citationBuilder->entityHasSourceDocument($control)) {
                $citations[] = $this->citationBuilder->forControl($control);
            }
        }
        foreach ($results['requirements'] as $requirement) {
            if ($this->citationBuilder->entityHasSourceDocument($requirement)) {
                $citations[] = $this->citationBuilder->forRequirement($requirement);
            }
        }

        // A search may legitimately match nothing; fall back to citing the official source
        // documents of the release so the contract invariant (>= 1 citation) always holds.
        if ($citations === []) {
            foreach ($sourceDocuments as $document) {
                $citations[] = $this->citationBuilder->forSourceDocument($document);
            }
        }

        $this->guardrails->assertCitationsValid($citations);

        return $this->envelope(ComplianceAiGuardrailService::CONTEXT_SEARCH_CONTEXT, $framework, $release, $revision, $contextPayload, $citations, $generatedAt);
    }

    /**
     * @return Collection<int, ComplianceSourceDocument>
     */
    private function releaseSourceDocuments(ComplianceFrameworkRelease $release): Collection
    {
        return ComplianceSourceDocument::query()
            ->where('framework_release_id', $release->id)
            ->orderBy('key')
            ->get();
    }

    private function findRequirement(ComplianceFrameworkRelease $release, string $requirementCode): ComplianceRequirement
    {
        if (trim($requirementCode) === '') {
            throw new ComplianceAiContextException('Requirement code is required.', 'requirement_code_required');
        }

        $requirement = ComplianceRequirement::query()
            ->where('framework_release_id', $release->id)
            ->where(function ($query) use ($requirementCode): void {
                $query->where('code', $requirementCode)
                    ->orWhere('display_code', $requirementCode)
                    ->orWhere('normalized_code', $requirementCode);
            })
            ->with(['sourceDocument', 'control.sourceDocument', 'control.domain.sourceDocument'])
            ->first();

        if ($requirement === null) {
            throw new ComplianceCorpusNotFoundException("Requirement not found: {$requirementCode}.");
        }

        return $requirement;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<array<string, mixed>>  $citations
     * @return array<string, mixed>
     */
    private function envelope(
        string $contextType,
        \App\Models\Compliance\ComplianceFramework $framework,
        ComplianceFrameworkRelease $release,
        ComplianceCorpusRevision $revision,
        array $payload,
        array $citations,
        string $generatedAt,
    ): array {
        return [
            'context_type' => $contextType,
            'framework' => ComplianceFrameworkResource::make($framework)->resolve(),
            'release' => ComplianceFrameworkReleaseResource::make($release)->resolve(),
            'revision' => ComplianceCorpusRevisionResource::make($revision)->resolve(),
            'payload' => $payload,
            'citations' => $citations,
            'guardrails' => $this->guardrails->standardGuardrails(),
            'generated_at' => $generatedAt,
        ];
    }

    private function now(): string
    {
        return now()->toIso8601String();
    }
}
