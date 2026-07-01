<?php

namespace App\Services\AI\Providers;

use App\Contracts\Ai\AiProviderInterface;
use App\DataTransferObjects\Ai\AiCompletionRequest;
use App\DataTransferObjects\Ai\AiCompletionResponse;
use App\DataTransferObjects\Ai\AiEmbeddingsRequest;
use App\DataTransferObjects\Ai\AiEmbeddingsResponse;
use App\DataTransferObjects\Ai\AiProviderHealth;
use App\DataTransferObjects\Ai\AiStreamChunk;
use App\DataTransferObjects\Ai\AiUsage;
use App\Enums\Ai\AiCapability;
use App\Exceptions\Ai\AiProviderException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * OpenAI provider built on the **Responses API** (NOT the Assistants API, NOT legacy Chat
 * Completions). Supports structured JSON output, streaming, citation annotations, response
 * metadata, and a health probe. All configuration (API key, base URL, model) comes from
 * config/ai.php — nothing is hardcoded. This class makes external HTTP calls and is therefore
 * only invoked when the AI feature flag is enabled.
 */
class OpenAiProvider implements AiProviderInterface
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config = [])
    {
    }

    public function key(): string
    {
        return 'openai';
    }

    public function chat(AiCompletionRequest $request): AiCompletionResponse
    {
        return $this->execute($request);
    }

    public function responses(AiCompletionRequest $request): AiCompletionResponse
    {
        return $this->execute($request);
    }

    public function stream(AiCompletionRequest $request): iterable
    {
        $payload = $this->buildPayload($request, true);

        $response = $this->client(stream: true)->withOptions(['stream' => true])
            ->post('/responses', $payload);

        if ($response->failed()) {
            throw new AiProviderException('OpenAI stream request failed.', 'ai_provider_upstream', 502);
        }

        $body = $response->toPsrResponse()->getBody();
        $buffer = '';

        while (! $body->eof()) {
            $buffer .= $body->read(1024);

            while (($newlinePos = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $newlinePos));
                $buffer = substr($buffer, $newlinePos + 1);

                if ($line === '' || ! str_starts_with($line, 'data:')) {
                    continue;
                }

                $data = trim(substr($line, 5));
                if ($data === '[DONE]') {
                    yield new AiStreamChunk('', true);

                    return;
                }

                $event = json_decode($data, true);
                if (! is_array($event)) {
                    continue;
                }

                $type = $event['type'] ?? null;
                if ($type === 'response.output_text.delta' && isset($event['delta'])) {
                    yield new AiStreamChunk((string) $event['delta']);
                } elseif ($type === 'response.completed') {
                    yield new AiStreamChunk('', true, $this->parseUsage($event['response']['usage'] ?? []));
                }
            }
        }
    }

    public function embeddings(AiEmbeddingsRequest $request): AiEmbeddingsResponse
    {
        $model = $request->model ?? ($this->config['embeddings_model'] ?? null);
        if ($model === null || $model === '') {
            throw new AiProviderException('No OpenAI embeddings model configured.', 'ai_provider_misconfigured');
        }

        $response = $this->client()->post('/embeddings', [
            'model' => $model,
            'input' => $request->inputs,
        ]);

        if ($response->failed()) {
            throw new AiProviderException('OpenAI embeddings request failed.', 'ai_provider_upstream', 502);
        }

        $json = $response->json();
        $vectors = array_map(static fn ($item) => $item['embedding'] ?? [], $json['data'] ?? []);

        return new AiEmbeddingsResponse('openai', $model, $vectors, $this->parseUsage($json['usage'] ?? []));
    }

    public function health(): AiProviderHealth
    {
        if (($this->config['api_key'] ?? null) === null) {
            return new AiProviderHealth('openai', false, 'unconfigured', 'OPENAI_API_KEY is not set.');
        }

        try {
            $response = $this->client(timeout: 5)->get('/models');

            return $response->successful()
                ? new AiProviderHealth('openai', true, 'ready')
                : new AiProviderHealth('openai', false, 'unhealthy', 'HTTP '.$response->status());
        } catch (\Throwable $e) {
            return new AiProviderHealth('openai', false, 'unreachable', $e->getMessage());
        }
    }

    public function supportedCapabilities(): array
    {
        return [
            AiCapability::Chat,
            AiCapability::Stream,
            AiCapability::Embeddings,
            AiCapability::Responses,
            AiCapability::StructuredJson,
            AiCapability::Citations,
        ];
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private function execute(AiCompletionRequest $request): AiCompletionResponse
    {
        $payload = $this->buildPayload($request, false);

        $response = $this->client()->post('/responses', $payload);

        if ($response->failed()) {
            throw new AiProviderException(
                $this->upstreamErrorMessage('OpenAI request failed', $response->status(), $response->json(), $response->body()),
                'ai_provider_upstream',
                $response->status() >= 500 ? 502 : 422,
            );
        }

        return $this->parseResponse($response->json(), (string) $payload['model']);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(AiCompletionRequest $request, bool $stream): array
    {
        $model = $request->model ?? ($this->config['model'] ?? null);
        if ($model === null || $model === '') {
            throw new AiProviderException('No OpenAI model configured.', 'ai_provider_misconfigured');
        }

        $instructions = null;
        $input = [];
        foreach ($request->messagesArray() as $message) {
            $role = (string) ($message['role'] ?? 'user');
            $content = (string) ($message['content'] ?? '');
            if ($role === 'system') {
                $instructions = $instructions === null ? $content : $instructions."\n\n".$content;

                continue;
            }
            $input[] = ['role' => $role, 'content' => $content];
        }

        if ($input === []) {
            $input = $request->messagesArray();
        }

        $payload = [
            'model' => $model,
            'input' => count($input) === 1 && ($input[0]['role'] ?? '') === 'user'
                ? (string) $input[0]['content']
                : $input,
            'max_output_tokens' => $request->maxTokens ?? (int) config('ai.defaults.max_tokens', 1024),
            'stream' => $stream,
        ];

        if ($this->supportsTemperature($model)) {
            $payload['temperature'] = $request->temperature ?? (float) config('ai.defaults.temperature', 0.0);
        }

        $this->applyModelSpecificOptions($payload, $model, $request->responseFormat === 'json');

        if ($instructions !== null && $instructions !== '') {
            $payload['instructions'] = $instructions;
        }

        if ($request->responseFormat === 'json') {
            $payload['text'] = ['format' => $request->jsonSchema !== null
                ? ['type' => 'json_schema', 'name' => 'compliance_response', 'schema' => $request->jsonSchema]
                : ['type' => 'json_object']];
        }

        if ($request->metadata !== []) {
            $payload['metadata'] = $request->metadata;
        }

        if (! empty($request->metadata['use_file_search'])) {
            $this->attachFileSearchTool($payload);
        }

        $payload['max_output_tokens'] = $this->resolveMaxOutputTokens($model, (int) $payload['max_output_tokens']);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function parseResponse(array $json, string $model): AiCompletionResponse
    {
        $text = '';
        $citations = [];

        foreach ($json['output'] ?? [] as $item) {
            if (($item['type'] ?? null) !== 'message') {
                continue;
            }
            foreach ($item['content'] ?? [] as $content) {
                if (($content['type'] ?? null) === 'output_text') {
                    $text .= (string) ($content['text'] ?? '');
                    foreach ($content['annotations'] ?? [] as $annotation) {
                        $citations[] = $annotation;
                    }
                }
            }
        }

        if ($text === '' && isset($json['output_text'])) {
            $text = (string) $json['output_text'];
        }

        if (($json['status'] ?? null) === 'incomplete' && $text !== '') {
            $text .= "\n\n[Note: response was truncated by the model output limit. Retry or increase AI_MAX_TOKENS_REASONING.]";
        }

        $structured = null;
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            $structured = $decoded;
        }

        return new AiCompletionResponse(
            provider: 'openai',
            model: $model,
            content: $text,
            structured: $structured,
            citations: $citations,
            usage: $this->parseUsage($json['usage'] ?? []),
            finishReason: $json['status'] ?? null,
            id: $json['id'] ?? null,
            mocked: false,
            metadata: ['response_id' => $json['id'] ?? null],
        );
    }

    /**
     * @param  array<string, mixed>  $usage
     */
    private function parseUsage(array $usage): AiUsage
    {
        return new AiUsage(
            promptTokens: (int) ($usage['input_tokens'] ?? $usage['prompt_tokens'] ?? 0),
            completionTokens: (int) ($usage['output_tokens'] ?? $usage['completion_tokens'] ?? 0),
            totalTokens: (int) ($usage['total_tokens'] ?? 0),
        );
    }

    private function client(?int $timeout = null, bool $stream = false): PendingRequest
    {
        $apiKey = $this->config['api_key'] ?? null;
        if ($apiKey === null || $apiKey === '') {
            throw new AiProviderException('OPENAI_API_KEY is not configured.', 'ai_provider_misconfigured');
        }

        $baseUrl = rtrim((string) ($this->config['base_url'] ?? 'https://api.openai.com/v1'), '/');

        $request = Http::baseUrl($baseUrl)
            ->timeout($timeout ?? $this->clientTimeout())
            ->withToken($apiKey)
            ->acceptJson();

        if (! empty($this->config['organization'])) {
            $request = $request->withHeaders(['OpenAI-Organization' => (string) $this->config['organization']]);
        }

        return $request;
    }

    private function clientTimeout(): int
    {
        return max(
            (int) config('ai.defaults.timeout', 60),
            (int) config('openai.request_timeout', 60),
        );
    }

    /**
     * gpt-5* and o-series reasoning models reject the temperature parameter on the Responses API.
     */
    private function supportsTemperature(string $model): bool
    {
        $normalized = strtolower(trim($model));

        return ! str_starts_with($normalized, 'gpt-5')
            && ! preg_match('/^o\d/', $normalized);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function applyModelSpecificOptions(array &$payload, string $model, bool $jsonResponse): void
    {
        $normalized = strtolower(trim($model));
        if (! str_starts_with($normalized, 'gpt-5')) {
            return;
        }

        $payload['reasoning'] = ['effort' => 'medium'];
        if (! $jsonResponse) {
            $payload['text'] = ['verbosity' => 'medium'];
        }
    }

    private function resolveMaxOutputTokens(string $model, int $requested): int
    {
        $normalized = strtolower(trim($model));
        if (str_starts_with($normalized, 'gpt-5') || preg_match('/^o\d/', $normalized)) {
            return max($requested, (int) config('ai.defaults.max_tokens_reasoning', 4096));
        }

        return max($requested, (int) config('ai.defaults.max_tokens', 2048));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function attachFileSearchTool(array &$payload): void
    {
        $vectorStoreId = trim((string) config('openai.vector_store_id', ''));
        if ($vectorStoreId === '') {
            return;
        }

        $payload['tools'] = [[
            'type' => 'file_search',
            'vector_store_ids' => [$vectorStoreId],
            'max_num_results' => (int) config('ai.workspace.file_search_max_results', 5),
            'ranking_options' => [
                'ranker' => 'auto',
                'score_threshold' => 0.2,
            ],
        ]];
    }

    /**
     * @param  array<string, mixed>|null  $json
     */
    private function upstreamErrorMessage(string $prefix, int $status, ?array $json, string $body): string
    {
        $detail = null;
        if (is_array($json)) {
            $detail = $json['error']['message'] ?? $json['message'] ?? null;
        }
        if (! is_string($detail) || $detail === '') {
            $detail = strlen($body) > 240 ? substr($body, 0, 240).'…' : $body;
        }

        return trim($prefix.' (HTTP '.$status.'): '.$detail);
    }
}
