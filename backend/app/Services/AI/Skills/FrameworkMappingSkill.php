<?php

namespace App\Services\Ai\Skills;

use App\DataTransferObjects\Ai\AiSkillRequest;
use App\DataTransferObjects\Ai\AiSkillResult;
use App\Exceptions\Ai\AiSkillException;
use App\Services\Compliance\Mapping\ComplianceFrameworkComparisonService;
use App\Services\Compliance\Mapping\ComplianceMappingService;

/**
 * FrameworkMappingSkill — returns control-objective mappings, related controls, framework
 * coverage, and framework comparison. Reuses ComplianceMappingService and
 * ComplianceFrameworkComparisonService, both of which return EMPTY where data does not exist
 * (no fabricated mappings). No prompt, no AI, no own DB queries.
 */
class FrameworkMappingSkill extends AbstractAiSkill
{
    public function __construct(
        private readonly ComplianceMappingService $mapping = new ComplianceMappingService(),
        private readonly ComplianceFrameworkComparisonService $comparison = new ComplianceFrameworkComparisonService(),
    ) {}

    public function key(): string
    {
        return 'framework_mapping';
    }

    public function displayName(): string
    {
        return 'Framework Mapping';
    }

    public function description(): string
    {
        return 'Returns control-objective mappings, related controls, framework coverage, and cross-framework comparison.';
    }

    public function supportedContextTypes(): array
    {
        return [
            'control_objectives',
            'objective_mapping',
            'control_mapping',
            'framework_coverage',
            'framework_comparison',
        ];
    }

    public function execute(AiSkillRequest $request): AiSkillResult
    {
        $operation = $request->contextType ?? $request->stringParam('operation', 'control_objectives');
        $framework = $request->frameworkKey;
        $release = $request->releaseCode;

        $payload = match ($operation) {
            'control_objectives' => $this->mapping->getControlObjectives($framework, $release),
            'objective_mapping' => $this->mapping->getObjectiveMapping(
                $this->requireParam($request, 'objectiveCode'),
                $framework,
                $release,
            ),
            'control_mapping' => $this->mapping->getControlMapping(
                $this->requireParam($request, 'controlCode'),
                $framework,
                $release,
            ),
            'framework_coverage' => $this->comparison->getFrameworkCoverage(
                $this->requireScope($framework),
                $release,
            ),
            'framework_comparison' => $this->comparison->getFrameworkComparison(
                $this->requireScope($framework),
                $this->requireParam($request, 'targetFramework'),
                $release,
                $request->stringParam('targetRelease'),
            ),
            default => throw new AiSkillException("Unsupported mapping operation: {$operation}.", 'skill_invalid_parameter'),
        };

        return new AiSkillResult(
            skillKey: $this->key(),
            contextType: $payload['context_type'] ?? $operation,
            payload: $payload,
            citations: $this->collectCitations($payload),
            guardrails: $this->standardGuardrails(),
        );
    }

    private function requireParam(AiSkillRequest $request, string $key): string
    {
        $value = $request->stringParam($key);
        if ($value === null) {
            throw new AiSkillException("Mapping operation requires the '{$key}' parameter.", 'skill_missing_parameter');
        }

        return $value;
    }

    private function requireScope(?string $framework): string
    {
        if ($framework === null || $framework === '') {
            throw new AiSkillException('This mapping operation requires a framework.', 'skill_missing_scope');
        }

        return $framework;
    }

    protected function tags(): array
    {
        return ['mapping', 'coverage', 'comparison'];
    }
}
