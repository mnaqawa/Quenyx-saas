<?php

declare(strict_types=1);

namespace App\Services\Automation\Intelligence;

use App\DataTransferObjects\Ai\AiUsage;
use App\Models\Automation\AutomationApproval;
use App\Models\Automation\AutomationExecution;
use App\Models\Automation\AutomationRunbook;
use App\Models\Automation\AutomationWorkflow;
use App\Models\Project;
use App\Models\User;
use App\Repositories\Ai\AiConversationRepository;
use App\Services\AI\ModuleAiNarrator;
use App\Services\Automation\ActionRegistry;
use App\Services\Automation\AutomationAdapterRegistry;
use App\Services\Automation\AutomationLearningService;
use App\Services\Automation\ExecutionHistory;

/**
 * Sprint 23 — QynRun Automation Intelligence orchestrator.
 *
 * Provides the automation dashboard, the Automation Copilot, AI-assisted runbook DRAFTING, and
 * execution explanations. Every AI answer is narrated through the shared {@see ModuleAiNarrator}
 * (no duplicated AI logic) and grounded ONLY in real evidence: the registry-discovered adapters and
 * actions, the workspace's execution history, and the auditable Automation Learning statistics.
 * AI-suggested runbooks are editable drafts — they are NEVER auto-executed.
 */
class QynRunIntelligenceService
{
    private const AUDIT_PREFIX = 'automation_intelligence_';

    private const ROLE_PREAMBLE = 'You are Quenyx AI operating as an enterprise automation & SRE architect for the '
        .'QynRun platform. You design and explain automation using ONLY the registered execution adapters, the '
        .'registered action catalog, the workspace execution history, and the auditable automation-learning '
        .'statistics provided below. Never invent adapters, actions, hosts, or outcomes. Prefer safe, reversible, '
        .'diagnostic-first steps; clearly flag any destructive step as requiring approval. Cite the evidence you use.';

