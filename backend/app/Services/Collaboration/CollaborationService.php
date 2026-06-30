<?php

declare(strict_types=1);

namespace App\Services\Collaboration;

use App\Models\Collaboration\Comment;
use App\Models\Collaboration\Participant;
use App\Models\Project;
use App\Models\User;
use App\Services\Platform\PlatformAuditLogger;

/**
 * Sprint 24 — the reusable Collaboration platform service.
 *
 * Provides comments, mentions, assignments, watchers, and task ownership on ANY entity, addressed
 * polymorphically by (entity_type, entity_uuid). Every module (incidents, tickets, documents,
 * executions, …) reuses this — there is no per-module collaboration implementation. Workspace-scoped,
 * UUID-addressed, audited.
 */
class CollaborationService
{
    /** Entity types collaboration may be attached to (validation allowlist). */
    public const ENTITY_TYPES = ['incident', 'ticket', 'document', 'execution', 'asset', 'alert', 'workflow', 'runbook', 'notification'];

    public const ROLES = ['watcher', 'assignee', 'owner'];

    public function __construct(
        private readonly PlatformAuditLogger $audit,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function thread(Project $project, string $entityType, string $entityUuid): array
    {
        return [
            'entity_type' => $entityType,
            'entity_uuid' => $entityUuid,
            'comments' => $this->comments($project, $entityType, $entityUuid),
            'participants' => $this->participants($project, $entityType, $entityUuid),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  list<string>  $mentions  user uuids
     */
    public function comment(Project $project, ?User $user, string $entityType, string $entityUuid, string $body, array $mentions = []): Comment
    {
        $comment = Comment::create([
            'project_id' => $project->id,
            'author_id' => $user?->id,
            'entity_type' => $entityType,
            'entity_uuid' => $entityUuid,
            'body' => $body,
            'mentions' => array_values(array_unique($mentions)),
        ]);

        // Mentioned users automatically become watchers (reusable, deterministic).
        foreach ($mentions as $mentionUuid) {
            $mentioned = User::where('uuid', $mentionUuid)->first();
            if ($mentioned !== null) {
                $this->addParticipant($project, $mentioned, $entityType, $entityUuid, 'watcher');
            }
        }

        $this->audit->log($user, $project, 'collaboration_comment_added', [
            'entity_type' => $entityType, 'entity_uuid' => $entityUuid, 'comment_uuid' => $comment->uuid, 'mentions' => count($mentions),
        ]);

        return $comment;
    }

    public function addParticipant(Project $project, User $user, string $entityType, string $entityUuid, string $role): Participant
    {
        return Participant::firstOrCreate([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'entity_type' => $entityType,
            'entity_uuid' => $entityUuid,
            'role' => $role,
        ]);
    }

    public function removeParticipant(Project $project, User $user, string $entityType, string $entityUuid, string $role): void
    {
        Participant::where('project_id', $project->id)
            ->where('user_id', $user->id)
            ->where('entity_type', $entityType)
            ->where('entity_uuid', $entityUuid)
            ->where('role', $role)
            ->delete();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function comments(Project $project, string $entityType, string $entityUuid): array
    {
        return Comment::with('author')
            ->where('project_id', $project->id)
            ->where('entity_type', $entityType)
            ->where('entity_uuid', $entityUuid)
            ->orderBy('created_at')
            ->get()
            ->map(fn (Comment $c): array => [
                'uuid' => $c->uuid,
                'body' => $c->body,
                'mentions' => (array) ($c->mentions ?? []),
                'author' => $c->author ? ['uuid' => $c->author->uuid, 'name' => $c->author->name] : null,
                'created_at' => optional($c->created_at)->toIso8601String(),
            ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function participants(Project $project, string $entityType, string $entityUuid): array
    {
        return Participant::with('user')
            ->where('project_id', $project->id)
            ->where('entity_type', $entityType)
            ->where('entity_uuid', $entityUuid)
            ->get()
            ->map(fn (Participant $p): array => [
                'role' => $p->role,
                'user' => $p->user ? ['uuid' => $p->user->uuid, 'name' => $p->user->name] : null,
            ])->all();
    }
}
