<?php

namespace App\Services\Compliance\Copilot;

use App\DataTransferObjects\Ai\AiUsage;
use App\Models\Ai\AiConversation;
use App\Models\Project;
use App\Models\User;
use App\Repositories\Ai\AiConversationRepository;
use Illuminate\Support\Str;

/**
 * Owns Copilot conversation/session state (QCIF Sprint 14).
 *
 * All database access is delegated to {@see AiConversationRepository} (the single AI persistence
 * boundary) — this service issues NO direct queries of its own. Persistence is OFF by default and
 * only happens when `ai.copilot.persist_conversations` is enabled; message CONTENT is only stored
 * when `ai.copilot.prompt_logging` is enabled. When persistence is off, ephemeral UUIDs are
 * minted so the response contract always carries a conversation_uuid + message_uuid.
 */
class ComplianceCopilotSessionService
{
    public function __construct(
        private readonly AiConversationRepository $conversations,
    ) {}

    public function persistenceEnabled(): bool
    {
        return (bool) config('ai.copilot.persist_conversations', false);
    }

    public function promptLoggingEnabled(): bool
    {
        return (bool) config('ai.copilot.prompt_logging', false);
    }

    /**
     * Resolve (or create) the conversation for this turn. Returns null when persistence is off.
     */
    public function resolveConversation(Project $project, ?User $user, ?string $conversationUuid, string $provider): ?AiConversation
    {
        if (! $this->persistenceEnabled()) {
            return null;
        }

        $conversation = null;
        if ($conversationUuid !== null && $conversationUuid !== '') {
            $conversation = $this->conversations->findForProject($project, $conversationUuid);
        }

        return $conversation ?? $this->conversations->start($project, $user, $provider);
    }

    /**
     * Record the user + assistant turn. Returns the conversation_uuid + message_uuid to surface in
     * the response — minting ephemeral UUIDs when persistence is disabled. Message content is only
     * stored when prompt logging is enabled; intent/mode are always stored as non-content metadata.
     *
     * @return array{conversation_uuid: string, message_uuid: string}
     */
    public function recordTurn(
        ?AiConversation $conversation,
        string $userMessage,
        string $intent,
        string $mode,
        string $provider,
        string $assistantAnswer,
        AiUsage $usage,
        bool $mocked,
    ): array {
        if ($conversation === null) {
            return [
                'conversation_uuid' => (string) Str::uuid(),
                'message_uuid' => (string) Str::uuid(),
            ];
        }

        $logContent = $this->promptLoggingEnabled();

        $this->conversations->recordMessage(
            $conversation,
            'user',
            $logContent ? $userMessage : null,
            new AiUsage(),
            $mocked,
            ['intent' => $intent],
        );

        $assistantMessage = $this->conversations->recordMessage(
            $conversation,
            'assistant',
            $logContent ? $assistantAnswer : null,
            $usage,
            $mocked,
            ['intent' => $intent, 'mode' => $mode, 'provider' => $provider],
        );

        return [
            'conversation_uuid' => $conversation->uuid,
            'message_uuid' => $assistantMessage->uuid,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listConversations(Project $project): array
    {
        if (! $this->persistenceEnabled()) {
            return [];
        }

        return $this->conversations->listForProject($project)
            ->map(fn (AiConversation $c) => [
                'conversation_uuid' => $c->uuid,
                'provider' => $c->provider,
                'status' => $c->status,
                'message_count' => (int) $c->message_count,
                'last_intent' => $c->metadata['intent'] ?? null,
                'created_at' => optional($c->created_at)->toIso8601String(),
                'updated_at' => optional($c->updated_at)->toIso8601String(),
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function showConversation(Project $project, string $conversationUuid): ?array
    {
        if (! $this->persistenceEnabled()) {
            return null;
        }

        $conversation = $this->conversations->findForProjectWithMessages($project, $conversationUuid);
        if ($conversation === null) {
            return null;
        }

        $logContent = $this->promptLoggingEnabled();

        return [
            'conversation_uuid' => $conversation->uuid,
            'provider' => $conversation->provider,
            'status' => $conversation->status,
            'message_count' => (int) $conversation->message_count,
            'created_at' => optional($conversation->created_at)->toIso8601String(),
            'updated_at' => optional($conversation->updated_at)->toIso8601String(),
            'messages' => $conversation->messages->map(fn ($m) => [
                'message_uuid' => $m->uuid,
                'role' => $m->role,
                'intent' => $m->metadata['intent'] ?? null,
                'mode' => $m->metadata['mode'] ?? null,
                'content' => $logContent ? $m->content : null,
                'created_at' => optional($m->created_at)->toIso8601String(),
            ])->all(),
        ];
    }
}
