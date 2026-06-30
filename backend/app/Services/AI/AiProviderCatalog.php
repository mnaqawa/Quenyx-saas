<?php

namespace App\Services\AI;

/**
 * v1.0.0 — declarative catalog of AI providers the platform is AWARE of.
 *
 * This is metadata ONLY. A provider appearing here does NOT mean it is configured or executable:
 *
 *   - `executable` (whether a real adapter class exists and can make live calls) is decided by
 *     {@see AiProviderRegistry::has()} — NOT by this catalog. Today only `openai` (and the dev-only
 *     `mock`) have adapters; every other entry is "catalog/configurable but not executable yet".
 *   - `platform_configured` (whether real credentials are present) is decided by
 *     {@see AiProviderRegistry::isConfigured()} from config/env — never fabricated here.
 *
 * The catalog drives the enterprise provider UI (name, type, capabilities, endpoint) without
 * pretending any connectivity. The `mock` provider is dev-only and is hidden outside local/testing.
 */
class AiProviderCatalog
{
    /**
     * Provider type buckets (display only).
     */
    public const TYPE_HOSTED = 'hosted';

    public const TYPE_GATEWAY = 'gateway';

    public const TYPE_SELF_HOSTED = 'self_hosted';

    public const TYPE_CUSTOM = 'custom';

    public const TYPE_DEV = 'dev';

    /**
     * Catalog definitions, in display order. `capabilities` are DECLARED (documented) capabilities,
     * used only for the UI; live capability negotiation still flows through the provider adapters.
     *
     * @return array<string, array{label:string,type:string,capabilities:list<string>,endpoint:?string,docs_url:?string,dev_only?:bool}>
     */
    public function definitions(): array
    {
        return [
            'openai' => [
                'label' => 'OpenAI',
                'type' => self::TYPE_HOSTED,
                'capabilities' => ['chat', 'stream', 'embeddings', 'responses', 'structured_json', 'citations'],
                'endpoint' => 'https://api.openai.com/v1',
                'docs_url' => 'https://platform.openai.com/docs',
            ],
            'anthropic' => [
                'label' => 'Anthropic Claude',
                'type' => self::TYPE_HOSTED,
                'capabilities' => ['chat', 'stream', 'structured_json'],
                'endpoint' => 'https://api.anthropic.com',
                'docs_url' => 'https://docs.anthropic.com',
            ],
            'gemini' => [
                'label' => 'Google Gemini',
                'type' => self::TYPE_HOSTED,
                'capabilities' => ['chat', 'stream', 'embeddings'],
                'endpoint' => 'https://generativelanguage.googleapis.com',
                'docs_url' => 'https://ai.google.dev/docs',
            ],
            'azure-openai' => [
                'label' => 'Azure OpenAI',
                'type' => self::TYPE_HOSTED,
                'capabilities' => ['chat', 'stream', 'embeddings', 'responses', 'structured_json'],
                'endpoint' => null,
                'docs_url' => 'https://learn.microsoft.com/azure/ai-services/openai/',
            ],
            'openrouter' => [
                'label' => 'OpenRouter',
                'type' => self::TYPE_GATEWAY,
                'capabilities' => ['chat', 'stream'],
                'endpoint' => 'https://openrouter.ai/api/v1',
                'docs_url' => 'https://openrouter.ai/docs',
            ],
            'mistral' => [
                'label' => 'Mistral AI',
                'type' => self::TYPE_HOSTED,
                'capabilities' => ['chat', 'stream', 'embeddings'],
                'endpoint' => 'https://api.mistral.ai/v1',
                'docs_url' => 'https://docs.mistral.ai',
            ],
            'cohere' => [
                'label' => 'Cohere',
                'type' => self::TYPE_HOSTED,
                'capabilities' => ['chat', 'embeddings'],
                'endpoint' => 'https://api.cohere.ai',
                'docs_url' => 'https://docs.cohere.com',
            ],
            'xai' => [
                'label' => 'xAI Grok',
                'type' => self::TYPE_HOSTED,
                'capabilities' => ['chat', 'stream'],
                'endpoint' => 'https://api.x.ai/v1',
                'docs_url' => 'https://docs.x.ai',
            ],
            'ollama' => [
                'label' => 'Ollama',
                'type' => self::TYPE_SELF_HOSTED,
                'capabilities' => ['chat', 'stream', 'embeddings'],
                'endpoint' => 'http://localhost:11434',
                'docs_url' => 'https://github.com/ollama/ollama/blob/main/docs/api.md',
            ],
            'lmstudio' => [
                'label' => 'LM Studio',
                'type' => self::TYPE_SELF_HOSTED,
                'capabilities' => ['chat', 'stream'],
                'endpoint' => 'http://localhost:1234/v1',
                'docs_url' => 'https://lmstudio.ai/docs/local-server',
            ],
            'vllm' => [
                'label' => 'vLLM',
                'type' => self::TYPE_SELF_HOSTED,
                'capabilities' => ['chat', 'stream', 'embeddings'],
                'endpoint' => null,
                'docs_url' => 'https://docs.vllm.ai',
            ],
            'litellm' => [
                'label' => 'LiteLLM Gateway',
                'type' => self::TYPE_GATEWAY,
                'capabilities' => ['chat', 'stream', 'embeddings'],
                'endpoint' => null,
                'docs_url' => 'https://docs.litellm.ai',
            ],
            'huggingface' => [
                'label' => 'Hugging Face Inference',
                'type' => self::TYPE_HOSTED,
                'capabilities' => ['chat', 'embeddings'],
                'endpoint' => 'https://api-inference.huggingface.co',
                'docs_url' => 'https://huggingface.co/docs/api-inference',
            ],
            'custom-openai' => [
                'label' => 'Custom OpenAI-Compatible API',
                'type' => self::TYPE_CUSTOM,
                'capabilities' => ['chat', 'stream'],
                'endpoint' => null,
                'docs_url' => null,
            ],

            // Dev-only deterministic provider. Hidden from the production UI / default provider.
            'mock' => [
                'label' => 'Mock (development)',
                'type' => self::TYPE_DEV,
                'capabilities' => ['chat', 'stream', 'embeddings', 'responses'],
                'endpoint' => null,
                'docs_url' => null,
                'dev_only' => true,
            ],
        ];
    }

    /**
     * All catalog provider keys (including dev-only entries).
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->definitions());
    }

    /**
     * Keys visible in the current environment. Dev-only providers (mock) are excluded outside
     * local/testing so they can NEVER appear in a production UI or as a production default.
     *
     * @return list<string>
     */
    public function visibleKeys(): array
    {
        $devVisible = app()->environment(['local', 'testing']);

        return array_values(array_filter(
            $this->keys(),
            fn (string $key): bool => $devVisible || empty($this->definitions()[$key]['dev_only'])
        ));
    }

    public function isDevOnly(string $key): bool
    {
        return ! empty($this->definitions()[$key]['dev_only']);
    }

    /**
     * Metadata for a single provider, or null if unknown.
     *
     * @return array{label:string,type:string,capabilities:list<string>,endpoint:?string,docs_url:?string,dev_only?:bool}|null
     */
    public function meta(string $key): ?array
    {
        return $this->definitions()[$key] ?? null;
    }
}
