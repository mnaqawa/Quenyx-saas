<?php

declare(strict_types=1);

namespace App\Services\Support\Intelligence;

use App\DataTransferObjects\Ai\AiUsage;
use App\Models\Automation\AutomationRunbook;
use App\Models\Project;
use App\Models\Support\Ticket;
use App\Models\User;
use App\Repositories\Ai\AiConversationRepository;
use App\Services\AI\ModuleAiNarrator;
use App\Services\Knowledge\EnterpriseSearchService;

/**
 * Sprint 24 — Ticket Intelligence (QynSupport).
 *
 * Produces EVIDENCE-BASED, editable suggestions for a ticket: category, priority, impact, suggested
 * assignee, related incidents/assets/runbooks, and a suggested SLA — derived deterministically from the
 * ticket content and the workspace's real history. The narrated rationale is generated through the
 * shared {@see ModuleAiNarrator} (no duplicated AI logic). Nothing is auto-applied.
 */
class TicketIntelligenceService
{
    private const AUDIT_PREFIX = 'ticket_intelligence_';

    private const ROLE_PREAMBLE = 'You are Quenyx AI operating as an enterprise Service Desk analyst for QynSupport. '
        .'Recommend ticket triage (category, priority, impact, assignee, SLA) and surface related incidents, assets, '
        .'and runbooks using ONLY the deterministic evidence provided below — the ticket content and the workspace '
        .'history. Never invent users, incidents, or assets. If evidence is insufficient, say so. Cite the evidence.';

    public function __construct(
        private readonly EnterpriseSearchService $search,
        private readonly ModuleAiNarrator $narrator,
        private readonly AiConversationRepository $conversations,
    ) {}

