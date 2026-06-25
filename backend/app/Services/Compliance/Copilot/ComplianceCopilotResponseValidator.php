<?php

namespace App\Services\Compliance\Copilot;

/**
 * Validates a composed Copilot answer against the unioned skill guardrails (QCIF Sprint 14).
 *
 * It does NOT rewrite the answer — it only emits warnings (bilingual coverage, legal-advice
 * disclaimer, empty answer) that are surfaced in the response contract so callers and the UI can
 * react. Hard citation enforcement lives in {@see ComplianceCopilotCitationVerifier}.
 *
 * This service performs NO database access.
 */
class ComplianceCopilotResponseValidator
{
    /**
     * @param  array<string, bool>  $guardrails
     * @return list<string>
     */
    public function validate(array $guardrails, string $answerEn, string $answerAr, string $mode): array
    {
        $warnings = [];

        if (trim($answerEn) === '' && trim($answerAr) === '') {
            $warnings[] = 'empty_answer';

            return $warnings;
        }

        if (($guardrails['bilingual_required'] ?? false) && trim($answerAr) === '') {
            $warnings[] = 'missing_arabic_answer';
        }

        if (($guardrails['no_legal_advice_disclaimer_required'] ?? false) && ! $this->hasDisclaimer($answerEn.' '.$answerAr)) {
            $warnings[] = 'missing_legal_disclaimer';
        }

        return $warnings;
    }

    private function hasDisclaimer(string $answer): bool
    {
        $haystack = strtolower($answer);

        return str_contains($haystack, 'not legal advice') || str_contains($haystack, 'ليست استشارة قانونية');
    }
}
