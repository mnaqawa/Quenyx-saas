<?php

namespace App\Services\AI\Skills;

use App\DataTransferObjects\Ai\AiSkillRequest;
use App\DataTransferObjects\Ai\AiSkillResult;
use App\Exceptions\Ai\AiSkillException;
use App\Services\Compliance\Graph\ComplianceKnowledgeGraphService;

/**
 * KnowledgeGraphSkill — expands the intra-framework knowledge graph for an entity and returns
 * its node, ancestors, descendants, siblings (related controls), and cross-references. Reuses
 * ComplianceKnowledgeGraphService only; no prompt, no AI, no own DB queries.
 */
class KnowledgeGraphSkill extends AbstractAiSkill
{
    public function __construct(
        private readonly ComplianceKnowledgeGraphService $graph = new ComplianceKnowledgeGraphService(),
    ) {}

    public function key(): string
    {
        return 'knowledge_graph';
    }

    public function displayName(): string
    {
        return 'Knowledge Graph';
    }

    public function description(): string
    {
        return 'Expands the intra-framework graph: node, ancestors, descendants, siblings, and related controls.';
    }

    public function supportedContextTypes(): array
    {
        return [
            'graph_context',
            'framework_context',
            'domain_context',
            'control_context',
            'requirement_context',
        ];
    }

    public function execute(AiSkillRequest $request): AiSkillResult
    {
        $framework = $request->frameworkKey;
        $release = $request->releaseCode;

        if ($framework === null || $framework === '' || $release === null || $release === '') {
            throw new AiSkillException('Knowledge graph expansion requires a framework and release.', 'skill_missing_scope');
        }

        $entityType = $request->stringParam('entity_type', 'control');
        $code = $request->stringParam('code')
            ?? $request->stringParam('controlCode')
            ?? $request->stringParam('domainCode')
            ?? $request->stringParam('requirementCode');

        $payload = match ($entityType) {
            'framework' => $this->graph->getFrameworkContext($framework, $release),
            'domain' => $this->graph->getDomainContext($framework, $release, $this->requireCode($code, 'domain')),
            'control' => $this->graph->getControlContext($framework, $release, $this->requireCode($code, 'control')),
            'requirement' => $this->graph->getRequirementContext($framework, $release, $this->requireCode($code, 'requirement')),
            default => throw new AiSkillException("Unsupported graph entity type: {$entityType}.", 'skill_invalid_parameter'),
        };

        return new AiSkillResult(
            skillKey: $this->key(),
            contextType: $payload['context_type'] ?? 'graph_context',
            payload: $payload,
            citations: $this->collectCitations($payload),
            guardrails: $this->standardGuardrails(),
        );
    }

    private function requireCode(?string $code, string $entityType): string
    {
        if ($code === null || $code === '') {
            throw new AiSkillException("A '{$entityType}' code is required for graph expansion.", 'skill_missing_parameter');
        }

        return $code;
    }

    protected function tags(): array
    {
        return ['graph', 'navigation', 'relationships'];
    }
}
