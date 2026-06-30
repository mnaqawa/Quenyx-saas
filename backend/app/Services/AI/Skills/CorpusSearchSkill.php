<?php

namespace App\Services\AI\Skills;

use App\DataTransferObjects\Ai\AiSkillRequest;
use App\DataTransferObjects\Ai\AiSkillResult;
use App\Exceptions\Ai\AiSkillException;
use App\Services\Compliance\Ai\ComplianceAiContextService;

/**
 * CorpusSearchSkill — searches the QCIF corpus and returns a ready-to-consume AI Context
 * payload (with citations + guardrails). It reuses the corpus AI Context service (which in turn
 * reuses ComplianceCorpusSearchService); it builds NO prompt, calls NO AI, and writes NO
 * database queries of its own.
 */
class CorpusSearchSkill extends AbstractAiSkill
{
    public function __construct(
        private readonly ComplianceAiContextService $contextService = new ComplianceAiContextService(),
    ) {}

    public function key(): string
    {
        return 'corpus_search';
    }

    public function displayName(): string
    {
        return 'Corpus Search';
    }

    public function description(): string
    {
        return 'Searches the QCIF compliance corpus and returns a cited AI Context payload (search, summary, or profile).';
    }

    public function supportedContextTypes(): array
    {
        return [
            'search_context',
            'corpus_summary',
            'control_profile',
            'domain_profile',
            'requirement_profile',
        ];
    }

    public function execute(AiSkillRequest $request): AiSkillResult
    {
        $contextType = $request->contextType ?? 'search_context';
        $framework = $request->frameworkKey;
        $release = $request->releaseCode;

        if ($framework === null || $framework === '' || $release === null || $release === '') {
            throw new AiSkillException('Corpus search requires a framework and release.', 'skill_missing_scope');
        }

        $envelope = $this->contextService->build($contextType, $framework, $release, $request->parameters);

        return new AiSkillResult(
            skillKey: $this->key(),
            contextType: $envelope['context_type'] ?? $contextType,
            payload: $envelope,
            citations: $envelope['citations'] ?? [],
            guardrails: $envelope['guardrails'] ?? $this->standardGuardrails(),
        );
    }

    protected function tags(): array
    {
        return ['corpus', 'search', 'ai-context'];
    }
}
