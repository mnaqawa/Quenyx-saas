<?php

namespace App\Services\Compliance\Retrieval;

use App\DataTransferObjects\Ai\AiSkillRequest;
use App\DataTransferObjects\Compliance\Retrieval\RetrievalQuery;

/**
 * Turns a {@see RetrievalQuery} + resolved scope into a deterministic list of skill requests
 * (QCIF Sprint 15). The mapping is fixed per mode; corpus retrieval uses a control profile when the
 * query contains a control code, otherwise free-text search. Code-dependent corpus/graph/mapping
 * skills are only requested when a code is present, so they degrade gracefully.
 *
 * No AI, no DB — it only builds request DTOs the Skill Router will execute.
 */
class ComplianceRetrievalPlanner
{
    /** Matches codes like "1-1-1", "2-8-4", "A.5.1", "AC-2". */
    private const CODE_PATTERN = '/\b([0-9]+(?:[-.][0-9]+)+|[A-Za-z]{1,3}[-.][0-9]+(?:[-.][0-9]+)*)\b/';

    public function extractCode(string $query): ?string
    {
        if (preg_match(self::CODE_PATTERN, $query, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /**
     * @return list<AiSkillRequest>
     */
    public function plan(RetrievalQuery $query, ?string $framework, ?string $release): array
    {
        $code = $query->code;
        $requests = [];

        foreach ($query->mode->corpusSkills() as $skill) {
            $requests[] = match ($skill) {
                'corpus_search' => $code !== null
                    ? $this->skill('corpus_search', 'control_profile', $framework, $release, ['controlCode' => $code, 'code' => $code])
                    : $this->skill('corpus_search', 'search_context', $framework, $release, ['query' => $query->query, 'limit' => $query->limit]),
                'knowledge_graph' => $code !== null
                    ? $this->skill('knowledge_graph', 'control_context', $framework, $release, ['entity_type' => 'control', 'code' => $code, 'controlCode' => $code])
                    : null,
                'framework_mapping' => $code !== null
                    ? $this->skill('framework_mapping', 'control_mapping', $framework, $release, ['controlCode' => $code])
                    : null,
                default => null,
            };
        }

        foreach ($query->mode->workspaceSkills() as $skill) {
            $params = ['project_id' => $query->projectId];
            if ($code !== null && $skill === 'evidence') {
                $params['controlCode'] = $code;
            }
            $requests[] = $this->skill($skill, null, $framework, $release, $params);
        }

        return array_values(array_filter($requests));
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function skill(string $skill, ?string $contextType, ?string $framework, ?string $release, array $params): AiSkillRequest
    {
        return new AiSkillRequest(
            skill: $skill,
            contextType: $contextType,
            frameworkKey: $framework,
            releaseCode: $release,
            parameters: $params,
        );
    }
}
