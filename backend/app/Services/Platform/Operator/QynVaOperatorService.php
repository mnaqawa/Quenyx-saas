<?php

declare(strict_types=1);

namespace App\Services\Platform\Operator;

use App\DataTransferObjects\Ai\AiUsage;
use App\Models\Project;
use App\Models\User;
use App\Repositories\Ai\AiConversationRepository;
use App\Services\AI\ModuleAiNarrator;
use App\Services\Platform\Context\EnterpriseContextEngine;
use App\Services\Platform\EventBus\PlatformEventBus;
use App\Services\Platform\EventBus\PlatformEventNames;
use App\Services\QuenyxAI\AiModuleAdapterRegistry;

/**
 * Sprint 25 — QynVA, the Enterprise AI Operator.
 *
 * QynVA is NOT a chatbot. It discovers modules and capabilities through the AI Adapter Registry, builds a
 * single enterprise context through the {@see EnterpriseContextEngine}, reasons through the shared
 * {@see ModuleAiNarrator}, and proposes cross-module coordination — but it NEVER duplicates module logic
 * and NEVER executes anything itself. "Coordination" means producing an editable, evidence-based plan
 * that references EXISTING module actions/endpoints; the operator confirms and the owning module executes
 * through its own approved, audited path. Every operator turn publishes a ConversationCompleted event.
 */
class QynVaOperatorService
{
    private const ROLE_PREAMBLE = 'You are QynVA, the Quenyx Enterprise AI Operator. You coordinate across modules '
        .'(monitoring, assets, automation, incidents, knowledge, tickets, notifications, compliance). Use ONLY the '
        .'provided enterprise context and the catalog of available module actions. Reason about the situation, then '
        .'recommend a concrete, ordered plan that references existing module actions by their keys. NEVER invent data, '
        .'NEVER claim to have executed anything — all execution is performed by the owning module after human approval. '
        .'Be explicit about evidence and about anything that is unknown.';

    public function __construct(
        private readonly AiModuleAdapterRegistry $registry,
        private readonly EnterpriseContextEngine $contextEngine,
        private readonly ModuleAiNarrator $narrator,
        private readonly AiConversationRepository $conversations,
        private readonly PlatformEventBus $eventBus,
    ) {}

    /**
     * Discovered modules, capabilities, and the cross-module action catalog (deterministic).
     *
     * @return array<string, mixed>
     */
    public function capabilities(Project $project): array
    {
        $modules = [];
        $capabilities = [];
        $actions = [];

        foreach ($this->registry->all() as $adapter) {
            $modules[] = [
                'module' => $adapter->moduleKey(),
                'name' => $adapter->moduleName(),
                'category' => $adapter->moduleCategory(),
                'capabilities' => $adapter->capabilities(),
            ];
            $capabilities = array_merge($capabilities, $adapter->capabilities());
            foreach ($adapter->availableActions() as $action) {
                $actions[] = array_merge(['module' => $adapter->moduleKey()], $action);
            }
        }

        return [
            'module_count' => count($modules),
            'modules' => $modules,
            'capabilities' => array_values(array_unique($capabilities)),
            'actions' => $actions,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Operate: reason over the full enterprise context and recommend a cross-module coordination plan.
     *
     * @return array<string, mixed>
     */
    public function operate(Project $project, ?User $user, string $question, ?string $conversationUuid = null): array
    {
        // Exclude qynva from the cross-module gather — the operator IS qynva, so it never recurses on itself.
        $context = $this->contextEngine->build($project, $user, ['query' => $question, 'exclude' => ['qynva']]);
        $catalog = $this->capabilities($project);

        $evidence = [
            'enterprise_context' => $context,
            'available_actions' => $catalog['actions'],
        ];

        $ai = $this->narrator->narrate(
            $project,
            $user,
            'enterprise_operator',
            $evidence,
            $question,
            self::ROLE_PREAMBLE,
            'qynva_operator',
            'qynva.operator.operate',
            ModuleAiNarrator::DEFAULT_GUARDRAILS,
            'text',
            [['source_document_key' => 'platform.enterprise_context', 'official_reference' => 'Enterprise context', 'type' => 'operator']],
        );

        $conversation = $conversationUuid !== null ? $this->conversations->findForProject($project, $conversationUuid) : null;
        if ($conversation === null) {
            $conversation = $this->conversations->start($project, $user, $ai['provider'] ?? 'mock', $ai['model'] ?? null, [
                'title' => 'QynVA Enterprise Operator',
                'origin' => 'qynva_operator',
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

        // Event-driven: announce the completed operator conversation (no direct module calls).
        $this->eventBus->publish(PlatformEventNames::CONVERSATION_COMPLETED, $project, $user, [
            'title' => 'QynVA operator conversation',
            'conversation_uuid' => $conversation->uuid,
            'source' => 'qynva',
        ]);

        return [
            'conversation_uuid' => $conversation->uuid,
            'message_uuid' => $assistant->uuid,
            'answer' => $ai,
            'context_summary' => $context['summary'] ?? [],
            'available_actions' => $catalog['actions'],
            'note' => 'QynVA proposes an evidence-based plan. Execution is performed by the owning module after approval — QynVA never executes directly.',
        ];
    }
}
