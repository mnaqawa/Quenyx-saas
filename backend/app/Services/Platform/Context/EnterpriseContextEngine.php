<?php

declare(strict_types=1);

namespace App\Services\Platform\Context;

use App\Models\Project;
use App\Models\User;
use App\Services\Ai\Workspace\AiWorkspaceContextResolver;
use App\Services\Incident\CrossModuleOrchestrator;
use App\Services\Knowledge\EnterpriseSearchService;
use App\Services\Knowledge\GlobalTimelineService;
use App\Services\Knowledge\KnowledgeGraphService;

/**
 * Sprint 25 — the shared Enterprise Context Engine.
 *
 * Builds ONE normalized AI context object from every platform source: workspace, user + permissions,
 * cross-module context (monitoring, assets, automation, knowledge, incidents, notifications, compliance —
 * via the AI Adapter Registry, no module branching), enterprise search, global timeline, and the
 * knowledge graph. Every AI adapter / operator consumes the same object, so there is exactly one place
 * that assembles enterprise context — no duplicated context building anywhere.
 *
 * It is a pure READ-MODEL: it reuses existing deterministic services and writes nothing. Sections are
 * bounded for predictable size and may be selectively included via options.
 */
class EnterpriseContextEngine
{
    public function __construct(
        private readonly CrossModuleOrchestrator $crossModule,
        private readonly EnterpriseSearchService $search,
        private readonly GlobalTimelineService $timeline,
        private readonly KnowledgeGraphService $graph,
        private readonly AiWorkspaceContextResolver $workspace,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     *   - query: ?string         include enterprise search results for this query
     *   - exclude: list<string>   module keys to exclude from cross-module gather
     *   - include: list<string>   sections to include (defaults to all)
     *   - timeline_limit: int
     * @return array<string, mixed>
     */
    public function build(Project $project, ?User $user = null, array $options = []): array
    {
        $include = (array) ($options['include'] ?? ['workspace', 'user', 'cross_module', 'timeline', 'graph', 'search']);
        $exclude = (array) ($options['exclude'] ?? []);
        $query = isset($options['query']) ? trim((string) $options['query']) : '';

        $context = [
            'context_version' => 'v1',
            'generated_at' => now()->toIso8601String(),
        ];

        if ($this->wants($include, 'workspace')) {
            $context['workspace'] = [
                'uuid' => (string) $project->uuid,
                'name' => $project->name,
            ];
        }

        if ($this->wants($include, 'user')) {
            $context['user'] = [
                'uuid' => $user?->uuid,
                'name' => $user?->name,
            ];
            $context['permissions'] = $user !== null
                ? $this->workspace->effectivePermissions($project, $user)
                : ['can_use_ai' => false];
        }

        if ($this->wants($include, 'cross_module')) {
            $context['cross_module'] = $this->crossModule->gather($project, $exclude);
        }

        if ($this->wants($include, 'timeline')) {
            $context['timeline'] = $this->timeline->build($project, [
                'limit' => (int) ($options['timeline_limit'] ?? 25),
            ]);
        }

        if ($this->wants($include, 'graph')) {
            $context['graph'] = $this->graph->build($project);
        }

        if ($this->wants($include, 'search') && $query !== '') {
            $context['search'] = $this->search->search($project, $query, ['limit' => 8]);
        }

        $context['summary'] = $this->summarize($context);

        return $context;
    }

    /**
     * A compact, deterministic digest the AI can lead with (counts only — no fabrication).
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function summarize(array $context): array
    {
        return [
            'modules_available' => (int) ($context['cross_module']['module_count'] ?? 0),
            'timeline_events' => count((array) ($context['timeline']['events'] ?? [])),
            'graph_nodes' => (int) ($context['graph']['node_count'] ?? count((array) ($context['graph']['nodes'] ?? []))),
            'graph_edges' => (int) ($context['graph']['edge_count'] ?? count((array) ($context['graph']['edges'] ?? []))),
            'search_hits' => count((array) ($context['search']['results'] ?? [])),
        ];
    }

    /**
     * @param  list<string>  $include
     */
    private function wants(array $include, string $section): bool
    {
        return $include === [] || in_array($section, $include, true);
    }
}
