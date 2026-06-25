<?php

namespace App\Services\Compliance\Copilot;

use App\DataTransferObjects\Ai\AiCompletionRequest;
use App\DataTransferObjects\Ai\AiSkillResponse;
use App\DataTransferObjects\Ai\AiUsage;
use App\Enums\Compliance\Copilot\ComplianceCopilotIntent;
use App\Exceptions\Ai\AiProviderException;
use App\Services\Ai\AiProviderRegistry;
use App\Services\Ai\CompliancePromptOrchestrator;
use App\Services\Ai\Skills\AiSkillRouter;

/**
 * Compliance Copilot v0 orchestrator (QCIF Sprint 14).
 *
 * Turns a user question into a grounded, citation-enforced answer WITHOUT ever touching the
 * database or calling a model directly. The pipeline is:
 *   1. deterministic intent classification (Planner)
 *   2. deterministic skill selection (SkillSelector)
 *   3. skill execution (Skill Router → existing AI Skills)
 *   4. prompt composition from skill results (Prompt Orchestrator)
 *   5. answer production — deterministic mock preview when AI is disabled, otherwise a provider
 *      call routed exclusively through the AI Provider Registry
 *   6. citation enforcement + guardrail validation (fail closed: no citation → no answer)
 *
 * Session persistence and audit logging are handled by the caller (controller + SessionService);
 * this class returns a pure result array and is itself DB-free.
 */
class ComplianceCopilotService
{
    /** @var list<string> Skills whose results provide deterministic (non-corpus) grounding. */
    private const ENGINE_SKILLS = ['gap_assessment', 'evidence', 'recommendation'];

    public function __construct(
        private readonly ComplianceCopilotPlanner $planner,
        private readonly ComplianceCopilotSkillSelector $selector,
        private readonly AiSkillRouter $router,
        private readonly CompliancePromptOrchestrator $orchestrator,
        private readonly AiProviderRegistry $registry,
        private readonly ComplianceCopilotResponseValidator $validator,
        private readonly ComplianceCopilotCitationVerifier $citationVerifier,
    ) {}

    /**
     * @param  array<string, mixed>  $options  framework, release, provider
     * @return array<string, mixed>
     */
    public function handle(int $projectId, string $userMessage, array $options = []): array
    {
        $framework = $this->stringOrNull($options['framework'] ?? null);
        $release = $this->stringOrNull($options['release'] ?? null);

        $plan = $this->planner->classify($userMessage);
        $intent = $plan['intent'];

        if (! $intent->isSupported()) {
            return $this->unsupportedResult();
        }

        $requests = $this->selector->select($plan, $projectId, $framework, $release);
        $responses = $this->router->executeMany($requests);

        $prompt = $this->orchestrator->composeFromSkills($responses, $userMessage);
        $corpusCitations = $prompt->citations;
        $groundingRefs = $this->buildGroundingRefs($responses);
        $guardrails = $prompt->guardrails;
        $skillWarnings = $this->collectSkillWarnings($responses);

        $mode = $this->aiEnabled() ? 'ai' : 'mock';

        if ($mode === 'ai') {
            $answer = $this->generateAiAnswer($prompt->toMessages(), $options, $corpusCitations);
        } else {
            $answer = $this->generateMockAnswer($intent, $plan, $responses, $corpusCitations);
        }

        $citationCheck = $this->citationVerifier->verify(
            $intent,
            $answer['citations'],
            $groundingRefs,
            $answer['answer_en'],
            $answer['answer_ar'],
            $mode,
        );

        if (! $citationCheck['ok']) {
            return $this->citationFailedResult($intent, $mode, $answer['provider'], $responses, $guardrails, $citationCheck['warnings']);
        }

        $validatorWarnings = $this->validator->validate($guardrails, $answer['answer_en'], $answer['answer_ar'], $mode);
        $warnings = array_values(array_unique([...$skillWarnings, ...$citationCheck['warnings'], ...$validatorWarnings]));

        if ($citationCheck['needs_review']) {
            $warnings[] = 'needs_review';
            $warnings = array_values(array_unique($warnings));
        }

        return [
            'intent' => $intent->value,
            'mode' => $mode,
            'provider' => $answer['provider'],
            'answer_en' => $answer['answer_en'],
            'answer_ar' => $answer['answer_ar'],
            'citations' => $this->mergeCitations($answer['citations'], $groundingRefs),
            'skill_results' => $this->summarizeSkills($responses),
            'guardrails' => $guardrails,
            'warnings' => $warnings,
            'usage' => $answer['usage']->toArray(),
            'mocked' => $answer['mocked'],
            'plain_answer' => trim($answer['answer_en']."\n".$answer['answer_ar']),
        ];
    }

