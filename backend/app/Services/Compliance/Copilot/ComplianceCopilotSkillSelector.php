<?php

namespace App\Services\Compliance\Copilot;

use App\DataTransferObjects\Ai\AiSkillRequest;
use App\Enums\Compliance\Copilot\ComplianceCopilotIntent;

/**
 * Maps a classified intent to the exact set of AI Skill requests the Copilot must execute
 * (QCIF Sprint 14). The mapping is fixed and deterministic — one intent always selects the same
 * canonical skills. Code-dependent skills (knowledge graph, framework mapping) are only requested
 * when a control code was extracted, so they degrade gracefully instead of failing.
 *
 * This service performs NO database access — it only builds request DTOs that the Skill Router
 * (and the skills themselves) will resolve.
 */
class ComplianceCopilotSkillSelector
{
    public const SKILL_CORPUS = 'corpus_search';

    public const SKILL_GRAPH = 'knowledge_graph';

    public const SKILL_MAPPING = 'framework_mapping';

    public const SKILL_EVIDENCE = 'evidence';

    public const SKILL_GAP = 'gap_assessment';

    public const SKILL_RECOMMENDATION = 'recommendation';

    /**
     * The canonical skill set for an intent, independent of available parameters. Used for QA and
     * documentation; the actual executed set may omit code-dependent skills (see {@see select()}).
     *
     * @return list<string>
     */
    public function canonicalSkills(ComplianceCopilotIntent $intent): array
    {
        return match ($intent) {
            ComplianceCopilotIntent::ControlExplanation => [self::SKILL_CORPUS, self::SKILL_GRAPH],
            ComplianceCopilotIntent::GapSummary => [self::SKILL_GAP, self::SKILL_RECOMMENDATION],
            ComplianceCopilotIntent::EvidenceStatus => [self::SKILL_EVIDENCE, self::SKILL_GAP],
            ComplianceCopilotIntent::RecommendationSummary => [self::SKILL_GAP, self::SKILL_RECOMMENDATION],
            ComplianceCopilotIntent::SearchCorpus => [self::SKILL_CORPUS, self::SKILL_GRAPH, self::SKILL_MAPPING],
            ComplianceCopilotIntent::Unsupported => [],
        };
    }

    /**
     * Build the concrete skill requests to execute for a planned message.
     *
     * @param  array{intent: ComplianceCopilotIntent, code: ?string, query: ?string, entity_type: string}  $plan
     * @return list<AiSkillRequest>
     */
    public function select(array $plan, int $projectId, ?string $framework, ?string $release): array
    {
        $intent = $plan['intent'];
        $code = $plan['code'];
        $query = $plan['query'];

        return match ($intent) {
            ComplianceCopilotIntent::ControlExplanation => array_values(array_filter([
                $this->corpus('control_profile', $framework, $release, ['controlCode' => $code, 'code' => $code]),
                $code !== null ? $this->graph('control_context', $framework, $release, $code) : null,
            ])),
            ComplianceCopilotIntent::GapSummary,
            ComplianceCopilotIntent::RecommendationSummary => [
                $this->workspaceSkill(self::SKILL_GAP, $projectId, $framework, $release),
                $this->workspaceSkill(self::SKILL_RECOMMENDATION, $projectId, $framework, $release),
            ],
            ComplianceCopilotIntent::EvidenceStatus => [
                $this->workspaceSkill(self::SKILL_EVIDENCE, $projectId, $framework, $release, $code !== null ? ['controlCode' => $code] : []),
                $this->workspaceSkill(self::SKILL_GAP, $projectId, $framework, $release),
            ],
            ComplianceCopilotIntent::SearchCorpus => array_values(array_filter([
                $this->corpus('search_context', $framework, $release, ['query' => $query ?? '']),
                $code !== null ? $this->graph('control_context', $framework, $release, $code) : null,
                $code !== null ? $this->mapping('control_mapping', $framework, $release, $code) : null,
            ])),
            ComplianceCopilotIntent::Unsupported => [],
        };
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function corpus(string $contextType, ?string $framework, ?string $release, array $params): AiSkillRequest
    {
        return new AiSkillRequest(
            skill: self::SKILL_CORPUS,
            contextType: $contextType,
            frameworkKey: $framework,
            releaseCode: $release,
            parameters: $params,
        );
    }

    private function graph(string $contextType, ?string $framework, ?string $release, string $code): AiSkillRequest
    {
        return new AiSkillRequest(
            skill: self::SKILL_GRAPH,
            contextType: $contextType,
            frameworkKey: $framework,
            releaseCode: $release,
            parameters: ['entity_type' => 'control', 'code' => $code, 'controlCode' => $code],
        );
    }

    private function mapping(string $contextType, ?string $framework, ?string $release, string $code): AiSkillRequest
    {
        return new AiSkillRequest(
            skill: self::SKILL_MAPPING,
            contextType: $contextType,
            frameworkKey: $framework,
            releaseCode: $release,
            parameters: ['controlCode' => $code],
        );
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function workspaceSkill(string $skill, int $projectId, ?string $framework, ?string $release, array $extra = []): AiSkillRequest
    {
        return new AiSkillRequest(
            skill: $skill,
            contextType: null,
            frameworkKey: $framework,
            releaseCode: $release,
            parameters: array_merge(['project_id' => $projectId], $extra),
        );
    }
}
