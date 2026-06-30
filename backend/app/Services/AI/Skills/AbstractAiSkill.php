<?php

namespace App\Services\AI\Skills;

use App\Contracts\Ai\AiSkillInterface;
use App\DataTransferObjects\Ai\AiSkillMetadata;
use App\DataTransferObjects\Ai\AiSkillRequest;

/**
 * Shared behavior for skills: default selection logic, metadata/health, the standard guardrail
 * set, and citation extraction from corpus-derived node payloads. Concrete skills implement
 * only key/displayName/description/supportedContextTypes/execute.
 */
abstract class AbstractAiSkill implements AiSkillInterface
{
    public function supports(AiSkillRequest $request): bool
    {
        if ($request->skill !== null && $request->skill !== '') {
            return $request->skill === $this->key();
        }

        if ($request->contextType !== null && $request->contextType !== '') {
            return in_array($request->contextType, $this->supportedContextTypes(), true);
        }

        return false;
    }

    public function metadata(): AiSkillMetadata
    {
        return new AiSkillMetadata(
            key: $this->key(),
            displayName: $this->displayName(),
            description: $this->description(),
            supportedContextTypes: $this->supportedContextTypes(),
            tags: $this->tags(),
        );
    }

    public function health(): bool
    {
        return true;
    }

    /**
     * @return list<string>
     */
    protected function tags(): array
    {
        return [];
    }

    /**
     * The canonical guardrail set every AI-ready payload carries. Mirrors the corpus AI
     * contract guardrails so downstream prompts always honor "use only provided context",
     * "cite every claim", etc. Kept inline so skills stay independent of corpus internals.
     *
     * @return array<string, bool>
     */
    protected function standardGuardrails(): array
    {
        return [
            'use_only_provided_context' => true,
            'do_not_invent_controls' => true,
            'cite_every_claim' => true,
            'preserve_official_wording' => true,
            'bilingual_required' => true,
            'no_legal_advice_disclaimer_required' => true,
            'tenant_data_not_included' => true,
            'evidence_not_included' => true,
        ];
    }

    /**
     * Derive citations from a corpus-derived payload by walking it for `provenance` blocks that
     * resolve to a known source document. This NEVER fabricates citations — it only surfaces
     * provenance already present in the data returned by the reused services.
     *
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    protected function collectCitations(array $payload): array
    {
        $citations = [];
        $seen = [];
        $this->walkForProvenance($payload, $citations, $seen);

        return array_values($citations);
    }

    /**
     * @param  mixed  $node
     * @param  list<array<string, mixed>>  $citations
     * @param  array<string, true>  $seen
     */
    private function walkForProvenance(mixed $node, array &$citations, array &$seen): void
    {
        if (! is_array($node)) {
            return;
        }

        $provenance = $node['provenance'] ?? null;
        if (is_array($provenance) && ! empty($provenance['source_document_key'])) {
            $key = (string) $provenance['source_document_key'].'|'.(string) ($node['uuid'] ?? '');
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $citations[] = [
                    'source_document_key' => $provenance['source_document_key'],
                    'official_reference' => $provenance['official_reference'] ?? null,
                    'source_reference' => $provenance['source_reference'] ?? null,
                    'source_page' => $provenance['source_page'] ?? null,
                    'entity_type' => $node['entity_type'] ?? null,
                    'entity_uuid' => $node['uuid'] ?? null,
                ];
            }
        }

        foreach ($node as $value) {
            if (is_array($value)) {
                $this->walkForProvenance($value, $citations, $seen);
            }
        }
    }
}
