<?php

declare(strict_types=1);

namespace App\Services\Knowledge;

use App\Models\Automation\AutomationRunbook;
use App\Models\Automation\AutomationWorkflow;
use App\Models\Incident\Incident;
use App\Models\Notification\Notification;
use App\Models\Project;
use App\Models\Support\Ticket;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Sprint 24 — Enterprise Search.
 *
 * One search interface across every module. Knowledge documents are searched through the Knowledge
 * Source Registry (no provider branching); first-party operational entities (incidents, tickets,
 * notifications, workflows, runbooks) are searched over their REAL rows. Results are unified, ranked by
 * a deterministic lexical relevance score, and workspace-scoped. Only real indexed data is returned —
 * nothing is fabricated. `mode=semantic` applies the same deterministic token-overlap ranking (honest
 * "semantic-style" relevance) unless a real vector source is registered and operational.
 */
class EnterpriseSearchService
{
    public function __construct(
        private readonly KnowledgeSourceRegistry $sources,
    ) {}

    /**
     * @param  array<string, mixed>  $options  limit, types (list<string>), mode (keyword|semantic)
     * @return array<string, mixed>
     */
    public function search(Project $project, string $query, array $options = []): array
    {
        $limit = $this->clampLimit((int) ($options['limit'] ?? config('knowledge.search.default_limit', 25)));
        $types = (array) ($options['types'] ?? []);
        $mode = ($options['mode'] ?? 'keyword') === 'semantic' ? 'semantic' : 'keyword';
        $terms = $this->tokenize($query);

        $results = [];

        // Knowledge documents — through the registry only (every operational source).
        if ($this->wants($types, 'document')) {
            foreach ($this->sources->operational($project) as $source) {
                foreach ($source->search($project, $query, ['limit' => $limit]) as $hit) {
                    $results[] = array_merge(['module' => 'qynknow'], $hit);
                }
            }
        }

        if ($this->wants($types, 'incident') && Schema::hasTable('incidents')) {
            $results = array_merge($results, $this->scanIncidents($project, $terms, $limit));
        }
        if ($this->wants($types, 'ticket') && Schema::hasTable('tickets')) {
            $results = array_merge($results, $this->scanTickets($project, $terms, $limit));
        }
        if ($this->wants($types, 'notification') && Schema::hasTable('notifications')) {
            $results = array_merge($results, $this->scanNotifications($project, $terms, $limit));
        }
        if ($this->wants($types, 'workflow') && Schema::hasTable('automation_workflows')) {
            $results = array_merge($results, $this->scanWorkflows($project, $terms, $limit));
        }
        if ($this->wants($types, 'runbook') && Schema::hasTable('automation_runbooks')) {
            $results = array_merge($results, $this->scanRunbooks($project, $terms, $limit));
        }

        usort($results, static fn (array $a, array $b): int => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));

