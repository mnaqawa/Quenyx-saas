<?php

declare(strict_types=1);

namespace App\Services\Knowledge;

use App\Models\Knowledge\KnowledgeDocument;
use App\Models\Project;
use App\Models\User;
use App\Services\Platform\EventBus\PlatformEventNames;
use App\Services\Platform\EventBus\PublishesPlatformEvents;
use App\Services\Platform\PlatformAuditLogger;
use Illuminate\Support\Str;

/**
 * Sprint 24 — Knowledge Base domain service: CRUD over Internal Knowledge Base documents. All writes
 * are workspace-scoped, UUID-addressed, and audited. AI-assisted drafts are saved as editable `draft`
 * documents — they are never auto-published.
 */
class KnowledgeService
{
    use PublishesPlatformEvents;

    public function __construct(
        private readonly PlatformAuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function list(Project $project, array $filters = []): array
    {
        return KnowledgeDocument::where('project_id', $project->id)
            ->when(! empty($filters['category']), fn ($q) => $q->where('category', $filters['category']))
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->orderByDesc('updated_at')
            ->limit((int) ($filters['limit'] ?? 100))
            ->get()
            ->map(fn (KnowledgeDocument $d): array => $this->summary($d))
            ->all();
    }

    public function find(Project $project, string $uuid): ?KnowledgeDocument
    {
        return KnowledgeDocument::where('project_id', $project->id)->where('uuid', $uuid)->first();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Project $project, ?User $user, array $data): KnowledgeDocument
    {
        $doc = KnowledgeDocument::create([
            'project_id' => $project->id,
            'author_id' => $user?->id,
            'source_key' => $data['source_key'] ?? config('knowledge.internal_source_key', 'internal'),
            'title' => $data['title'],
            'slug' => Str::slug((string) $data['title']),
            'format' => $data['format'] ?? 'markdown',
            'category' => $data['category'] ?? null,
            'status' => $data['status'] ?? 'published',
            'body' => $data['body'] ?? null,
            'tags' => $data['tags'] ?? [],
            'metadata' => $data['metadata'] ?? [],
        ]);

        $this->audit->log($user, $project, 'knowledge_document_created', ['uuid' => $doc->uuid, 'title' => $doc->title]);

        $this->publishPlatformEvent(PlatformEventNames::KNOWLEDGE_CREATED, $project, $user, [
            'document_uuid' => $doc->uuid,
            'title' => $doc->title,
            'category' => $doc->category,
            'status' => $doc->status,
        ]);

        return $doc;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Project $project, ?User $user, KnowledgeDocument $doc, array $data): KnowledgeDocument
    {
        $doc->fill(array_filter([
            'title' => $data['title'] ?? null,
            'category' => $data['category'] ?? null,
            'status' => $data['status'] ?? null,
            'body' => $data['body'] ?? null,
            'format' => $data['format'] ?? null,
        ], static fn ($v) => $v !== null));

        if (array_key_exists('tags', $data)) {
            $doc->tags = (array) $data['tags'];
        }
        if (isset($data['title'])) {
            $doc->slug = Str::slug((string) $data['title']);
        }
        $doc->indexed_at = now();
        $doc->save();

        $this->audit->log($user, $project, 'knowledge_document_updated', ['uuid' => $doc->uuid]);

        $this->publishPlatformEvent(PlatformEventNames::KNOWLEDGE_UPDATED, $project, $user, [
            'document_uuid' => $doc->uuid,
            'status' => $doc->status,
        ]);

        return $doc;
    }

    public function delete(Project $project, ?User $user, KnowledgeDocument $doc): void
    {
        $uuid = $doc->uuid;
        $doc->delete();
        $this->audit->log($user, $project, 'knowledge_document_deleted', ['uuid' => $uuid]);
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(KnowledgeDocument $doc): array
    {
        return [
            'uuid' => $doc->uuid,
            'title' => $doc->title,
            'category' => $doc->category,
            'status' => $doc->status,
            'format' => $doc->format,
            'source_key' => $doc->source_key,
            'tags' => (array) ($doc->tags ?? []),
            'updated_at' => optional($doc->updated_at)->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(KnowledgeDocument $doc): array
    {
        return array_merge($this->summary($doc), [
            'body' => $doc->body,
            'external_ref' => $doc->external_ref,
            'metadata' => (array) ($doc->metadata ?? []),
            'indexed_at' => optional($doc->indexed_at)->toIso8601String(),
        ]);
    }
}
