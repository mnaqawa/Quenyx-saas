<?php

namespace App\Services\OpenAI;

use App\DTOs\KnowledgeBaseAnswer;
use App\Exceptions\OpenAIServiceException;
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

    /**
     * Shared platform grounding prepended to every agent's instructions.
     */
    private const SHARED_INSTRUCTIONS = <<<'TXT'
You are an AI operations assistant for Quenyx vOPS HUB.

Platform facts you must always respect:
- Quenyx vOPS HUB is a modular operations platform. Workspaces are the tenant containers; all monitoring data is scoped to a workspace.
- QynSight is the active monitoring module and the product runtime.
- QynSight uses NATIVE monitoring (Laravel-scheduled checks via the `observe:run-checks` command). This is the runtime engine.
- Nagios is legacy and deprecated. Never recommend Nagios, Nagios plugins-as-runtime, or Nagios-specific tooling as the monitoring runtime.

Answering rules:
- Use the knowledge base (File Search) as your primary source of truth.
- Keep answers concise, operational, and actionable.
- If operational context (JSON) is provided, ground your answer in it.
- If the knowledge base does not contain the answer, say so clearly instead of inventing details.
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
     *
     * @throws OpenAIServiceException
     */
    public function askKnowledgeBase(string $question, string $agentType, array $context = []): KnowledgeBaseAnswer
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

        $model = (string) (config('openai.model') ?: 'gpt-5-mini');
        $instructions = self::SHARED_INSTRUCTIONS."\n\n".$agentInstructions;
        $input = $this->buildInput($question, $context);

        try {
            $response = $this->client->responses()->create([
                'model' => $model,
                'instructions' => $instructions,
                'input' => $input,
                'tools' => [[
                    'type' => 'file_search',
                    'vector_store_ids' => [$vectorStoreId],
                ]],
            ]);
        } catch (ErrorException $e) {
            throw $this->mapErrorException($e);
        } catch (TransporterException $e) {
            throw new OpenAIServiceException(
                'The AI request timed out or OpenAI could not be reached. Please retry.',
                504,
                'timeout',
                $e,
            );
        } catch (Throwable $e) {
            throw new OpenAIServiceException(
                'Unexpected error while contacting the AI provider.',
                502,
                'ai_error',
                $e,
            );
        }

        $answer = trim((string) ($response->outputText ?? ''));
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
            totalTokens: $response->usage?->totalTokens ?? null,
        );
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
