<?php

namespace App\Services\Ai\Providers;

use App\Contracts\Ai\AiProviderInterface;
use App\DataTransferObjects\Ai\AiCompletionRequest;
use App\DataTransferObjects\Ai\AiCompletionResponse;
use App\DataTransferObjects\Ai\AiEmbeddingsRequest;
use App\DataTransferObjects\Ai\AiEmbeddingsResponse;
use App\DataTransferObjects\Ai\AiProviderHealth;
use App\DataTransferObjects\Ai\AiStreamChunk;
use App\DataTransferObjects\Ai\AiUsage;
use App\Enums\Ai\AiCapability;

/**
 * Deterministic, network-free provider used whenever AI execution is disabled (the default)
 * or when explicitly selected for testing. It performs NO external calls and invents NO
 * compliance content — it echoes a fixed notice so the orchestration platform can be
 * exercised end-to-end without any real model.
 */
class MockAiProvider implements AiProviderInterface
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config = [])
    {
    }

    public function key(): string
    {
        return 'mock';
    }

    public function chat(AiCompletionRequest $request): AiCompletionResponse
    {
        return $this->mockResponse($request);
    }

    public function responses(AiCompletionRequest $request): AiCompletionResponse
    {
        return $this->mockResponse($request);
    }

    public function stream(AiCompletionRequest $request): iterable
    {
        yield new AiStreamChunk('[mock] ');
        yield new AiStreamChunk('AI execution is disabled.');
        yield new AiStreamChunk('', true, new AiUsage(0, 0, 0));
    }

    public function embeddings(AiEmbeddingsRequest $request): AiEmbeddingsResponse
    {
        $vectors = array_map(static fn () => [], $request->inputs);

        return new AiEmbeddingsResponse('mock', null, $vectors, new AiUsage(), true);
    }

    public function health(): AiProviderHealth
    {
        return new AiProviderHealth('mock', true, 'ready', 'Mock provider — no external calls.');
    }

    public function supportedCapabilities(): array
    {
        return [
            AiCapability::Chat,
            AiCapability::Stream,
            AiCapability::Responses,
            AiCapability::Embeddings,
        ];
    }

    private function mockResponse(AiCompletionRequest $request): AiCompletionResponse
    {
        $content = '[mock] AI execution is disabled. Enable it via the ai.feature_flags.enabled flag to use a real provider.';
        $structured = $request->responseFormat === 'json' ? ['mock' => true, 'message' => $content] : null;

        return new AiCompletionResponse(
            provider: 'mock',
            model: null,
            content: $content,
            structured: $structured,
            citations: [],
            usage: new AiUsage(),
            finishReason: 'mock',
            id: 'mock-'.substr(md5(json_encode($request->messagesArray()) ?: ''), 0, 12),
            mocked: true,
            metadata: ['mocked' => true],
        );
    }
}