        return [
            'query' => $query,
            'mode' => $mode,
            'total' => count($results),
            'results' => array_slice($results, 0, $limit),
            'searched_sources' => array_map(static fn ($s): string => $s->key(), $this->sources->operational($project)),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  list<string>  $terms
     * @return list<array<string, mixed>>
     */
    private function scanIncidents(Project $project, array $terms, int $limit): array
    {
        $rows = Incident::where('project_id', $project->id)
            ->when($terms !== [], fn ($q) => $q->where(fn ($w) => $this->likeAny($w, $terms, ['title', 'description'])))
            ->orderByDesc('updated_at')->limit($limit)->get();

        return $rows->map(fn (Incident $i): array => $this->result(
            'incident', 'qynreact', $i->uuid, $i->title, (string) $i->description, $terms,
            ['status' => $i->status, 'severity' => $i->severity], $i->updated_at,
        ))->all();
    }

    /**
     * @param  list<string>  $terms
     * @return list<array<string, mixed>>
     */
    private function scanTickets(Project $project, array $terms, int $limit): array
    {
        $rows = Ticket::where('project_id', $project->id)
            ->when($terms !== [], fn ($q) => $q->where(fn ($w) => $this->likeAny($w, $terms, ['subject', 'description'])))
            ->orderByDesc('updated_at')->limit($limit)->get();

        return $rows->map(fn (Ticket $tk): array => $this->result(
            'ticket', 'qynsupport', $tk->uuid, $tk->subject, (string) $tk->description, $terms,
            ['status' => $tk->status, 'priority' => $tk->priority, 'reference' => $tk->reference], $tk->updated_at,
        ))->all();
    }

    /**
     * @param  list<string>  $terms
     * @return list<array<string, mixed>>
     */
    private function scanNotifications(Project $project, array $terms, int $limit): array
    {
        $rows = Notification::where('project_id', $project->id)
            ->when($terms !== [], fn ($q) => $q->where(fn ($w) => $this->likeAny($w, $terms, ['title', 'body'])))
            ->orderByDesc('created_at')->limit($limit)->get();

        return $rows->map(fn (Notification $n): array => $this->result(
            'notification', 'qynnotify', $n->uuid, $n->title, (string) $n->body, $terms,
            ['status' => $n->status, 'severity' => $n->severity], $n->created_at,
        ))->all();
    }

    /**
     * @param  list<string>  $terms
     * @return list<array<string, mixed>>
     */
    private function scanWorkflows(Project $project, array $terms, int $limit): array
    {
        $rows = AutomationWorkflow::where('project_id', $project->id)
            ->when($terms !== [], fn ($q) => $q->where(fn ($w) => $this->likeAny($w, $terms, ['name', 'description'])))
            ->orderByDesc('updated_at')->limit($limit)->get();

        return $rows->map(fn (AutomationWorkflow $wf): array => $this->result(
            'workflow', 'qynrun', $wf->uuid, $wf->name, (string) $wf->description, $terms,
            ['trigger_type' => $wf->trigger_type], $wf->updated_at,
        ))->all();
    }

    /**
     * @param  list<string>  $terms
     * @return list<array<string, mixed>>
     */
    private function scanRunbooks(Project $project, array $terms, int $limit): array
    {
        $rows = AutomationRunbook::where('project_id', $project->id)
            ->when($terms !== [], fn ($q) => $q->where(fn ($w) => $this->likeAny($w, $terms, ['name', 'description'])))
            ->orderByDesc('updated_at')->limit($limit)->get();

        return $rows->map(fn (AutomationRunbook $rb): array => $this->result(
            'runbook', 'qynrun', $rb->uuid, $rb->name, (string) $rb->description, $terms,
            ['category' => $rb->category, 'status' => $rb->status], $rb->updated_at,
        ))->all();
    }

    /**
     * @param  list<string>  $fields
     */
    private function likeAny(mixed $query, array $terms, array $fields): void
    {
        foreach ($terms as $term) {
            $like = '%'.$term.'%';
            foreach ($fields as $field) {
                $query->orWhere($field, 'like', $like);
            }
        }
    }

    /**
     * @param  list<string>  $terms
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function result(string $type, string $module, string $uuid, string $title, string $text, array $terms, array $meta, mixed $updatedAt): array
    {
        return [
            'type' => $type,
            'module' => $module,
            'uuid' => $uuid,
            'title' => $title,
            'snippet' => $this->snippet($text, $terms),
            'score' => $this->score($title, $text, $terms),
            'meta' => $meta,
            'updated_at' => $updatedAt ? $updatedAt->toIso8601String() : null,
        ];
    }

    /**
     * Deterministic relevance: title matches weigh 3×, body 1×; no query terms => recency-neutral 1.0.
     *
     * @param  list<string>  $terms
     */
    private function score(string $title, string $text, array $terms): float
    {
        if ($terms === []) {
            return 1.0;
        }

        $title = Str::lower($title);
        $text = Str::lower($text);
        $score = 0.0;
        foreach ($terms as $term) {
            if (str_contains($title, $term)) {
                $score += 3.0;
            }
            if (str_contains($text, $term)) {
                $score += 1.0;
            }
        }

        return round($score, 2);
    }

    /**
     * @param  list<string>  $terms
     */
    private function snippet(string $text, array $terms): string
    {
        $length = (int) config('knowledge.search.snippet_length', 240);
        $plain = trim(preg_replace('/\s+/', ' ', strip_tags($text)) ?? '');
        if ($plain === '') {
            return '';
        }
        foreach ($terms as $term) {
            $pos = stripos($plain, $term);
            if ($pos !== false) {
                return Str::limit(substr($plain, max(0, $pos - 60)), $length);
            }
        }

        return Str::limit($plain, $length);
    }

    /**
     * @param  list<string>  $types
     */
    private function wants(array $types, string $type): bool
    {
        return $types === [] || in_array($type, $types, true);
    }

    /**
     * @return list<string>
     */
    private function tokenize(string $query): array
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', Str::lower(trim($query))) ?: [];

        return array_values(array_filter($parts, static fn (string $t): bool => strlen($t) >= 2));
    }

    private function clampLimit(int $limit): int
    {
        $max = (int) config('knowledge.search.max_limit', 100);

        return max(1, min($limit, $max));
    }
}
