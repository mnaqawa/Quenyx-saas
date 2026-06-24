<?php

namespace App\Services\Compliance\Ai;

use App\Exceptions\ComplianceAiContextException;

/**
 * Owns the deterministic guardrails block embedded into every AI-ready payload and the
 * validation invariants that must hold before a payload is considered consumable.
 *
 * IMPORTANT: This service performs NO AI execution. It only declares constraints that a
 * future AI/RAG consumer MUST honor, and rejects payloads that would be unsafe to send.
 */
class ComplianceAiGuardrailService
{
    public const CONTEXT_CONTROL_PROFILE = 'control_profile';

    public const CONTEXT_DOMAIN_PROFILE = 'domain_profile';

    public const CONTEXT_REQUIREMENT_PROFILE = 'requirement_profile';

    public const CONTEXT_CORPUS_SUMMARY = 'corpus_summary';

    public const CONTEXT_SEARCH_CONTEXT = 'search_context';

    /**
     * @var list<string>
     */
    public const SUPPORTED_CONTEXT_TYPES = [
        self::CONTEXT_CONTROL_PROFILE,
        self::CONTEXT_DOMAIN_PROFILE,
        self::CONTEXT_REQUIREMENT_PROFILE,
        self::CONTEXT_CORPUS_SUMMARY,
        self::CONTEXT_SEARCH_CONTEXT,
    ];

    /**
     * The standard, immutable guardrails block. Booleans are intentionally hard-coded:
     * the contract layer does not allow callers to weaken these constraints.
     *
     * @return array<string, bool>
     */
    public function standardGuardrails(): array
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

    public function isSupportedContextType(string $contextType): bool
    {
        return in_array($contextType, self::SUPPORTED_CONTEXT_TYPES, true);
    }

    /**
     * @throws ComplianceAiContextException
     */
    public function assertSupportedContextType(string $contextType): void
    {
        if (! $this->isSupportedContextType($contextType)) {
            throw new ComplianceAiContextException(
                "Unsupported AI context type: {$contextType}.",
                'unsupported_context_type',
            );
        }
    }

    /**
     * Every AI-ready payload must carry at least one citation, and every citation must
     * resolve to a known source document (no source document => unverifiable claim).
     *
     * @param  list<array<string, mixed>>  $citations
     *
     * @throws ComplianceAiContextException
     */
    public function assertCitationsValid(array $citations): void
    {
        if ($citations === []) {
            throw new ComplianceAiContextException(
                'AI context payload has no citations; payload is invalid.',
                'missing_citations',
            );
        }

        foreach ($citations as $citation) {
            $key = $citation['source_document_key'] ?? null;
            if ($key === null || $key === '') {
                $entity = $citation['entity_code'] ?? $citation['entity_uuid'] ?? 'unknown';
                throw new ComplianceAiContextException(
                    "Citation for entity {$entity} is missing its source document.",
                    'missing_source_document',
                );
            }
        }
    }

    /**
     * Enforce that the primary entity carries both EN and AR text. The contract requires
     * bilingual content; a payload missing either language is rejected.
     *
     * @param  array<string, array{0: ?string, 1: ?string}>  $pairs  label => [en, ar]
     *
     * @throws ComplianceAiContextException
     */
    public function assertBilingualText(array $pairs): void
    {
        foreach ($pairs as $label => [$en, $ar]) {
            if ($en === null || trim((string) $en) === '') {
                throw new ComplianceAiContextException(
                    "Missing English text for {$label}; bilingual content is required.",
                    'missing_bilingual_text',
                );
            }
            if ($ar === null || trim((string) $ar) === '') {
                throw new ComplianceAiContextException(
                    "Missing Arabic text for {$label}; bilingual content is required.",
                    'missing_bilingual_text',
                );
            }
        }
    }
}
