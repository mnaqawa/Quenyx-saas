<?php

declare(strict_types=1);

namespace App\Services\Knowledge\Sources;

use App\Contracts\Knowledge\KnowledgeSource;
use App\Models\Project;

/**
 * Sprint 24 — base class for Knowledge Source providers. Safe defaults: not operational, no results,
 * zero count. A concrete provider overrides only what it truly supports — keeping the contract
 * extensible and honest (no fabricated content).
 */
abstract class AbstractKnowledgeSource implements KnowledgeSource
{
    public function category(): string
    {
        return 'general';
    }

    public function isOperational(Project $project): bool
    {
        return false;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return list<array<string, mixed>>
     */
    public function search(Project $project, string $query, array $options = []): array
    {
        return [];
    }

    public function count(Project $project): int
    {
        return 0;
    }
}
