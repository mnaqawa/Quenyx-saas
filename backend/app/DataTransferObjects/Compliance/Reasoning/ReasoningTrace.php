<?php

namespace App\DataTransferObjects\Compliance\Reasoning;

/**
 * The deterministic reasoning graph (QCIF Sprint 16): a single root decision node with child nodes
 * for facts, findings, recommendations, and missing information. Fully explainable, reproducible,
 * and free of any hidden chain-of-thought. Pure data.
 */
final readonly class ReasoningTrace
{
    public function __construct(
        public ReasoningTraceNode $root,
    ) {}

    /**
     * Flatten the tree into a deterministic, depth-first list of node summaries.
     *
     * @return list<array<string, mixed>>
     */
    public function flatten(): array
    {
        return $this->walk($this->root);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function walk(ReasoningTraceNode $node): array
    {
        $flat = [[
            'uuid' => $node->uuid,
            'reason' => $node->reason,
            'source' => $node->source,
            'parent' => $node->parent,
            'citation_count' => count($node->citations),
        ]];

        foreach ($node->children as $child) {
            $flat = [...$flat, ...$this->walk($child)];
        }

        return $flat;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->root->toArray();
    }
}
