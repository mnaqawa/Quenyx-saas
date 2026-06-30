<?php

declare(strict_types=1);

namespace App\Services\Knowledge\Sources;

use App\Models\Project;

/**
 * Sprint 24 — a registered-but-not-yet-connected Knowledge Source (Confluence, SharePoint, Google
 * Drive, OneDrive, Git/GitHub/GitLab wiki, MediaWiki, Elastic/OpenSearch, Vector Store, …).
 *
 * It exists in the registry so the provider catalog is complete and a real connector plugs in by
 * swapping this instance for an operational implementation — with no change to Enterprise Search or the
 * Assistant. Until then it is honest: not operational, zero documents, no results. It NEVER simulates
 * content or fabricates search hits.
 */
class PlannedKnowledgeSource extends AbstractKnowledgeSource
{
    public function __construct(
        private readonly string $key,
        private readonly string $name,
        private readonly string $category = 'general',
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function category(): string
    {
        return $this->category;
    }

    public function isOperational(Project $project): bool
    {
        return false;
    }
}