    // -------------------------------------------------------------------------
    // Answer generation
    // -------------------------------------------------------------------------

    /**
     * @param  list<\App\DataTransferObjects\Ai\AiMessage>  $messages
     * @param  array<string, mixed>  $options
     * @param  list<array<string, mixed>>  $corpusCitations
     * @return array{answer_en: string, answer_ar: string, citations: list<array<string, mixed>>, provider: string, usage: AiUsage, mocked: bool}
     */
    private function generateAiAnswer(array $messages, array $options, array $corpusCitations): array
    {
        $requested = $this->stringOrNull($options['provider'] ?? null);

        try {
            $provider = $this->registry->get($requested);
            $completion = $provider->responses(new AiCompletionRequest(
                messages: $messages,
                model: null,
                temperature: (float) config('ai.defaults.temperature', 0.0),
                maxTokens: (int) config('ai.defaults.max_tokens', 1024),
                responseFormat: 'text',
                stream: false,
            ));
        } catch (AiProviderException $e) {
            // Fall back to a safe empty answer; the citation verifier will not block an empty answer,
            // and the warning surfaces the provider failure.
            return [
                'answer_en' => '',
                'answer_ar' => '',
                'citations' => $corpusCitations,
                'provider' => $requested ?? $this->registry->defaultKey(),
                'usage' => new AiUsage(),
                'mocked' => false,
            ];
        }

        return [
            'answer_en' => $completion->content,
            'answer_ar' => '',
            'citations' => $this->mergeCitations($corpusCitations, $completion->citations),
            'provider' => $completion->provider,
            'usage' => $completion->usage,
            'mocked' => $completion->mocked,
        ];
    }

