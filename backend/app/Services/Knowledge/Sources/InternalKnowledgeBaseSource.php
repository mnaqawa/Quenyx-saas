<?php

declare(strict_types=1);

namespace App\Services\Knowledge\Sources;

use App\Models\Knowledge\KnowledgeDocument;
use App\Models\Project;
use Illuminate\Support\Str;

/**
 * Sprint 24 — the Internal Knowledge Base source. Backed by the `knowledge_documents` table, so it is
 * ALWAYS operational. Search is deterministic lexical relevance (title + tag + body token overlap) over
 * real rows — no embeddings, no fabrication. Returns only documents that actually exist in the workspace.
 */
class InternalKnowledgeBaseSource extends AbstractKnowledgeSource
{
    public function key(): string
    {
        return 'internal';
    }

    public function name(): string
    {
        return 'Internal Knowledge Base';
    }

    public function category(): string
    {
        return 'internal';
    }

    public function isOperational(Project $project): bool
    {
        return true;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return list<array<string, mixed>>
     */
    public function search(Project $project, string $query, array $options = []): array
    {
        $limit = (int) ($options['limit'] ?? 25);
        $terms = $this->tokenize($query);

        $builder = KnowledgeDocument::query()
            ->where('project_id', $project->id)
            ->where('status', '!=', 'archived');

        if (! empty($options['category'])) {
            $builder->where('category', (string) $options['category']);
        }

        // Narrow with a coarse LIKE filter when we have terms; otherwise return recent docs.
        if ($terms !== []) {
            $builder->where(function ($q) use ($terms): void {
                foreach ($terms as $term) {
                    $like = '%'.$term.'%';
                    $q->orWhere('title', 'like', $like)->orWhere('body', 'like', $like);
                }
            });
        }

        $docs = $builder->orderByDesc('updated_at')->limit(200)->get();

        $hits = [];
        foreach ($docs as $doc) {
            $score = $this->score($doc, $terms);
            if ($terms !== [] && $score <= 0) {
                continue;
            }
            $hits[] = $this->hit($doc, $score, $terms);
        }

        usort($hits, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_slice($hits, 0, max(1, $limit));
    }

    public function count(Project $project): int
    {
        return KnowledgeDocument::where('project_id', $project->id)->count();
    }

    /**
     * Deterministic token-overlap relevance. Title matches weigh more than body matches; tags add a
     * small boost. No randomness — identical inputs always score identically.
     *
     * @param  list<string>  $terms
     */
    private function score(KnowledgeDocument $doc, array $terms): float
    {
        if ($terms === []) {
            return 1.0;
        }

        $title = Str::lower((string) $doc->title);
        $body = Str::lower((string) ($doc->body ?? ''));
        $tags = Str::lower(implode(' ', (array) ($doc->tags ?? [])));

        $score = 0.0;
        foreach ($terms as $term) {
            if (str_contains($title, $term)) {
                $score += 3.0;
            }
            if (str_contains($tags, $term)) {
                $score += 1.5;
            }
            if (str_contains($body, $term)) {
                $score += 1.0;
            }
        }

        return $score;
    }

    /**
     * @param  list<string>  $terms
     * @return array<string, mixed>
     */
    private function hit(KnowledgeDocument $doc, float $score, array $terms): array
    {
        return [
            'type' => 'document',
            'source_key' => $this->key(),
            'uuid' => $doc->uuid,
            'title' => $doc->title,
            'category' => $doc->category,
            'status' => $doc->status,
            'snippet' => $this->snippet((string) ($doc->body ?? ''), $terms),
            'tags' => (array) ($doc->tags ?? []),
            'score' => round($score, 2),
            'updated_at' => optional($doc->updated_at)->toIso8601String(),
        ];
    }

    /**
     * @param  list<string>  $terms
     */
    private function snippet(string $body, array $terms): string
    {
        $length = (int) config('knowledge.search.snippet_length', 240);
        $plain = trim(preg_replace('/\s+/', ' ', strip_tags($body)) ?? '');
        if ($plain === '') {
            return '';
        }

        foreach ($terms as $term) {
            $pos = stripos($plain, $term);
            if ($pos !== false) {
                $start = max(0, $pos - 60);

                return Str::limit(substr($plain, $start), $length);
            }
        }

        return Str::limit($plain, $length);
    }

    /**
     * @return list<string>
     */
    private function tokenize(string $query): array
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', Str::lower(trim($query))) ?: [];

        return array_values(array_filter($parts, static fn (string $t): bool => strlen($t) >= 2));
    }
}