    public function __construct(
        private readonly AutomationAdapterRegistry $adapters,
        private readonly ActionRegistry $actions,
        private readonly AutomationLearningService $learning,
        private readonly ExecutionHistory $history,
        private readonly ModuleAiNarrator $narrator,
        private readonly AiConversationRepository $conversations,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function overview(Project $project): array
    {
        return [
            'counts' => [
                'workflows' => AutomationWorkflow::where('project_id', $project->id)->count(),
                'runbooks' => AutomationRunbook::where('project_id', $project->id)->count(),
                'executions' => AutomationExecution::where('project_id', $project->id)->count(),
                'pending_approvals' => AutomationApproval::where('project_id', $project->id)->where('status', 'pending')->count(),
            ],
            'executions_by_status' => AutomationExecution::where('project_id', $project->id)
                ->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status')->all(),
            'adapters' => $this->adapters->describeAll(),
            'action_catalog' => $this->actions->all(),
            'learning' => $this->learning->stats($project),
            'recent_executions' => $this->history->list($project, ['limit' => 10]),
            'live_execution_enabled' => (bool) config('automation.live_execution', false),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Automation Copilot — grounded in the catalog + history + learning, reusing the shared
     * conversation surface.
     *
     * @return array<string, mixed>
     */
    public function copilot(Project $project, ?User $user, string $question, ?string $conversationUuid = null): array
    {
        $evidence = $this->automationEvidence($project);
        $ai = $this->narrate($project, $user, 'automation_copilot', $evidence, $question, 'copilot', 'qynrun.intelligence.copilot', $this->catalogCitations());

        $providerKey = $ai['provider'] ?? 'mock';
        $conversation = $conversationUuid !== null ? $this->conversations->findForProject($project, $conversationUuid) : null;
        if ($conversation === null) {
            $conversation = $this->conversations->start($project, $user, $providerKey, $ai['model'] ?? null, [
                'title' => 'Automation Copilot',
                'origin' => 'qynrun_automation_intelligence',
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
     * AI-assisted runbook drafting. Returns an EDITABLE suggested definition + an AI rationale.
     * Nothing is persisted or executed here.
     *
     * @return array<string, mixed>
     */
    public function suggestRunbook(Project $project, ?User $user, string $problem): array
    {
        $suggestion = $this->draftRunbook($problem);
        $evidence = array_merge($this->automationEvidence($project), [
            'problem' => $problem,
            'suggested_runbook' => $suggestion,
        ]);

        $question = sprintf(
            'Draft an editable runbook for the problem: "%s". Use only the registered adapters and actions. '
            .'Order the steps diagnostic-first, then remediation. Flag every destructive step as requiring '
            .'approval. Explain each step briefly.',
            $problem
        );

        $ai = $this->narrate($project, $user, 'runbook_suggestion', $evidence, $question, 'suggest_runbook', 'qynrun.intelligence.runbooks.suggest', $this->catalogCitations());

        return [
            'problem' => $problem,
            'suggested_runbook' => $suggestion,
            'ai_rationale' => $ai,
            'note' => 'This is an editable draft. Review and adjust before saving. AI-generated runbooks are never auto-executed.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function explainExecution(Project $project, ?User $user, AutomationExecution $execution): array
    {
        $evidence = $this->history->detail($execution);
        $question = sprintf(
            'Explain automation execution "%s" (adapter %s, status %s): what was attempted, what happened, '
            .'and the safe next step. Use only the execution evidence.',
            $execution->uuid,
            $execution->adapter_key,
            $execution->status,
        );

        $ai = $this->narrate($project, $user, 'execution_explain', $evidence, $question, 'explain_execution', 'qynrun.intelligence.executions.explain', [[
            'source_document_key' => 'qynrun.execution.'.$execution->uuid,
            'official_reference' => 'Execution '.$execution->uuid,
            'type' => 'execution',
        ]]);

        return array_merge(['execution' => $evidence], ['ai_explanation' => $ai]);
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Deterministic, safe runbook draft from the action catalog. Diagnostic-first, remediation flagged.
     *
     * @return array<string, mixed>
     */
    private function draftRunbook(string $problem): array
    {
        $key = strtolower($problem);
        $steps = [
            [
                'name' => 'Capture diagnostics',
                'description' => 'Collect read-only diagnostics for the affected target.',
                'adapter_key' => 'script',
                'action_key' => 'run_diagnostic_script',
                'parameters' => ['interpreter' => 'bash', 'script' => $this->diagnosticScriptFor($key)],
                'destructive' => false,
            ],
            [
                'name' => 'Notify on-call',
                'description' => 'Post a structured notification to the incident channel.',
                'adapter_key' => 'webhook',
                'action_key' => 'send_webhook',
                'parameters' => ['url' => '', 'payload' => ['problem' => $problem]],
                'destructive' => false,
            ],
        ];

        // Remediation step (destructive → requires approval), tailored to the problem family.
        $steps[] = $this->remediationStepFor($key, $problem);

        return [
            'name' => 'Runbook: '.$problem,
            'category' => $this->categoryFor($key),
            'source' => 'ai_assisted',
            'status' => 'draft',
            'definition' => ['steps' => $steps],
        ];
    }

    private function diagnosticScriptFor(string $key): string
    {
        return match (true) {
            str_contains($key, 'cpu') => 'top -bn1 | head -20; uptime',
            str_contains($key, 'disk') => 'df -h; du -xh / 2>/dev/null | sort -rh | head -20',
            str_contains($key, 'apache') || str_contains($key, 'http') => 'systemctl status apache2 --no-pager; tail -n 50 /var/log/apache2/error.log',
            str_contains($key, 'database') || str_contains($key, 'latency') => 'systemctl status mysql --no-pager; tail -n 50 /var/log/mysql/error.log',
            str_contains($key, 'vpn') => 'systemctl status openvpn --no-pager; tail -n 50 /var/log/openvpn.log',
            default => 'uptime; free -m; df -h',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function remediationStepFor(string $key, string $problem): array
    {
        [$action, $params, $desc] = match (true) {
            str_contains($key, 'apache') || str_contains($key, 'http') => ['restart_service_linux', ['host' => '', 'command' => 'systemctl restart apache2', 'rollback_command' => ''], 'Restart Apache after diagnostics confirm the cause.'],
            str_contains($key, 'database') => ['restart_service_linux', ['host' => '', 'command' => 'systemctl restart mysql', 'rollback_command' => ''], 'Restart the database service if safe to do so.'],
            str_contains($key, 'disk') => ['clear_disk_space', ['host' => '', 'command' => 'journalctl --vacuum-time=3d'], 'Reclaim disk space with a guarded cleanup.'],
            str_contains($key, 'service') || str_contains($key, 'down') => ['restart_service_linux', ['host' => '', 'command' => 'systemctl restart <service>', 'rollback_command' => ''], 'Restart the failed service.'],
            default => ['restart_service_linux', ['host' => '', 'command' => '', 'rollback_command' => ''], 'Remediation step — edit before running.'],
        };

        return [
            'name' => 'Remediation (requires approval)',
            'description' => $desc,
            'adapter_key' => 'ssh',
            'action_key' => $action,
            'parameters' => $params,
            'destructive' => true,
        ];
    }

    private function categoryFor(string $key): string
    {
        return match (true) {
            str_contains($key, 'cpu') || str_contains($key, 'disk') || str_contains($key, 'memory') => 'capacity',
            str_contains($key, 'database') => 'database',
            str_contains($key, 'vpn') || str_contains($key, 'network') => 'network',
            default => 'service',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function automationEvidence(Project $project): array
    {
        return [
            'adapters' => array_map(fn (array $a): array => [
                'key' => $a['key'], 'name' => $a['name'], 'category' => $a['category'], 'operational' => $a['operational'],
            ], $this->adapters->describeAll()),
            'action_catalog' => $this->actions->all(),
            'learning' => $this->learning->stats($project),
            'recent_executions' => $this->history->list($project, ['limit' => 15]),
            'live_execution_enabled' => (bool) config('automation.live_execution', false),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function catalogCitations(): array
    {
        $refs = [['source_document_key' => 'qynrun.action_catalog', 'official_reference' => 'Automation action catalog', 'type' => 'catalog']];
        foreach ($this->adapters->keys() as $key) {
            $refs[] = ['source_document_key' => 'qynrun.adapter.'.$key, 'official_reference' => 'Adapter: '.$key, 'type' => 'adapter'];
        }

        return array_slice($refs, 0, 20);
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