    /**
     * @param  array{intent: ComplianceCopilotIntent, code: ?string, query: ?string, entity_type: string}  $plan
     * @param  list<AiSkillResponse>  $responses
     * @param  list<array<string, mixed>>  $corpusCitations
     * @return array{answer_en: string, answer_ar: string, citations: list<array<string, mixed>>, provider: string, usage: AiUsage, mocked: bool}
     */
    private function generateMockAnswer(ComplianceCopilotIntent $intent, array $plan, array $responses, array $corpusCitations): array
    {
        $code = $plan['code'];
        $query = $plan['query'] ?? '';
        $gap = $this->payloadFor($responses, 'gap_assessment');
        $reco = $this->payloadFor($responses, 'recommendation');
        $evidence = $this->payloadFor($responses, 'evidence');

        $preview = '(Preview mode — AI generation disabled.)';
        $disclaimerEn = 'This is not legal advice.';
        $disclaimerAr = 'هذه ليست استشارة قانونية.';

        [$en, $ar] = match ($intent) {
            ComplianceCopilotIntent::ControlExplanation => [
                sprintf('Control %s: refer to the cited official source(s) for the authoritative text and requirements.', $code ?? ''),
                sprintf('الضابط %s: راجع المصادر الرسمية المُستشهد بها للنص والمتطلبات الرسمية.', $code ?? ''),
            ],
            ComplianceCopilotIntent::GapSummary => [
                sprintf(
                    'Compliance gap summary: %d requirement(s) assessed — %d satisfied, %d with gaps. %d remediation recommendation(s) available.',
                    $this->dig($gap, ['summary', 'totals', 'requirements']),
                    $this->dig($gap, ['summary', 'totals', 'satisfied']),
                    $this->dig($gap, ['summary', 'totals', 'gaps']),
                    $this->dig($reco, ['summary', 'totals', 'recommendations']),
                ),
                'ملخص فجوات الامتثال: راجع الأرقام التفصيلية والمراجع المرفقة.',
            ],
            ComplianceCopilotIntent::EvidenceStatus => [
                sprintf(
                    'Evidence status%s: %d evidence item(s) recorded for this workspace; %d related requirement gap(s) outstanding.',
                    $code !== null ? ' for control '.$code : '',
                    $this->dig($evidence, ['counts', 'evidence']),
                    $this->dig($gap, ['summary', 'totals', 'gaps']),
                ),
                'حالة الأدلة: راجع عدد عناصر الأدلة والفجوات ذات الصلة في المرفقات.',
            ],
            ComplianceCopilotIntent::RecommendationSummary => [
                sprintf(
                    'Remediation priorities across %d recommendation(s): %d critical, %d high, %d medium. Address critical and high items first.',
                    $this->dig($reco, ['summary', 'totals', 'recommendations']),
                    $this->dig($reco, ['summary', 'totals', 'by_priority', 'critical']),
                    $this->dig($reco, ['summary', 'totals', 'by_priority', 'high']),
                    $this->dig($reco, ['summary', 'totals', 'by_priority', 'medium']),
                ),
                'أولويات المعالجة: عالجوا العناصر الحرجة وعالية الأولوية أولاً وفق المرفقات.',
            ],
            ComplianceCopilotIntent::SearchCorpus => [
                sprintf("Search results for '%s': %d cited corpus reference(s) match. See citations.", $query, count($corpusCitations)),
                sprintf("نتائج البحث عن '%s': راجع المراجع المُستشهد بها المرفقة.", $query),
            ],
            default => ['', ''],
        };

        return [
            'answer_en' => trim($en.' '.$preview.' '.$disclaimerEn),
            'answer_ar' => trim($ar.' '.$disclaimerAr),
            'citations' => $corpusCitations,
            'provider' => 'mock',
            'usage' => new AiUsage(),
            'mocked' => true,
        ];
    }

    // -------------------------------------------------------------------------
    // Grounding + citation helpers
    // -------------------------------------------------------------------------

    /**
     * @param  list<AiSkillResponse>  $responses
     * @return list<array<string, mixed>>
     */
    private function buildGroundingRefs(array $responses): array
    {
        $refs = [];
        foreach ($responses as $response) {
            if (! $response->success || $response->result === null) {
                continue;
            }
            if (! in_array($response->skillKey, self::ENGINE_SKILLS, true)) {
                continue;
            }

            $payload = $response->result->payload;
            $refs[] = array_filter([
                'type' => $response->result->contextType ?? $response->skillKey,
                'skill' => $response->skillKey,
                'revision_uuid' => $this->digString($payload, ['revision', 'uuid'])
                    ?? $this->digString($payload, ['corpus_revision', 'uuid'])
                    ?? $this->digString($payload, ['revision_uuid']),
                'framework' => $this->digString($payload, ['framework', 'key'])
                    ?? $this->digString($payload, ['framework', 'title_en']),
                'workspace_scoped' => true,
            ], static fn ($v) => $v !== null && $v !== '');
        }

        return $refs;
    }

    /**
     * @param  list<array<string, mixed>>  $a
     * @param  list<array<string, mixed>>  $b
     * @return list<array<string, mixed>>
     */
    private function mergeCitations(array $a, array $b): array
    {
        $merged = [];
        $seen = [];
        foreach ([...$a, ...$b] as $citation) {
            $key = json_encode($citation);
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $merged[] = $citation;
            }
        }

