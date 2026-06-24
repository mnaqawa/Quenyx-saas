<?php

namespace App\Contracts\Ai;

use App\DataTransferObjects\Ai\AiCompletionRequest;
use App\DataTransferObjects\Ai\AiCompletionResponse;
use App\DataTransferObjects\Ai\AiEmbeddingsRequest;
use App\DataTransferObjects\Ai\AiEmbeddingsResponse;
use App\DataTransferObjects\Ai\AiProviderHealth;
use App\Enums\Ai\AiCapability;

/**
 * Provider-agnostic AI execution contract. ALL provider-specific code (HTTP, wire formats,
 * model names) lives behind implementations of this interface; the rest of the platform
 * depends only on these DTOs. Implementations are resolved via AiProviderRegistry.
 */
interface AiProviderInterface
{
    /**
     * Stable provider key (e.g. "openai", "mock"). Must match the config registry key.
     */
    public function key(): string;

    /**
     * Conversational completion (non-streaming).
     */
    public function chat(AiCompletionRequest $request): AiCompletionResponse;

    /**
     * Streaming completion. Yields incremental chunks until done.
     *
     * @return iterable<\App\DataTransferObjects\Ai\AiStreamChunk>
     */
    public function stream(AiCompletionRequest $request): iterable;

    /**
     * Vector embeddings for one or more inputs.
     */
    public function embeddings(AiEmbeddingsRequest $request): AiEmbeddingsResponse;

    /**
     * Structured / Responses-API style completion (supports JSON output + citations).
     */
    public function responses(AiCompletionRequest $request): AiCompletionResponse;

    /**
     * Lightweight readiness probe. Must never throw — returns an unhealthy result instead.
     */
    public function health(): AiProviderHealth;

    /**
     * @return list<AiCapability>
     */
    public function supportedCapabilities(): array;
}
