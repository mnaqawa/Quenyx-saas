<?php

namespace App\Services\AI;

use App\Contracts\Ai\AiProviderInterface;
use App\Exceptions\Ai\AiProviderException;

/**
 * Resolves AI providers from config. This is the ONLY place that turns a provider key into a
 * concrete provider instance — no other layer references a provider class directly, which is
 * what makes providers swappable (default provider, discovery, switching) and future providers
 * (Azure, Claude, Gemini, Ollama, Local) drop-in. Contains NO business logic.
 */
class AiProviderRegistry
{
    /** @var array<string, AiProviderInterface> */
    private array $resolved = [];

    /**
     * Provider keys declared in config (implemented or future).
     *
     * @return list<string>
     */
    public function available(): array
    {
        return array_keys((array) config('ai.providers', []));
    }

    /**
     * Resolve the platform default provider key.
     *
     * v1.0.0 — the mock provider must NEVER be the production default. Resolution order:
     *   1. An explicit AI_PROVIDER (config('ai.default')) always wins.
     *   2. Otherwise prefer a real, configured provider (currently OpenAI when its key is set).
     *   3. Otherwise fall back to `mock` ONLY in local/testing.
     *   4. Otherwise return '' — the honest "no provider configured" state.
     */
    public function defaultKey(): string
    {
        $explicit = config('ai.default');
        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }

        if ($this->isConfigured('openai')) {
            return 'openai';
        }

        if (app()->environment(['local', 'testing'])) {
            return 'mock';
        }

        return '';
    }

    /**
     * Whether a real default provider is resolvable (i.e. not the empty "none configured" state).
     */
    public function hasDefault(): bool
    {
        return $this->defaultKey() !== '';
    }

    public function has(string $key): bool
    {
        $providers = (array) config('ai.providers', []);

        return isset($providers[$key]) && ! empty($providers[$key]['class']);
    }

    /**
     * Whether the provider has real platform-level credentials configured (from config/env).
     * Used to decide the default provider and to surface an honest configured/unconfigured state.
     * Never throws and never inspects per-workspace secrets.
     */
    public function isConfigured(string $key): bool
    {
        $providers = (array) config('ai.providers', []);
        $config = (array) ($providers[$key] ?? []);

        return match ($key) {
            'openai' => ! empty($config['api_key']),
            'mock' => app()->environment(['local', 'testing']),
            default => ! empty($config['api_key']) || ! empty($config['base_url']),
        };
    }

    public function default(): AiProviderInterface
    {
        return $this->get($this->defaultKey());
    }

    /**
     * Resolve a provider by key (or the default when null). Throws for unknown or
     * not-yet-implemented providers.
     */
    public function get(?string $key = null): AiProviderInterface
    {
        $key ??= $this->defaultKey();

        if (isset($this->resolved[$key])) {
            return $this->resolved[$key];
        }

        $providers = (array) config('ai.providers', []);
        if (! isset($providers[$key])) {
            throw new AiProviderException("Unknown AI provider: {$key}.", 'ai_provider_unknown');
        }

        $config = (array) $providers[$key];
        $class = $config['class'] ?? null;

        if ($class === null || ! class_exists($class)) {
            throw new AiProviderException(
                "AI provider '{$key}' is declared but not implemented yet.",
                'ai_provider_not_implemented',
            );
        }

        $instance = new $class($config);

        if (! $instance instanceof AiProviderInterface) {
            throw new AiProviderException("Provider '{$key}' does not implement AiProviderInterface.", 'ai_provider_invalid');
        }

        return $this->resolved[$key] = $instance;
    }
}