        return $merged;
    }

    // -------------------------------------------------------------------------
    // Result envelopes
    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function unsupportedResult(): array
    {
        return [
            'intent' => ComplianceCopilotIntent::Unsupported->value,
            'mode' => $this->aiEnabled() ? 'ai' : 'mock',
            'provider' => null,
            'answer_en' => 'This question is outside the Compliance Copilot v0 scope. Supported topics: control explanation, gap summary, evidence status, recommendations, and corpus search.',
            'answer_ar' => 'هذا السؤال خارج نطاق مساعد الامتثال الإصدار 0. المواضيع المدعومة: شرح الضوابط، ملخص الفجوات، حالة الأدلة، التوصيات، والبحث في المدونة.',
            'citations' => [],
            'skill_results' => [],
            'guardrails' => [],
            'warnings' => ['unsupported_intent'],
            'usage' => (new AiUsage())->toArray(),
            'mocked' => true,
            'plain_answer' => 'unsupported_intent',
        ];
    }

    /**
     * @param  list<AiSkillResponse>  $responses
     * @param  array<string, bool>  $guardrails
     * @param  list<string>  $warnings
     * @return array<string, mixed>
     */
    private function citationFailedResult(ComplianceCopilotIntent $intent, string $mode, ?string $provider, array $responses, array $guardrails, array $warnings): array
    {
        return [
            'intent' => $intent->value,
            'mode' => $mode,
            'provider' => $provider,
            'answer_en' => '',
            'answer_ar' => '',
            'citations' => [],
            'skill_results' => $this->summarizeSkills($responses),
            'guardrails' => $guardrails,
            'warnings' => array_values(array_unique([...$warnings, 'citation_validation_failed', 'needs_review'])),
            'usage' => (new AiUsage())->toArray(),
            'mocked' => $mode === 'mock',
            'plain_answer' => '',
            'error_code' => 'citation_validation_failed',
        ];
    }

    // -------------------------------------------------------------------------
    // Skill response inspection
    // -------------------------------------------------------------------------

    /**
     * @param  list<AiSkillResponse>  $responses
     * @return array<string, mixed>|null
     */
    private function payloadFor(array $responses, string $skillKey): ?array
    {
        foreach ($responses as $response) {
            if ($response->skillKey === $skillKey && $response->success && $response->result !== null) {
                return $response->result->payload;
            }
        }

        return null;
    }

    /**
     * @param  list<AiSkillResponse>  $responses
     * @return list<array<string, mixed>>
     */
    private function summarizeSkills(array $responses): array
    {
        return array_map(static fn (AiSkillResponse $r) => [
            'skill' => $r->skillKey,
            'success' => $r->success,
            'context_type' => $r->result?->contextType,
            'execution_uuid' => $r->execution->uuid,
            'status' => $r->execution->status,
            'duration_ms' => round($r->execution->durationMs, 2),
            'citation_count' => $r->result !== null ? count($r->result->citations) : 0,
            'warnings' => $r->result?->warnings ?? [],
            'error' => $r->error,
            'error_code' => $r->errorCode,
        ], $responses);
    }

    /**
     * @param  list<AiSkillResponse>  $responses
     * @return list<string>
     */
    private function collectSkillWarnings(array $responses): array
    {
        $warnings = [];
        foreach ($responses as $response) {
            foreach ($response->result?->warnings ?? [] as $warning) {
                $warnings[] = $warning;
            }
            if (! $response->success && $response->errorCode !== null) {
                $warnings[] = 'skill_'.$response->skillKey.'_'.$response->errorCode;
            }
        }

        return $warnings;
    }

    // -------------------------------------------------------------------------
    // Low-level utilities
    // -------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>|null  $data
     * @param  list<string|int>  $path
     */
    private function dig(?array $data, array $path): int
    {
        $value = $this->digRaw($data, $path);

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param  array<string, mixed>|null  $data
     * @param  list<string|int>  $path
     */
    private function digString(?array $data, array $path): ?string
    {
        $value = $this->digRaw($data, $path);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>|null  $data
     * @param  list<string|int>  $path
     */
    private function digRaw(?array $data, array $path): mixed
    {
        $current = $data;
        foreach ($path as $key) {
            if (! is_array($current) || ! array_key_exists($key, $current)) {
                return null;
            }
            $current = $current[$key];
        }

        return $current;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function aiEnabled(): bool
    {
        return (bool) config('ai.feature_flags.enabled', false);
    }
}
