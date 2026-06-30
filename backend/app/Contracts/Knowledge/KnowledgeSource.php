<?php

declare(strict_types=1);

namespace App\Contracts\Knowledge;

use App\Models\Project;

/**
 * Sprint 24 — contract for a Knowledge Source provider.
 *
 * Every knowledge provider (Internal KB, Markdown, PDF, Git, Confluence, SharePoint, Google Drive,
 * OneDrive, GitHub/GitLab Wiki, MediaWiki, Elastic/OpenSearch, Vector Store, …) implements this and
 * registers with the {@see \App\Services\Knowledge\KnowledgeSourceRegistry}. Enterprise Search and the
 * Knowledge Assistant consume sources ONLY through this contract — there is no provider-specific
 * branching. A source that is not configured reports `isOperational() === false` and returns no
 * results; it never fabricates content.
 */
interface KnowledgeSource
{
    /** Stable registry key, e.g. 'internal', 'confluence', 'vector_store'. */
    public function key(): string;

    public function name(): string;

    /** Provider family, e.g. 'internal', 'files', 'repository', 'collaboration', 'semantic'. */
    public function category(): string;

    /** True only when the provider is implemented AND configured for real use in this workspace. */
    public function isOperational(Project $project): bool;

    /**
     * Search the source for documents matching $query. MUST return real indexed rows only (empty when
     * not operational). Each result is an associative array shaped like a search hit.
     *
     * @param  array<string, mixed>  $options  limit, category, …
     * @return list<array<string, mixed>>
     */
    public function search(Project $project, string $query, array $options = []): array;

    /**
     * Total number of indexed documents available from this source for the workspace.
     */
    public function count(Project $project): int;
}