    /**
     * Deterministic, evidence-based triage suggestions + AI rationale.
     *
     * @return array<string, mixed>
     */
    public function analyze(Project $project, ?User $user, Ticket $ticket): array
    {
        $suggestions = $this->suggest($project, $ticket);
        $evidence = [
            'ticket' => [
                'reference' => $ticket->reference,
                'subject' => $ticket->subject,
                'description' => $ticket->description,
                'current_category' => $ticket->category,
                'current_priority' => $ticket->priority,
            ],
            'suggestions' => $suggestions,
        ];

        $question = sprintf(
            'Review ticket "%s" and explain the recommended category, priority, impact, assignee, and SLA, and '
            .'how the related incidents/assets/runbooks help resolve it. Use only the provided evidence.',
            $ticket->subject,
        );

        $ai = $this->narrate($project, $user, 'ticket_triage', $evidence, $question, 'analyze', 'qynsupport.intelligence.analyze');

        // Persist the latest evidence-based suggestions (editable, not applied).
        $ticket->ai_suggestions = $suggestions;
        $ticket->save();

        return [
            'ticket_uuid' => $ticket->uuid,
            'suggestions' => $suggestions,
            'ai_rationale' => $ai,
            'note' => 'Evidence-based suggestions. Review and apply manually — nothing is auto-applied.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function copilot(Project $project, ?User $user, Ticket $ticket, string $question, ?string $conversationUuid = null): array
    {
        $evidence = [
            'ticket' => $this->ticketEvidence($ticket),
            'suggestions' => $this->suggest($project, $ticket),
        ];
        $ai = $this->narrate($project, $user, 'ticket_copilot', $evidence, $question, 'copilot', 'qynsupport.intelligence.copilot');

        $providerKey = $ai['provider'] ?? 'mock';
        $conversation = $conversationUuid !== null ? $this->conversations->findForProject($project, $conversationUuid) : null;
        if ($conversation === null) {
            $conversation = $this->conversations->start($project, $user, $providerKey, $ai['model'] ?? null, [
                'title' => 'Ticket Copilot · '.$ticket->reference,
                'origin' => 'qynsupport_ticket_intelligence',
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
     * Deterministic suggestion engine. Pure functions of the ticket + workspace history.
     *
     * @return array<string, mixed>
     */
    private function suggest(Project $project, Ticket $ticket): array
    {
        $text = strtolower(trim(($ticket->subject ?? '').' '.($ticket->description ?? '')));

        $category = $ticket->category ?: $this->inferCategory($text);
        $priority = $this->inferPriority($text, $ticket->priority);
        $impact = $this->inferImpact($priority);
        $slaHours = (int) (config('knowledge.service_desk.sla_hours.'.$priority) ?? 24);

        $related = $this->search->search($project, (string) $ticket->subject, ['limit' => 5, 'types' => ['incident', 'runbook', 'asset']]);
        $relatedIncidents = array_values(array_filter($related['results'], static fn ($r): bool => $r['type'] === 'incident'));
        $relatedRunbooks = array_values(array_filter($related['results'], static fn ($r): bool => $r['type'] === 'runbook'));

        return [
            'category' => $category,
            'priority' => $priority,
            'impact' => $impact,
            'suggested_sla' => [
                'priority' => $priority,
                'hours' => $slaHours,
                'due_at' => now()->addHours($slaHours)->toIso8601String(),
            ],
            'suggested_assignee' => $this->suggestAssignee($project, $category),
            'related_incidents' => $relatedIncidents,
            'related_runbooks' => $relatedRunbooks,
            'available_runbooks' => AutomationRunbook::where('project_id', $project->id)->where('category', $category)->count(),
        ];
    }

    private function inferCategory(string $text): string
    {
        return match (true) {
            str_contains($text, 'password') || str_contains($text, 'login') || str_contains($text, 'access') => 'access',
            str_contains($text, 'network') || str_contains($text, 'vpn') || str_contains($text, 'dns') => 'network',
            str_contains($text, 'disk') || str_contains($text, 'server') || str_contains($text, 'cpu') || str_contains($text, 'hardware') => 'hardware',
            str_contains($text, 'install') || str_contains($text, 'software') || str_contains($text, 'license') => 'software',
            str_contains($text, 'breach') || str_contains($text, 'malware') || str_contains($text, 'phishing') || str_contains($text, 'security') => 'security',
            str_contains($text, 'down') || str_contains($text, 'outage') || str_contains($text, 'incident') => 'incident',
            default => 'request',
        };
    }

    private function inferPriority(string $text, ?string $current): string
    {
        if (str_contains($text, 'critical') || str_contains($text, 'outage') || str_contains($text, 'down') || str_contains($text, 'breach')) {
            return 'critical';
        }
        if (str_contains($text, 'urgent') || str_contains($text, 'asap') || str_contains($text, 'production')) {
            return 'high';
        }
        if (str_contains($text, 'minor') || str_contains($text, 'whenever') || str_contains($text, 'low')) {
            return 'low';
        }

        return $current ?: 'medium';
    }

    private function inferImpact(string $priority): string
    {
        return match ($priority) {
            'critical' => 'org',
            'high' => 'service',
            'medium' => 'team',
            default => 'individual',
        };
    }

    /**
     * Suggest the assignee who has resolved the most tickets in this category (real evidence). Returns
     * an honest "insufficient evidence" marker when no history exists.
     *
     * @return array<string, mixed>
     */
    private function suggestAssignee(Project $project, string $category): array
    {
        $top = Ticket::query()
            ->where('project_id', $project->id)
            ->where('category', $category)
            ->where('status', 'resolved')
            ->whereNotNull('assigned_to')
            ->selectRaw('assigned_to, count(*) as c')
            ->groupBy('assigned_to')
            ->orderByDesc('c')
            ->first();

        if ($top === null) {
            return ['available' => false, 'reason' => 'No resolved tickets in this category yet — insufficient evidence.'];
        }

        $user = User::find($top->assigned_to);

        return [
            'available' => true,
            'user' => $user ? ['uuid' => $user->uuid, 'name' => $user->name] : null,
            'resolved_in_category' => (int) $top->c,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ticketEvidence(Ticket $ticket): array
    {
        return [
            'reference' => $ticket->reference,
            'subject' => $ticket->subject,
            'description' => $ticket->description,
            'category' => $ticket->category,
            'priority' => $ticket->priority,
            'status' => $ticket->status,
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
            [['source_document_key' => 'qynsupport.ticket_evidence', 'official_reference' => 'Ticket evidence', 'type' => 'ticket']],
        );
    }
}
