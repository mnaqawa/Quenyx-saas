<?php

namespace App\Services\OpenAI;

use App\DTOs\KnowledgeBaseAnswer;
use App\Exceptions\OpenAIServiceException;
use Illuminate\Support\Facades\Log;
use OpenAI\Contracts\ClientContract;
use OpenAI\Exceptions\ErrorException;
use OpenAI\Exceptions\TransporterException;
use Throwable;

/**
 * Thin wrapper around the OpenAI Responses API with the File Search tool.
 *
 * Uses an existing Vector Store (OPENAI_VECTOR_STORE_ID) as the knowledge
 * base. This service intentionally does NOT use the Assistants API.
 */
class OpenAIService
{
    /** Maximum characters of serialized context appended to the model input. */
    private const MAX_CONTEXT_CHARS = 8000;

    /** Output token ceilings (standard vs. quick mode). */
    private const MAX_OUTPUT_TOKENS = 600;
    private const MAX_OUTPUT_TOKENS_QUICK = 300;

    /** Sampling temperature (low = focused/deterministic). */
    private const TEMPERATURE = 0.2;

    /** File Search retrieval limits (standard vs. quick mode). */
    private const FILE_SEARCH_MAX_RESULTS = 3;
    private const FILE_SEARCH_MAX_RESULTS_QUICK = 2;

    /** File Search ranking threshold; drop low-relevance chunks. */
    private const FILE_SEARCH_SCORE_THRESHOLD = 0.3;

    /**
     * Shared platform grounding prepended to every agent's instructions.
     */
    private const SHARED_INSTRUCTIONS = <<<'TXT'
You are an AI operations assistant for Quenyx vOPS HUB.

Platform facts (background — do NOT repeat unless the user explicitly asks about the platform itself):
- Quenyx vOPS HUB is a modular operations platform. Workspaces are tenant containers; monitoring data is scoped to a workspace.
- QynSight is the active monitoring module and the product runtime. It uses NATIVE monitoring (Laravel-scheduled checks via `observe:run-checks`).
- Nagios is legacy and deprecated. Never recommend Nagios or Nagios plugins as the monitoring runtime.

Answering rules:
- Answer in a maximum of 8 bullet points. Be short, direct, and operational.
- Do not repeat the platform background above unless the user explicitly asks about it.
- For monitoring/operational questions, prioritize the provided QynSight operational context (JSON) over File Search; only consult the knowledge base when the context is insufficient.
- Use the knowledge base (File Search) only when it is actually needed to answer.
- If neither the context nor the knowledge base contains the answer, say so clearly instead of inventing details.
TXT;

    /** Extra directive appended in quick mode for terse, low-cost answers. */
    private const QUICK_INSTRUCTIONS = <<<'TXT'

Quick mode: respond with at most 3-4 short bullets. No preamble, no closing summary.
TXT;

    /**
     * Supported agent types mapped to their role-specific instructions.
     *
     * @var array<string, string>
     */
    private const AGENT_INSTRUCTIONS = [
        'performance_analyst' => 'Act as a Performance Analyst: analyze performance metrics and identify bottlenecks.',
        'anomaly_detector' => 'Act as an Anomaly Detector: detect anomalies and unusual system behaviour.',
        'compliance' => 'Act as a Compliance advisor: provide NCA and SAMA compliance guidance for the GCC region.',
        'capacity_planner' => 'Act as a Capacity Planner: predict future infrastructure requirements and headroom risks.',
    ];

    public function __construct(private readonly ClientContract $client)
    {
    }

    /**
     * @return list<string>
     */
    public static function supportedAgents(): array
    {
        return array_keys(self::AGENT_INSTRUCTIONS);
    }

