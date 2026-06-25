<?php

namespace App\Repositories\Ai;

use App\DataTransferObjects\Ai\AiUsage;
use App\Models\Ai\AiConversation;
use App\Models\Ai\AiConversationMessage;
use App\Models\Project;
use App\Models\User;

/**
 * The single persistence boundary for AI sessions. All conversation/message DB access goes
 * through here — no other AI service touches the database. Stores metadata, usage, and token
 * counts; message CONTENT is only persisted when the caller passes it (gated by the
 * prompt_logging feature flag in the controller).
 */
class AiConversationRepository
{
    public function start(Project $project, ?User $user, string $provider, ?string $model = null, array $metadata = []): AiConversation
    {
        return AiConversation::create([
            'project_id' => $project->id,
            'user_id' => $user?->id,
            'provider' => $provider,
            'model' => $model,
            'status' => 'active',
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }

    public function findForProject(Project $project, string $uuid): ?AiConversation
    {
        return AiConversation::query()
            ->where('project_id', $project->id)
            ->where('uuid', $uuid)
            ->first();
    }

    /**
     * List a project's conversations (most recent first). Metadata-only; no message content.
     *
     * @return \Illuminate\Support\Collection<int, AiConversation>
     */
    public function listForProject(Project $project, int $limit = 50): \Illuminate\Support\Collection
    {
        return AiConversation::query()
            ->where('project_id', $project->id)
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Load a single conversation with its messages (oldest first) scoped to the project.
     */
    public function findForProjectWithMessages(Project $project, string $uuid): ?AiConversation
    {
        return AiConversation::query()
            ->where('project_id', $project->id)
            ->where('uuid', $uuid)
            ->with(['messages' => fn ($q) => $q->orderBy('id')])
            ->first();
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function recordMessage(
        AiConversation $conversation,
        string $role,
        ?string $content,
        AiUsage $usage,
        bool $mocked = false,
        array $metadata = [],
    ): AiConversationMessage {
        $message = $conversation->messages()->create([
            'role' => $role,
            'content' => $content,
            'prompt_tokens' => $usage->promptTokens,
            'completion_tokens' => $usage->completionTokens,
            'total_tokens' => $usage->totalTokens,
            'mocked' => $mocked,
            'metadata' => $metadata === [] ? null : $metadata,
        ]);

        $conversation->increment('message_count');
        $conversation->increment('prompt_tokens', $usage->promptTokens);
        $conversation->increment('completion_tokens', $usage->completionTokens);
        $conversation->increment('total_tokens', $usage->totalTokens);

        return $message;
    }
}
