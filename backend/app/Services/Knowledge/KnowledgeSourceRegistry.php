<?php

declare(strict_types=1);

namespace App\Services\Knowledge;

use App\Contracts\Knowledge\KnowledgeSource;
use App\Models\Project;
use RuntimeException;

/**
 * Sprint 24 — the Knowledge Source Registry.
 *
 * A process-wide, dynamic registry of {@see KnowledgeSource} providers, mirroring the AI Adapter
 * Registry and Automation Registry patterns. Enterprise Search and the Knowledge Assistant resolve
 * providers ONLY through this registry — there is no provider-specific branching anywhere. Future
 * connectors plug in with one registration line.
 */
class KnowledgeSourceRegistry
{
    /** @var array<string, KnowledgeSource> */
    private array $sources = [];

    public function register(KnowledgeSource $source): void
    {
        $this->sources[$source->key()] = $source;
    }

    public function has(string $key): bool
    {
        return isset($this->sources[$key]);
    }

    public function get(string $key): KnowledgeSource
    {
        if (! $this->has($key)) {
            throw new RuntimeException("Knowledge source [{$key}] is not registered.");
        }

        return $this->sources[$key];
    }

    /**
     * @return array<string, KnowledgeSource>
     */
    public function all(): array
    {
        return $this->sources;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->sources);
    }

    /**
     * Operational providers for a workspace (the ones that can actually return real results).
     *
     * @return list<KnowledgeSource>
     */
    public function operational(Project $project): array
    {
        return array_values(array_filter($this->sources, static fn (KnowledgeSource $s): bool => $s->isOperational($project)));
    }

    /**
     * Describe every registered source for the discovery API (UI catalog).
     *
     * @return list<array<string, mixed>>
     */
    public function describe(Project $project): array
    {
        $out = [];
        foreach ($this->sources as $source) {
            $operational = $source->isOperational($project);
            $out[] = [
                'key' => $source->key(),
                'name' => $source->name(),
                'category' => $source->category(),
                'operational' => $operational,
                'document_count' => $operational ? $source->count($project) : 0,
            ];
        }

        return $out;
    }
}
