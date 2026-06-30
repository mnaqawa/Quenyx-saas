<?php

declare(strict_types=1);

namespace App\Services\Notification\Intelligence;

use App\DataTransferObjects\Ai\AiUsage;
use App\Models\Project;
use App\Models\User;
use App\Repositories\Ai\AiConversationRepository;
use App\Services\AI\ModuleAiNarrator;
use App\Services\Notification\NotificationService;

/**
 * Sprint 24 — Notification Intelligence (QynNotify).
 *
 * Generates digests and executive summaries over the workspace's REAL active notifications (urgency,
 * correlation groups, channel mix) and answers questions through the shared {@see ModuleAiNarrator}.
 * Deterministic evidence is assembled by the {@see NotificationService}; the AI only narrates it — no
 * duplicated AI logic and no fabricated routing.
 */
class NotificationIntelligenceService
{
    private const AUDIT_PREFIX = 'notification_intelligence_';

    private const ROLE_PREAMBLE = 'You are Quenyx AI operating as an enterprise notification & on-call analyst for '
        .'QynNotify. Summarize and prioritize notifications using ONLY the provided evidence: active notifications, '
        .'their urgency scores, correlation groups, and selected channels/recipients. Never invent recipients, '
        .'channels, or events. Group related signals and highlight what needs attention first. Cite the evidence.';

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly ModuleAiNarrator $narrator,
        private readonly AiConversationRepository $conversations,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function digest(Project $project, ?User $user): array
    {
        $evidence = $this->evidence($project);
        $question = 'Produce a concise on-call digest of the current notifications: what is most urgent, which '
            .'signals are correlated, and the recommended next action. Use only the provided evidence.';
        $ai = $this->narrate($project, $user, 'notification_digest', $evidence, $question, 'digest', 'qynnotify.intelligence.digest');

        return ['digest' => $ai, 'evidence' => $evidence];
    }

    /**
     * @return array<string, mixed>
     */
    public function executiveSummary(Project $project, ?User $user): array
    {
        $evidence = $this->evidence($project);
        $question = 'Write a brief executive summary of the operational notification posture for leadership: overall '
            .'severity, notable correlation clusters, and whether escalation is warranted. Use only the evidence.';
        $ai = $this->narrate($project, $user, 'notification_executive_summary', $evidence, $question, 'executive_summary', 'qynnotify.intelligence.executive');

        return ['executive_summary' => $ai, 'evidence' => $evidence];
    }

    /**
     * @return array<string, mixed>
     */
    public function copilot(Project $project, ?User $user, string $question, ?string $conversationUuid = null): array
    {
        $evidence = $this->evidence($project);
        $ai = $this->narrate($project, $user, 'notification_copilot', $evidence, $question, 'copilot', 'qynnotify.intelligence.copilot');

        $providerKey = $ai['provider'] ?? 'mock';
        $conversation = $conversationUuid !== null ? $this->conversations->findForProject($project, $conversationUuid) : null;
        if ($conversation === null) {
            $conversation = $this->conversations->start($project, $user, $providerKey, $ai['model'] ?? null, [
                'title' => 'Notification Copilot',
                'origin' => 'qynnotify_notification_intelligence',
            ]);
        }

        $promptLogging = (bool) config('ai.feature_flags.prompt_logging', false);
        $this->conversations->recordMessage($conversation, 'user', $promptLogging ? $question : null, new AiUsage(), (bool) ($ai['mocked'] ?? false));
        $assistant = $this->conversations->recordMessage(
            $conversation,
            'assistant',
            $promptLogging ? ($ai['content'] ?? null) : null,
            new AiUsage(
                (int) ($ai['usage']['prompt_tokens'] ?? 0),
                (int) ($ai['usage']['completion_tokens'] ?? 0),
                (int) ($ai['usage']['total_tokens'] ?? 0),
            ),
            (bool) ($ai['mocked'] ?? false),
        );

        return [
            'conversation_uuid' => $conversation->uuid,
            'message_uuid' => $assistant->uuid,
            'answer' => $ai,
            'evidence' => $evidence,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function evidence(Project $project): array
    {
        return [
            'active_notifications' => $this->notifications->list($project, ['limit' => 50]),
            'correlations' => $this->notifications->correlations($project),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    private function narrate(Project $project, ?User $user, string $contextType, array $evidence, string $question, string $action, string $endpoint): array
    {
        return $this->narrator->narrate(
            $project,
            $user,
            $contextType,
            $evidence,
            $question,
            self::ROLE_PREAMBLE,
            self::AUDIT_PREFIX.$action,
            $endpoint,
            ModuleAiNarrator::DEFAULT_GUARDRAILS,
            'text',
            [['source_document_key' => 'qynnotify.active_notifications', 'official_reference' => 'Active notifications', 'type' => 'notifications']],
        );
    }
}
