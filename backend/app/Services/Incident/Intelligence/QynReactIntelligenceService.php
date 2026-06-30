<?php

declare(strict_types=1);

namespace App\Services\Incident\Intelligence;

use App\DataTransferObjects\Ai\AiUsage;
use App\Models\Incident\Incident;
use App\Models\Project;
use App\Models\User;
use App\Repositories\Ai\AiConversationRepository;
use App\Services\Ai\ModuleAiNarrator;
use App\Services\Automation\AutomationLearningService;
use App\Services\Incident\IncidentService;

/**
 * Sprint 23 — QynReact Incident Intelligence orchestrator.
 *
 * Narrates the unified incident workspace: incident copilot, evidence-based response recommendations,
 * and postmortem drafting. It REUSES Operations & Asset Intelligence through the cross-module
 * orchestrator (no branching) and the auditable Automation Learning statistics, and narrates through
 * the shared {@see ModuleAiNarrator} (no duplicated AI logic). Nothing is fabricated and nothing is
 * auto-executed — recommendations are suggestions an operator runs through the approval gate.
 */
class QynReactIntelligenceService
{
    private const AUDIT_PREFIX = 'incident_intelligence_';

    private const ROLE_PREAMBLE = 'You are Quenyx AI operating as an incident commander for the QynReact platform. '
        .'You reason over a unified incident workspace (timeline, linked assets, monitoring/alerts, automation '
        .'history, evidence) assembled from other modules, plus auditable automation-learning statistics. Use ONLY '
        .'the provided evidence — never invent assets, alerts, metrics, or outcomes. Recommend safe, reversible, '
        .'diagnostic-first response actions and clearly flag destructive ones as requiring approval. Cite the '
        .'evidence (modules, executions, learning stats) you rely on.';

    public function __construct(
        private readonly IncidentService $incidents,
        private readonly AutomationLearningService $learning,
        private readonly ModuleAiNarrator $narrator,
        private readonly AiConversationRepository $conversations,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function copilot(Project $project, ?User $user, Incident $incident, string $question, ?string $conversationUuid = null): array
    {
        $evidence = $this->incidentEvidence($project, $incident);
        $ai = $this->narrate($project, $user, 'incident_copilot', $evidence, $question, 'copilot', 'qynreact.intelligence.copilot', $this->incidentCitations($incident));

        $providerKey = $ai['provider'] ?? 'mock';
        $conversation = $conversationUuid !== null ? $this->conversations->findForProject($project, $conversationUuid) : null;
        if ($conversation === null) {
            $conversation = $this->conversations->start($project, $user, $providerKey, $ai['model'] ?? null, [
                'title' => 'Incident Copilot — '.$incident->title,
                'origin' => 'qynreact_incident_intelligence',
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
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function recommend(Project $project, ?User $user, Incident $incident): array
    {
        $evidence = $this->incidentEvidence($project, $incident);
        $question = 'Recommend the next response actions for this incident, ordered diagnostic-first then '
            .'remediation. Cite the cross-module evidence and the historical automation-learning success rates. '
            .'Flag destructive actions as requiring approval.';

        $ai = $this->narrate($project, $user, 'incident_recommend', $evidence, $question, 'recommend', 'qynreact.intelligence.recommend', $this->incidentCitations($incident));

        return ['recommendations' => $ai, 'evidence' => $evidence];
    }

    /**
     * @return array<string, mixed>
     */
    public function postmortem(Project $project, ?User $user, Incident $incident): array
    {
        $evidence = $this->incidentEvidence($project, $incident);
        $question = 'Draft an editable postmortem for this incident: summary, customer/business impact, timeline of '
            .'key events, root-cause hypothesis supported by the evidence (clearly labelled as a hypothesis), what '
            .'went well, what to improve, and concrete action items. Use only the provided evidence.';

        $ai = $this->narrate($project, $user, 'incident_postmortem', $evidence, $question, 'postmortem', 'qynreact.intelligence.postmortem', $this->incidentCitations($incident));

        return [
            'postmortem_draft' => $ai,
            'note' => 'This is an editable draft generated from the incident evidence. Review before publishing.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function incidentEvidence(Project $project, Incident $incident): array
    {
        $workspace = $this->incidents->workspace($incident);
        $workspace['automation_learning'] = $this->learning->stats($project);

        return $workspace;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function incidentCitations(Incident $incident): array
    {
        return [[
            'source_document_key' => 'qynreact.incident.'.$incident->uuid,
            'official_reference' => 'Incident: '.$incident->title,
            'type' => 'incident',
        ]];
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @param  list<array<string, mixed>>  $citations
     * @return array<string, mixed>
     */
    private function narrate(Project $project, ?User $user, string $contextType, array $evidence, string $question, string $action, string $endpoint, array $citations): array
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
            $citations,
        );
    }
}
