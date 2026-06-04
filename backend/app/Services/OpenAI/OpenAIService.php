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
    /**
     * Supported agent types mapped to their system instructions.
     *
     * @var array<string, string>
     */
    private const AGENT_INSTRUCTIONS = [
        'performance_analyst' => 'Analyze performance metrics and identify bottlenecks.',
        'anomaly_detector' => 'Detect anomalies and unusual system behaviour.',
        'compliance' => 'Provide NCA and SAMA compliance guidance.',
        'capacity_planner' => 'Predict future infrastructure requirements.',
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
     * @throws OpenAIServiceException
     */
    public function askKnowledgeBase(string $question, string $agentType): KnowledgeBaseAnswer
    {
        $instructions = self::AGENT_INSTRUCTIONS[$agentType] ?? null;
        if ($instructions === null) {
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

        try {
            $response = $this->client->responses()->create([
                'model' => $model,
                'instructions' => $instructions,
                'input' => $question,
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
