<?php

namespace App\Services\Ai;

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

    public function defaultKey(): string
    {
        return (string) config('ai.default', 'mock');
    }

    public function has(string $key): bool
    {
        $providers = (array) config('ai.providers', []);

        return isset($providers[$key]) && ! empty($providers[$key]['class']);
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
