<?php

namespace App\DataTransferObjects\Compliance\Reasoning;

use App\Enums\Compliance\Copilot\ComplianceCopilotIntent;

/**
 * The immutable input to the Compliance Reasoning Engine (QCIF Sprint 16).
 *
 * It bundles everything the engine needs to make a deterministic decision: the classified intent,
 * the resolved scope, the user query/code, the deterministic skill payloads, the retrieval chunks,
 * and the corpus citations + grounding references. It is PURE DATA — no DB, no AI, no I/O. The
 * engine never reaches outside this object.
 */
final readonly class ComplianceReasoningContext
{
    /**
     * @param  array<string, array<string, mixed>>  $skillPayloads  keyed by skill key (successful skills only)
     * @param  array<string, mixed>  $scope
     * @param  list<array<string, mixed>>  $corpusCitations
     * @param  list<array<string, mixed>>  $groundingRefs
     * @param  list<array<string, mixed>>  $retrievalChunks
     * @param  array<string, bool>  $guardrails
     */
    public function __construct(
        public ComplianceCopilotIntent $intent,
        public string $query,
        public ?string $code,
        public array $scope,
        public array $skillPayloads,
        public array $corpusCitations = [],
        public array $groundingRefs = [],
        public array $retrievalChunks = [],
        public array $guardrails = [],
    ) {}

    public function has(string $skillKey): bool
    {
        return isset($this->skillPayloads[$skillKey]) && $this->skillPayloads[$skillKey] !== [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function payload(string $skillKey): ?array
    {
        $payload = $this->skillPayloads[$skillKey] ?? null;

        return is_array($payload) ? $payload : null;
    }

    public function revisionUuid(): ?string
    {
        $value = $this->scope['revision_uuid'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Stable signature used to seed deterministic UUIDs (revision + framework + code).
     */
    public function signature(): string
    {
        return implode('|', [
            (string) ($this->scope['framework_key'] ?? ''),
            (string) ($this->scope['release_code'] ?? ''),
            (string) $this->revisionUuid(),
            (string) $this->code,
        ]);
    }
}
