<?php

namespace App\Services\Compliance\Copilot;

use App\DataTransferObjects\Ai\AiCompletionRequest;
use App\DataTransferObjects\Ai\AiSkillResponse;
use App\DataTransferObjects\Ai\AiUsage;
use App\Enums\Compliance\Copilot\ComplianceCopilotIntent;
use App\Exceptions\Ai\AiProviderException;
use App\DataTransferObjects\Compliance\Retrieval\RetrievalQuery;
use App\DataTransferObjects\Compliance\Reasoning\ComplianceReasoningContext;
use App\Enums\Compliance\Retrieval\ComplianceRetrievalMode;
use App\Services\AI\AiExecutionResolver;
use App\Services\AI\AiProviderRegistry;
use App\Services\AI\CompliancePromptOrchestrator;
use App\Services\AI\Skills\AiSkillRouter;
use App\Services\Compliance\Rag\ComplianceHybridRetrievalService;
use App\Services\Compliance\Rag\ComplianceRagContextBuilder;
use App\Services\Compliance\Reasoning\ComplianceReasoningEngine;
use App\Services\Compliance\Retrieval\ComplianceRetrievalService;

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
        private readonly AiExecutionResolver $execution,
        private readonly ComplianceCopilotResponseValidator $validator,
        private readonly ComplianceCopilotCitationVerifier $citationVerifier,
        private readonly ComplianceRetrievalService $retrieval,
        private readonly ComplianceReasoningEngine $reasoningEngine,
        private readonly ComplianceHybridRetrievalService $hybridRetrieval,
        private readonly ComplianceRagContextBuilder $ragContextBuilder,
    ) {}

    /**
     * @param  array<string, mixed>  $options  framework, release, provider
     * @return array<string, mixed>
     */
    public function handle(int $projectId, string $userMessage, array $options = []): array
    {
        $framework = $this->stringOrNull($options['framework'] ?? null);
        $release = $this->stringOrNull($options['release'] ?? null);

        $plan = $this->planner->plan($userMessage, $framework, $release);
        $intent = $plan['intent'];
        $scope = $plan['scope'];

        if (! $intent->isSupported()) {
            return $this->unsupportedResult($scope);
        }

        // An explicitly-requested framework/release that does not exist fails clearly.
        if (($scope['error_code'] ?? null) === 'scope_unresolved') {
            return $this->scopeFailedResult($intent, $scope);
        }

        $requests = $this->selector->select($plan, $projectId, $scope['framework_key'], $scope['release_code']);
        $responses = $this->router->executeMany($requests);

        // Deterministic grounding from the skills (citations + guardrails).
        $skillPrompt = $this->orchestrator->composeFromSkills($responses, $userMessage);
        $corpusCitations = $skillPrompt->citations;
        $groundingRefs = $this->buildGroundingRefs($responses);
        $guardrails = $skillPrompt->guardrails;
        $skillWarnings = $this->collectSkillWarnings($responses);

        // QCIF Sprint 15 — deterministic retrieval over the SAME skill responses (no AI/DB).
        $retrievalQuery = $this->buildRetrievalQuery($projectId, $userMessage, $plan, $scope);
        $retrievalResult = $this->retrieval->fromResponses($retrievalQuery, $responses, $scope);

        // QCIF Sprint 17 — optional Hybrid Retrieval (deterministic + optional vector). Falls back to
        // deterministic retrieval (with a warning) if the vector provider is unavailable.
        $ragEnabled = $this->ragEnabled();
        if ($ragEnabled) {
            $retrievalResult = $this->hybridRetrieval->augment($retrievalResult, $retrievalQuery);
        }

        // QCIF Sprint 16 — the deterministic Reasoning Engine decides WHAT to answer BEFORE any LLM.
        $reasoning = $this->reasoningEngine->reason(new ComplianceReasoningContext(
            intent: $intent,
            query: $userMessage,
            code: $plan['code'] ?? null,
            scope: $scope,
            skillPayloads: $this->collectPayloads($responses),
            corpusCitations: $corpusCitations,
            groundingRefs: $groundingRefs,
            retrievalChunks: array_map(static fn ($c) => $c->toArray(), $retrievalResult->chunks),
            guardrails: $guardrails,
        ));

        // QCIF Sprint 17 — build a bounded, cited RAG context package (no AI call). Only when enabled.
        $ragContext = $ragEnabled
            ? $this->ragContextBuilder->build($retrievalResult, $reasoning, $responses)
            : null;

        // The Prompt Orchestrator consumes the ReasoningOutput (not raw skills) when enabled. When RAG
        // is on, the bounded RAG context is appended to the prompt as RETRIEVED CONTEXT.
        $prompt = $this->reasoningEnabled()
            ? $this->orchestrator->composeFromReasoning($reasoning, $userMessage, $ragContext !== null ? ['rag_context' => $ragContext] : [])
            : $skillPrompt;

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
            return $this->citationFailedResult($intent, $mode, $answer['provider'], $responses, $guardrails, $citationCheck['warnings'], $scope);
        }

        $validatorWarnings = $this->validator->validate($guardrails, $answer['answer_en'], $answer['answer_ar'], $mode);
        $ragWarnings = $ragEnabled ? $retrievalResult->warnings : [];
        $warnings = array_values(array_unique([...$skillWarnings, ...$citationCheck['warnings'], ...$validatorWarnings, ...$this->scopeWarnings($scope), ...$reasoning->warnings, ...$ragWarnings]));

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
            'scope' => $this->scopeBlock($scope),
            'reasoning' => $reasoning->toArray(),
            'retrieval_context' => (bool) config('ai.copilot.retrieval_enabled', false) ? $retrievalResult->toCopilotContext() : null,
            'rag_context' => $ragContext,
            'demo' => $this->demoMode()
                ? $this->buildDemoBlock($reasoning, $retrievalResult, $groundingRefs, $corpusCitations, $responses)
                : null,
            'usage' => $answer['usage']->toArray(),
            'mocked' => $answer['mocked'],
            'plain_answer' => trim($answer['answer_en']."\n".$answer['answer_ar']),
        ];
    }

    /**
     * Build the deterministic retrieval query (QCIF Sprint 15) used to turn the SAME skill responses
     * (skills are NOT re-run) into retrieval chunks, and (Sprint 17) to drive Hybrid Retrieval.
     *
     * @param  array{intent: ComplianceCopilotIntent, code: ?string, query: ?string, entity_type: string, scope: array<string, mixed>}  $plan
     * @param  array<string, mixed>  $scope
     */
    private function buildRetrievalQuery(int $projectId, string $userMessage, array $plan, array $scope): RetrievalQuery
    {
        return new RetrievalQuery(
            query: $userMessage,
            mode: ComplianceRetrievalMode::CopilotContext,
            projectId: $projectId,
            framework: $scope['framework_key'] ?? null,
            release: $scope['release_code'] ?? null,
            limit: 20,
            code: $plan['code'] ?? null,
        );
    }

    /**
     * Map successful skill responses to a skill-keyed payload map for the Reasoning Engine.
     *
     * @param  list<AiSkillResponse>  $responses
     * @return array<string, array<string, mixed>>
     */
    private function collectPayloads(array $responses): array
    {
        $payloads = [];
        foreach ($responses as $response) {
            if ($response->success && $response->result !== null) {
                $payloads[$response->skillKey] = $response->result->payload;
            }
        }

        return $payloads;
    }

    private function reasoningEnabled(): bool
    {
        return (bool) config('ai.copilot.reasoning_enabled', true);
    }

    private function ragEnabled(): bool
    {
        return (bool) config('ai.copilot.rag_enabled', false);
    }

    private function demoMode(): bool
    {
        return (bool) config('ai.copilot.demo_mode', false);
    }

    /**
     * QCIF Sprint 18 — assemble the demonstration block exposing the deterministic BUSINESS reasoning
     * behind the answer. This surfaces EXISTING intelligence only (reasoning trace, citations,
     * retrieved chunks, recommendation sources, evidence chain). It is NOT chain-of-thought.
     *
     * @param  \App\DataTransferObjects\Compliance\Reasoning\ReasoningOutput  $reasoning
     * @param  RetrievalResult  $retrievalResult
     * @param  list<array<string, mixed>>  $groundingRefs
     * @param  list<array<string, mixed>>  $corpusCitations
     * @param  list<AiSkillResponse>  $responses
     * @return array<string, mixed>
     */
    private function buildDemoBlock($reasoning, $retrievalResult, array $groundingRefs, array $corpusCitations, array $responses): array
    {
        return [
            'reasoning_trace' => $reasoning->trace->toArray(),
            'rules_fired' => $reasoning->explanation->appliedRuleIds,
            'findings' => array_map(static fn ($f) => $f->toArray(), $reasoning->findings),
            'recommendation_source' => array_map(static fn ($r) => [
                'uuid' => $r->uuid,
                'rule_id' => $r->ruleId,
                'action' => $r->action,
                'priority' => $r->priority,
                'citations' => $r->citations,
            ], $reasoning->recommendations),
            'citations' => $this->mergeCitations($corpusCitations, $groundingRefs),
            'retrieved_chunks' => array_map(static fn ($c) => $c->toArray(), $retrievalResult->chunks),
            'evidence_chain' => $groundingRefs,
            'missing_information' => $reasoning->missingInformation,
            'answer_strategy' => $reasoning->answerStrategy(),
            'disclaimer' => 'Business reasoning only — deterministic, rule-based, and citation-grounded. Not chain-of-thought.',
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
     * @param  array<string, mixed>  $scope
     * @return array<string, mixed>
     */
    private function unsupportedResult(array $scope): array
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
            'scope' => $this->scopeBlock($scope),
            'usage' => (new AiUsage())->toArray(),
            'mocked' => true,
            'plain_answer' => 'unsupported_intent',
        ];
    }

    /**
     * Explicit framework/release that does not exist — fail clearly (HTTP 422 at the controller).
     *
     * @param  array<string, mixed>  $scope
     * @return array<string, mixed>
     */
    private function scopeFailedResult(ComplianceCopilotIntent $intent, array $scope): array
    {
        return [
            'intent' => $intent->value,
            'mode' => $this->aiEnabled() ? 'ai' : 'mock',
            'provider' => null,
            'answer_en' => '',
            'answer_ar' => '',
            'citations' => [],
            'skill_results' => [],
            'guardrails' => [],
            'warnings' => array_values(array_unique([...$this->scopeWarnings($scope), 'scope_unresolved'])),
            'scope' => $this->scopeBlock($scope),
            'usage' => (new AiUsage())->toArray(),
            'mocked' => ! $this->aiEnabled(),
            'plain_answer' => '',
            'error_code' => 'scope_unresolved',
        ];
    }

    /**
     * @param  list<AiSkillResponse>  $responses
     * @param  array<string, bool>  $guardrails
     * @param  list<string>  $warnings
     * @param  array<string, mixed>  $scope
     * @return array<string, mixed>
     */
    private function citationFailedResult(ComplianceCopilotIntent $intent, string $mode, ?string $provider, array $responses, array $guardrails, array $warnings, array $scope): array
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
            'warnings' => array_values(array_unique([...$warnings, ...$this->scopeWarnings($scope), 'citation_validation_failed', 'needs_review'])),
            'scope' => $this->scopeBlock($scope),
            'usage' => (new AiUsage())->toArray(),
            'mocked' => $mode === 'mock',
            'plain_answer' => '',
            'error_code' => 'citation_validation_failed',
        ];
    }

    /**
     * The response-contract scope block (QCIF Sprint 14.1).
     *
     * @param  array<string, mixed>  $scope
     * @return array<string, mixed>
     */
    private function scopeBlock(array $scope): array
    {
        return [
            'framework_key' => $scope['framework_key'] ?? null,
            'release_code' => $scope['release_code'] ?? null,
            'revision_uuid' => $scope['revision_uuid'] ?? null,
            'source' => $scope['source'] ?? 'unresolved',
            'warnings' => array_values($scope['warnings'] ?? []),
        ];
    }

    /**
     * @param  array<string, mixed>  $scope
     * @return list<string>
     */
    private function scopeWarnings(array $scope): array
    {
        return array_values($scope['warnings'] ?? []);
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
        return $this->execution->isLiveExecution(null);
    }
}
