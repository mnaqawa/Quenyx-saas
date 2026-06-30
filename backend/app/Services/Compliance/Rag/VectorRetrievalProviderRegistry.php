<?php

namespace App\Services\Compliance\Rag;

use App\Contracts\Compliance\Retrieval\VectorRetrievalProviderInterface;
use App\Services\AI\AiProviderRegistry;

/**
 * Resolves the configured vector retrieval provider (QCIF Sprint 17), mirroring the AI Provider
 * Registry pattern. Returns null when RAG is disabled or no provider is configured, so the hybrid
 * layer transparently falls back to deterministic retrieval. This is the ONLY place that turns the
 * `VECTOR_PROVIDER` key into a concrete provider — keeping providers swappable and OFF by default.
 */
class VectorRetrievalProviderRegistry
{
    public function __construct(private readonly AiProviderRegistry $aiRegistry) {}

    public function enabled(): bool
    {
        return (bool) config('ai.rag.enabled', false);
    }

    public function providerKey(): ?string
    {
        $key = config('ai.rag.vector_provider');

        return is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * Resolve the active vector provider, or null when RAG is disabled / no provider configured /
     * the configured provider is unknown or not implemented.
     */
    public function resolve(): ?VectorRetrievalProviderInterface
    {
        if (! $this->enabled()) {
            return null;
        }

        $key = $this->providerKey();
        if ($key === null) {
            return null;
        }

        $providers = (array) config('ai.rag.providers', []);
        $config = $providers[$key] ?? null;
        $class = is_array($config) ? ($config['class'] ?? null) : null;

        if (! is_string($class) || ! class_exists($class)) {
            return null;
        }

        $instance = new $class((array) $config, $this->aiRegistry);

        return $instance instanceof VectorRetrievalProviderInterface ? $instance : null;
    }
}
