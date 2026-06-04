<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Thin client for an OpenAI-compatible Chat Completions API.
 *
 * Provider-agnostic: works with OpenAI, OpenRouter, Azure-compatible gateways,
 * Groq, Together, or a self-hosted vLLM/Ollama proxy. Never fabricates a reply;
 * upstream failures raise AiException so the caller can surface a real error.
 */
class LlmClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey,
        private readonly string $model,
        private readonly int $timeout,
        private readonly float $temperature,
        private readonly int $maxTokens,
    ) {
    }

    public static function fromConfig(): self
    {
        return new self(
            baseUrl: (string) config('ai.base_url'),
            apiKey: config('ai.api_key'),
            model: (string) config('ai.model'),
            timeout: (int) config('ai.timeout', 60),
            temperature: (float) config('ai.temperature', 0.3),
            maxTokens: (int) config('ai.max_tokens', 900),
        );
    }

    public function isConfigured(): bool
    {
        return is_string($this->apiKey) && $this->apiKey !== '';
    }

    public function model(): string
    {
        return $this->model;
    }

    /**
     * Run a (non-streaming) chat completion.
     *
     * @param array<int, array{role: string, content: string}> $messages
     * @return array{content: string, model: string, usage: array<string, int>}
     */
    public function chat(array $messages, ?float $temperature = null): array
    {
        if (! $this->isConfigured()) {
            throw AiException::notConfigured();
        }

        try {
            $response = Http::withToken($this->apiKey)
                ->acceptJson()
                ->timeout($this->timeout)
                ->post($this->baseUrl.'/chat/completions', [
                    'model' => $this->model,
                    'messages' => $messages,
                    'temperature' => $temperature ?? $this->temperature,
                    'max_tokens' => $this->maxTokens,
                ]);
        } catch (Throwable $e) {
            Log::error('LlmClient.chat transport error', ['message' => $e->getMessage()]);
            throw AiException::upstream($e->getMessage());
        }

        if ($response->failed()) {
            $detail = $response->json('error.message') ?? ('HTTP '.$response->status());
            Log::warning('LlmClient.chat upstream error', [
                'status' => $response->status(),
                'detail' => $detail,
            ]);
            throw AiException::upstream(is_string($detail) ? $detail : 'HTTP '.$response->status());
        }

        $content = $response->json('choices.0.message.content');
        if (! is_string($content) || $content === '') {
            throw AiException::upstream('Empty completion returned by provider.');
        }

        return [
            'content' => $content,
            'model' => $response->json('model') ?? $this->model,
            'usage' => [
                'prompt_tokens' => (int) ($response->json('usage.prompt_tokens') ?? 0),
                'completion_tokens' => (int) ($response->json('usage.completion_tokens') ?? 0),
                'total_tokens' => (int) ($response->json('usage.total_tokens') ?? 0),
            ],
        ];
    }

    /**
     * Stream a chat completion, invoking $onDelta($text) for each token chunk.
     * Returns the full concatenated text once the stream ends.
     *
     * @param array<int, array{role: string, content: string}> $messages
     * @param callable(string): void $onDelta
     */
    public function stream(array $messages, callable $onDelta, ?float $temperature = null): string
    {
        if (! $this->isConfigured()) {
            throw AiException::notConfigured();
        }

        try {
            $response = Http::withToken($this->apiKey)
                ->acceptJson()
                ->timeout($this->timeout)
                ->withOptions(['stream' => true])
                ->post($this->baseUrl.'/chat/completions', [
                    'model' => $this->model,
                    'messages' => $messages,
                    'temperature' => $temperature ?? $this->temperature,
                    'max_tokens' => $this->maxTokens,
                    'stream' => true,
                ]);
        } catch (Throwable $e) {
            Log::error('LlmClient.stream transport error', ['message' => $e->getMessage()]);
            throw AiException::upstream($e->getMessage());
        }

        if ($response->failed()) {
            $detail = $response->json('error.message') ?? ('HTTP '.$response->status());
            throw AiException::upstream(is_string($detail) ? $detail : 'HTTP '.$response->status());
        }

        $body = $response->toPsrResponse()->getBody();
        $buffer = '';
        $full = '';

        while (! $body->eof()) {
            $buffer .= $body->read(1024);

            // SSE frames are separated by a blank line.
            while (($sepPos = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $sepPos));
                $buffer = substr($buffer, $sepPos + 1);

                if ($line === '' || ! str_starts_with($line, 'data:')) {
                    continue;
                }

                $data = trim(substr($line, strlen('data:')));
                if ($data === '[DONE]') {
                    return $full;
                }

                $decoded = json_decode($data, true);
                $delta = $decoded['choices'][0]['delta']['content'] ?? null;
                if (is_string($delta) && $delta !== '') {
                    $full .= $delta;
                    $onDelta($delta);
                }
            }
        }

        return $full;
    }
}
