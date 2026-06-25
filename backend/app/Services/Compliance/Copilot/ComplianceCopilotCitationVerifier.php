<?php

namespace App\Services\Compliance\Copilot;

use App\Enums\Compliance\Copilot\ComplianceCopilotIntent;

/**
 * Enforces the "no citation, no answer" rule for the Compliance Copilot (QCIF Sprint 14).
 *
 * Fails CLOSED: a non-empty answer that is not backed by at least one corpus citation (for
 * corpus-cited intents) or at least one deterministic grounding reference (for engine-grounded
 * intents) is rejected with `citation_validation_failed`. In AI mode it additionally performs a
 * best-effort coverage check: if the generated answer does not mention any cited reference, the
 * answer is flagged `needs_review`.
 *
 * This service performs NO database access.
 */
class ComplianceCopilotCitationVerifier
{
    /**
     * @param  list<array<string, mixed>>  $corpusCitations
     * @param  list<array<string, mixed>>  $groundingRefs
     * @return array{ok: bool, code: ?string, warnings: list<string>, needs_review: bool}
     */
    public function verify(
        ComplianceCopilotIntent $intent,
        array $corpusCitations,
        array $groundingRefs,
        string $answerEn,
        string $answerAr,
        string $mode,
    ): array {
        $warnings = [];
        $needsReview = false;

        $answerEmpty = trim($answerEn) === '' && trim($answerAr) === '';
        if ($answerEmpty) {
            return ['ok' => true, 'code' => null, 'warnings' => $warnings, 'needs_review' => false];
        }

        if ($intent->requiresCorpusCitations()) {
            if ($corpusCitations === []) {
                return [
                    'ok' => false,
                    'code' => 'citation_validation_failed',
                    'warnings' => ['answer_rejected_no_corpus_citations'],
                    'needs_review' => true,
                ];
            }
        } elseif ($corpusCitations === [] && $groundingRefs === []) {
            return [
                'ok' => false,
                'code' => 'citation_validation_failed',
                'warnings' => ['answer_rejected_no_grounding'],
                'needs_review' => true,
            ];
        }

        // AI-mode coverage heuristic: the model output must reference at least one provided token.
        if ($mode === 'ai' && ! $this->mentionsAnyReference($answerEn.' '.$answerAr, $corpusCitations, $groundingRefs)) {
            $warnings[] = 'answer_may_reference_uncited_facts';
            $needsReview = true;
        }

        return ['ok' => true, 'code' => null, 'warnings' => $warnings, 'needs_review' => $needsReview];
    }

    /**
     * @param  list<array<string, mixed>>  $corpusCitations
     * @param  list<array<string, mixed>>  $groundingRefs
     */
    private function mentionsAnyReference(string $answer, array $corpusCitations, array $groundingRefs): bool
    {
        $haystack = strtolower($answer);

        foreach ([...$corpusCitations, ...$groundingRefs] as $ref) {
            foreach (['source_document_key', 'official_reference', 'code', 'control_code', 'requirement_code', 'revision_uuid', 'uuid'] as $field) {
                $token = $ref[$field] ?? null;
                if (is_string($token) && $token !== '' && str_contains($haystack, strtolower($token))) {
                    return true;
                }
            }
        }

        return false;
    }
}
