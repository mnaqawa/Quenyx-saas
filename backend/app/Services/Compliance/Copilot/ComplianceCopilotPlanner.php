<?php

namespace App\Services\Compliance\Copilot;

use App\Enums\Compliance\Copilot\ComplianceCopilotIntent;

/**
 * Deterministic intent planner for the Compliance Copilot (QCIF Sprint 14).
 *
 * Classifies a user message into exactly one supported intent using a fixed, ordered set of
 * keyword/regex rules and extracts lightweight parameters (a control/requirement code, a search
 * query). There is NO LLM, NO probability, and NO open-ended chat: the same message always
 * produces the same plan, and anything that doesn't match is `unsupported_intent`.
 *
 * Intent classification ({@see classify()}) performs NO database access. Scope attachment
 * ({@see plan()}) delegates the only DB work to {@see ComplianceCopilotScopeResolver} (the
 * sanctioned boundary) — the planner itself issues no queries.
 */
class ComplianceCopilotPlanner
{
    /** Matches codes like "1-1-1", "2-8-4", "A.5.1", "AC-2". */
    private const CODE_PATTERN = '/\b([0-9]+(?:[-.][0-9]+)+|[A-Za-z]{1,3}[-.][0-9]+(?:[-.][0-9]+)*)\b/';

    public function __construct(
        private readonly ComplianceCopilotScopeResolver $scopeResolver = new ComplianceCopilotScopeResolver(),
    ) {}

    /**
     * Classify a message AND attach the resolved framework/release/revision scope (QCIF Sprint 14.1)
     * so every selected skill receives scope automatically — even when the caller omits it.
     *
     * @return array{intent: ComplianceCopilotIntent, code: ?string, query: ?string, entity_type: string, scope: array<string, mixed>}
     */
    public function plan(string $message, ?string $framework = null, ?string $release = null): array
    {
        return array_merge(
            $this->classify($message),
            ['scope' => $this->scopeResolver->resolve($framework, $release)],
        );
    }

    /**
     * @return array{intent: ComplianceCopilotIntent, code: ?string, query: ?string, entity_type: string}
     */
    public function classify(string $message): array
    {
        $text = strtolower(trim($message));
        $code = $this->extractCode($message);

        $intent = $this->resolveIntent($text, $code);

        return [
            'intent' => $intent,
            'code' => $code,
            'query' => $intent === ComplianceCopilotIntent::SearchCorpus ? $this->extractQuery($message) : null,
            'entity_type' => 'control',
        ];
    }

    private function resolveIntent(string $text, ?string $code): ComplianceCopilotIntent
    {
        // 1. Evidence is the most specific signal.
        if ($this->hasAny($text, ['evidence', 'proof', 'artifact', 'artefact'])) {
            return ComplianceCopilotIntent::EvidenceStatus;
        }

        // 2. Explicit gap language.
        if ($this->hasAny($text, ['gap', 'gaps', 'compliance posture', 'where are we non-compliant', 'non-compliant'])) {
            return ComplianceCopilotIntent::GapSummary;
        }

        // 3. Remediation / prioritization language.
        if ($this->hasAny($text, ['recommend', 'recommendation', 'fix first', 'what should we fix', 'what to fix', 'prioriti', 'remediat', 'next step', 'action plan'])) {
            return ComplianceCopilotIntent::RecommendationSummary;
        }

        // 4. Explanation of a specific control.
        if ($code !== null && $this->hasAny($text, ['explain', 'describe', 'what is', 'what does', 'meaning', 'tell me about', 'control'])) {
            return ComplianceCopilotIntent::ControlExplanation;
        }

        // 5. Corpus search.
        if ($this->hasAny($text, ['find', 'search', 'related to', 'list controls', 'show controls', 'which controls', 'controls about', 'controls for'])) {
            return ComplianceCopilotIntent::SearchCorpus;
        }

        // 6. A bare code with no other signal → explain it.
        if ($code !== null) {
            return ComplianceCopilotIntent::ControlExplanation;
        }

        return ComplianceCopilotIntent::Unsupported;
    }

    private function extractCode(string $message): ?string
    {
        if (preg_match(self::CODE_PATTERN, $message, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function extractQuery(string $message): string
    {
        $text = trim($message);

        // Prefer the phrase after "related to" / "about" / "for".
        foreach (['related to', 'relating to', 'about', ' for '] as $marker) {
            $pos = stripos($text, $marker);
            if ($pos !== false) {
                $candidate = trim(substr($text, $pos + strlen($marker)));
                if ($candidate !== '') {
                    return $this->cleanQuery($candidate);
                }
            }
        }

        // Otherwise strip a leading search verb.
        $stripped = preg_replace('/^\s*(please\s+)?(find|search( for)?|list|show( me)?|which|show)\s+/i', '', $text);

        return $this->cleanQuery((string) ($stripped ?? $text));
    }

    private function cleanQuery(string $candidate): string
    {
        $candidate = preg_replace('/\b(controls?|requirements?|related|about)\b/i', '', $candidate) ?? $candidate;

        return trim(preg_replace('/\s+/', ' ', $candidate) ?? $candidate, " ?.!");
    }

    /**
     * @param  list<string>  $needles
     */
    private function hasAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
