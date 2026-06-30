<?php

declare(strict_types=1);

namespace App\Services\Platform\Cost;

use App\DataTransferObjects\Ai\AiUsage;
use App\Models\Project;
use App\Models\User;
use App\Repositories\Ai\AiConversationRepository;
use App\Services\Ai\ModuleAiNarrator;

/**
 * Sprint 25 — QynBalance Cost Intelligence copilot.
 *
 * Narrates the deterministic cost overview through the shared {@see ModuleAiNarrator}. It explains real
 * counts, configured-vs-missing pricing, and evidence-based optimization recommendations — and never
 * fabricates a financial figure. Reuses the shared Quenyx AI conversation surface.
 */
class CostIntelligenceCopilot
{
    private const ROLE_PREAMBLE = 'You are Quenyx AI as a FinOps analyst for QynBalance. Use ONLY the provided cost '
        .'evidence (resource counts, configured unit rates, recommendations). If pricing is unavailable, say so '
        .'plainly and reason about counts/utilization instead of inventing currency values. Recommendations are advisory.';

    public function __construct(
        private readonly CostIntelligenceService $cost,
        private readonly ModuleAiNarrator $narrator,
        private readonly AiConversationRepository $conversations,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function copilot(Project $project, ?User $user, string $question, ?string $conversationUuid = null): array
    {
        $evidence = ['cost' => $this->cost->overview($project)];

        $ai = $this->narrator->narrate(
            $project,
            $user,
            'cost_copilot',
            $evidence,
            $question,
            self::ROLE_PREAMBLE,
            'qynbalance_cost_copilot',
            'qynbalance.cost.copilot',
            ModuleAiNarrator::DEFAULT_GUARDRAILS,
            'text',
            [['source_document_key' => 'qynbalance.cost_evidence', 'official_reference' => 'Cost evidence', 'type' => 'cost']],
        );

        $conversation = $conversationUuid !== null ? $this->conversations->findForProject($project, $conversationUuid) : null;
        if ($conversation === null) {
            $conversation = $this->conversations->start($project, $user, $ai['provider'] ?? 'mock', $ai['model'] ?? null, [
                'title' => 'Cost Intelligence',
                'origin' => 'qynbalance_cost_intelligence',
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
}
