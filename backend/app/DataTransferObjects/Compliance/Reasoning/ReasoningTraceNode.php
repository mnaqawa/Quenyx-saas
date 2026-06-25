<?php

namespace App\DataTransferObjects\Compliance\Reasoning;

/**
 * A single node in the deterministic reasoning graph (QCIF Sprint 16).
 *
 * This is BUSINESS reasoning, NOT a hidden chain-of-thought: each node states an explicit,
 * rule-derived `reason`, the `source` it came from (corpus/gap/evidence/recommendation/rule/...),
 * its `citations`, its `parent`, and its `children`. UUID-only. Pure data.
 */
final readonly class ReasoningTraceNode
{
    /**
     * @param  list<array<string, mixed>>  $citations
     * @param  list<ReasoningTraceNode>  $children
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $uuid,
        public string $reason,
        public string $source,
        public array $citations = [],
        public ?string $parent = null,
        public array $children = [],
        public array $metadata = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'uuid' => $this->uuid,
            'reason' => $this->reason,
            'source' => $this->source,
            'citations' => $this->citations,
            'parent' => $this->parent,
            'children' => array_map(fn (ReasoningTraceNode $c) => $c->toArray(), $this->children),
            'metadata' => $this->metadata,
        ];
    }
}
