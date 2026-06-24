<?php

namespace App\Services\Ai;

use App\DataTransferObjects\Ai\AiPrompt;

/**
 * Assembles a grounded prompt from an AI Context payload (produced by the Sprint 6 AI
 * Consumption Contract Layer) plus a user question.
 *
 * STRICT boundaries: this class performs NO corpus querying and NO database access. It only
 * transforms the already-assembled, already-cited context array it is handed into a system
 * prompt + user prompt, embedding the citations and guardrails so any provider honors them.
 */
class CompliancePromptOrchestrator
{
    /**
     * @param  array<string, mixed>  $aiContext  AI Contract Layer envelope (context_type, payload, citations, guardrails, ...)
     * @param  array<string, mixed>  $options
     */
    public function buildPrompt(array $aiContext, string $userPrompt, array $options = []): AiPrompt
    {
        $citations = $this->extractList($aiContext, 'citations');
        $guardrails = $this->extractGuardrails($aiContext);
        $contextPayload = $aiContext['payload'] ?? $aiContext['data'] ?? $aiContext;

        $systemPrompt = $this->buildSystemPrompt($contextPayload, $citations, $guardrails, $options);

        return new AiPrompt(
            systemPrompt: $systemPrompt,
            userPrompt: trim($userPrompt),
            citations: $citations,
            guardrails: $guardrails,
            metadata: [
                'context_type' => $aiContext['context_type'] ?? null,
                'citation_count' => count($citations),
            ],
        );
    }

    /**
     * @param  mixed  $contextPayload
     * @param  list<array<string, mixed>>  $citations
     * @param  array<string, bool>  $guardrails
     * @param  array<string, mixed>  $options
     */
    private function buildSystemPrompt(mixed $contextPayload, array $citations, array $guardrails, array $options): string
    {
        $lines = [];
        $lines[] = (string) ($options['role_preamble']
            ?? 'You are a compliance assistant for the Quenyx QynShield platform. You answer strictly from the provided official compliance context.');
        $lines[] = '';
        $lines[] = 'GUARDRAILS (must be honored):';
        foreach ($this->guardrailDirectives($guardrails) as $directive) {
            $lines[] = '- '.$directive;
        }
        $lines[] = '';
        $lines[] = 'PROVIDED CONTEXT (the only source of truth — do not use outside knowledge):';
        $lines[] = $this->encode($contextPayload);
        $lines[] = '';
        $lines[] = 'CITATIONS (cite these by source_document_key / official_reference for every claim):';
        $lines[] = $citations === [] ? '(none provided — if so, state that you cannot answer without citations)' : $this->encode($citations);

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, bool>  $guardrails
     * @return list<string>
     */
    private function guardrailDirectives(array $guardrails): array
    {
        $map = [
            'use_only_provided_context' => 'Use ONLY the provided context. Never invent controls, requirements, or facts.',
            'do_not_invent_controls' => 'Do not fabricate controls, codes, or references.',
            'cite_every_claim' => 'Attach a citation to every factual claim.',
            'preserve_official_wording' => 'Preserve the official wording of controls and requirements.',
            'bilingual_required' => 'Provide both English and Arabic where the context contains both.',
            'no_legal_advice_disclaimer_required' => 'Include a disclaimer that this is not legal advice.',
            'tenant_data_not_included' => 'No tenant data is included; do not assume tenant-specific facts.',
            'evidence_not_included' => 'No evidence is included; do not assert evidence exists.',
        ];

        $directives = [];
        foreach ($guardrails as $key => $enabled) {
            if ($enabled && isset($map[$key])) {
                $directives[] = $map[$key];
            }
        }

        if ($directives === []) {
            $directives[] = 'Use ONLY the provided context and cite every claim.';
        }

        return $directives;
    }

    /**
     * @param  array<string, mixed>  $aiContext
     * @return array<string, bool>
     */
    private function extractGuardrails(array $aiContext): array
    {
        $guardrails = $aiContext['guardrails'] ?? [];

        return is_array($guardrails) ? array_map(static fn ($v) => (bool) $v, $guardrails) : [];
    }

    /**
     * @param  array<string, mixed>  $aiContext
     * @return list<array<string, mixed>>
     */
    private function extractList(array $aiContext, string $key): array
    {
        $value = $aiContext[$key] ?? [];

        return is_array($value) ? array_values($value) : [];
    }

    private function encode(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }
}