    /**
     * Ask the knowledge base a question as a specific agent persona.
     *
     * @param  array<string, mixed>  $context  Optional operational context (workspace, QynSight telemetry).
     * @param  bool  $quick  When true, request a shorter/cheaper answer (lower token ceiling, fewer retrieved chunks).
     *
     * @throws OpenAIServiceException
     */
    public function askKnowledgeBase(string $question, string $agentType, array $context = [], bool $quick = false): KnowledgeBaseAnswer
    {
        $agentInstructions = self::AGENT_INSTRUCTIONS[$agentType] ?? null;
        if ($agentInstructions === null) {
            throw new OpenAIServiceException(
                "Unsupported agent type: {$agentType}.",
                422,
                'invalid_agent',
            );
        }

        $vectorStoreId = trim((string) config('openai.vector_store_id'));
        if ($vectorStoreId === '') {
            throw new OpenAIServiceException(
                'Vector store is not configured. Set OPENAI_VECTOR_STORE_ID.',
                500,
                'vector_store_missing',
            );
        }

        $model = $this->resolveModel($agentType);
        $instructions = self::SHARED_INSTRUCTIONS."\n\n".$agentInstructions;
        if ($quick) {
            $instructions .= self::QUICK_INSTRUCTIONS;
        }
        $input = $this->buildInput($question, $context);

        $payload = [
            'model' => $model,
            'instructions' => $instructions,
            'input' => $input,
            'max_output_tokens' => $quick ? self::MAX_OUTPUT_TOKENS_QUICK : self::MAX_OUTPUT_TOKENS,
            'tools' => [[
                'type' => 'file_search',
                'vector_store_ids' => [$vectorStoreId],
                'max_num_results' => $quick ? self::FILE_SEARCH_MAX_RESULTS_QUICK : self::FILE_SEARCH_MAX_RESULTS,
                'ranking_options' => [
                    'ranker' => 'auto',
                    'score_threshold' => self::FILE_SEARCH_SCORE_THRESHOLD,
                ],
            ]],
        ];

        if ($this->supportsTemperature($model)) {
            $payload['temperature'] = self::TEMPERATURE;
        }

        $startedAt = microtime(true);
        Log::info('ai-agent.request.start', [
            'agent' => $agentType,
            'model' => $model,
            'quick' => $quick,
            'max_output_tokens' => $payload['max_output_tokens'],
            'file_search_max_num_results' => $payload['tools'][0]['max_num_results'],
        ]);

        try {
            $response = $this->client->responses()->create($payload);
        } catch (ErrorException $e) {
            $this->logFinish($agentType, $model, $quick, $startedAt, null, 'error');
            throw $this->mapErrorException($e);
        } catch (TransporterException $e) {
            $this->logFinish($agentType, $model, $quick, $startedAt, null, 'timeout');
            throw new OpenAIServiceException(
                'The AI request timed out or OpenAI could not be reached. Please retry.',
                504,
                'timeout',
                $e,
            );
        } catch (Throwable $e) {
            $this->logFinish($agentType, $model, $quick, $startedAt, null, 'error');
            throw new OpenAIServiceException(
                'Unexpected error while contacting the AI provider.',
                502,
                'ai_error',
                $e,
            );
        }

        $answer = trim((string) ($response->outputText ?? ''));
        $totalTokens = $response->usage?->totalTokens ?? null;
        $this->logFinish($agentType, $model, $quick, $startedAt, $totalTokens, 'ok');

        if ($answer === '') {
            throw new OpenAIServiceException(
                'The AI returned an empty answer.',
                502,
                'empty_response',
            );
        }

        return new KnowledgeBaseAnswer(
            answer: $answer,
            agentType: $agentType,
            model: (string) ($response->model ?? $model),
            responseId: $response->id ?? null,
            totalTokens: $totalTokens,
        );
    }

    /**
     * Resolve the model for an agent: per-agent env override, else the global
     * default (OPENAI_MODEL), else gpt-5-mini.
     */
    private function resolveModel(string $agentType): string
    {
        $override = trim((string) config("openai.models.$agentType"));
        if ($override !== '') {
            return $override;
        }

        return (string) (config('openai.model') ?: 'gpt-5-mini');
    }

    /**
     * Some Responses API reasoning models (including gpt-5-mini) reject the
     * temperature parameter. Keep sampling deterministic where supported.
     */
    private function supportsTemperature(string $model): bool
    {
        $normalized = strtolower(trim($model));

        return ! str_starts_with($normalized, 'gpt-5')
            && ! preg_match('/^o\d/', $normalized);
    }

    /**
     * Emit a structured timing log for one Responses API call.
     */
    private function logFinish(
        string $agentType,
        string $model,
        bool $quick,
        float $startedAt,
        ?int $totalTokens,
        string $outcome,
    ): void {
        $finishedAt = microtime(true);

        Log::info('ai-agent.request.finish', [
            'agent' => $agentType,
            'model' => $model,
            'quick' => $quick,
            'outcome' => $outcome,
            'started_at' => date('c', (int) $startedAt),
            'finished_at' => date('c', (int) $finishedAt),
            'duration_ms' => (int) round(($finishedAt - $startedAt) * 1000),
            'total_tokens' => $totalTokens,
        ]);
    }

    /**
     * Compose the model input: the user question plus optional operational
     * context serialized as JSON text (safely escaped and size-capped).
     *
     * @param  array<string, mixed>  $context
     */
    private function buildInput(string $question, array $context): string
    {
        if ($context === []) {
            return $question;
        }

        $json = json_encode(
            $context,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR
        );

        if ($json === false || $json === 'null') {
            return $question;
        }

        if (mb_strlen($json) > self::MAX_CONTEXT_CHARS) {
            $json = mb_substr($json, 0, self::MAX_CONTEXT_CHARS)."\n... [context truncated]";
        }

        return $question."\n\n---\nOperational context (JSON):\n".$json;
    }

    /**
     * Translate an OpenAI API error into a typed domain exception.
     */
    private function mapErrorException(ErrorException $e): OpenAIServiceException
    {
        $code = (string) ($e->getErrorCode() ?? '');
        $type = (string) ($e->getErrorType() ?? '');
        $haystack = strtolower(trim($code.' '.$type.' '.$e->getMessage()));

        if (str_contains($haystack, 'insufficient_quota') || str_contains($haystack, 'quota')) {
            return new OpenAIServiceException(
                'OpenAI quota exceeded. Check your plan and billing details.',
                429,
                'quota_exceeded',
                $e,
            );
        }

        if (str_contains($haystack, 'invalid_api_key')
            || str_contains($haystack, 'incorrect api key')
            || str_contains($haystack, 'invalid authentication')) {
            return new OpenAIServiceException(
                'Invalid OpenAI API key. Verify OPENAI_API_KEY.',
                502,
                'invalid_api_key',
                $e,
            );
        }

        if (str_contains($haystack, 'vector') && str_contains($haystack, 'store')) {
            return new OpenAIServiceException(
                'The configured vector store could not be found. Verify OPENAI_VECTOR_STORE_ID.',
                502,
                'vector_store_missing',
                $e,
            );
        }

        return new OpenAIServiceException(
            $e->getMessage() !== '' ? $e->getMessage() : 'The AI request failed.',
            502,
            'ai_error',
            $e,
        );
    }
}
