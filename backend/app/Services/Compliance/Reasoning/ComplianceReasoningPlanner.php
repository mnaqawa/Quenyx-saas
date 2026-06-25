<?php

namespace App\Services\Compliance\Reasoning;

use App\DataTransferObjects\Compliance\Reasoning\ComplianceReasoningContext;
use App\DataTransferObjects\Compliance\Reasoning\ComplianceReasoningDecision;
use App\Enums\Compliance\Reasoning\ComplianceReasoningDecisionType;

/**
 * Resolves the deterministic {@see ComplianceReasoningDecision} from the context (QCIF Sprint 16).
 *
 * The base decision is a fixed map from the intent; it is then refined to `framework_mapping` or
 * `knowledge_navigation` ONLY by explicit, deterministic context signals (mapping/graph data present
 * + matching query keywords). No LLM, no DB, no probabilistic choice.
 */
class ComplianceReasoningPlanner
{
    private const MAPPING_KEYWORDS = ['map', 'mapping', 'mapped', 'equivalent', 'cross-framework', 'crossframework', 'cross framework', 'iso', 'nist', 'pci', 'soc'];

    private const NAVIGATION_KEYWORDS = ['related', 'relationship', 'relationships', 'connected', 'parent', 'child', 'children', 'navigate', 'neighbor', 'neighbour', 'linked', 'depends'];

    public function decide(ComplianceReasoningContext $context): ComplianceReasoningDecision
    {
        $base = ComplianceReasoningDecisionType::fromIntent($context->intent);

        if (! $base->isSupported()) {
            return ComplianceReasoningDecision::for($base, ['intent_unsupported']);
        }

        $notes = ['base_from_intent:'.$context->intent->value];
        $query = mb_strtolower($context->query);

        // Refinement only applies to control-explanation style questions.
        if ($base === ComplianceReasoningDecisionType::ControlExplanation) {
            if ($context->has('framework_mapping') && $this->matchesAny($query, self::MAPPING_KEYWORDS)) {
                return ComplianceReasoningDecision::for(
                    ComplianceReasoningDecisionType::FrameworkMapping,
                    [...$notes, 'refined:mapping_signal'],
                );
            }

            if ($context->has('knowledge_graph') && $this->matchesAny($query, self::NAVIGATION_KEYWORDS)) {
                return ComplianceReasoningDecision::for(
                    ComplianceReasoningDecisionType::KnowledgeNavigation,
                    [...$notes, 'refined:navigation_signal'],
                );
            }
        }

        return ComplianceReasoningDecision::for($base, $notes);
    }

    /**
     * @param  list<string>  $keywords
     */
    private function matchesAny(string $haystack, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (str_contains($haystack, $keyword)) {
                return true;
            }
        }

        return false;
    }
}
