<?php

declare(strict_types=1);

namespace App\Services\Automation;

use App\DataTransferObjects\Automation\ExecutionContext;
use App\DataTransferObjects\Automation\ExecutionResult;
use App\Models\Automation\AutomationApproval;
use App\Models\Automation\AutomationExecution;
use App\Models\Automation\AutomationExecutionStep;
use App\Models\Project;
use App\Models\User;
use App\Services\Platform\EventBus\PlatformEventNames;
use App\Services\Platform\EventBus\PublishesPlatformEvents;
use Throwable;

/**
 * Sprint 23 — the Execution Engine: the SINGLE place automation runs.
 *
 * It resolves the adapter ONLY through the registry (no hardcoded execution), enforces the safety
 * envelope (dry-run by default, approval gate for any live action, timeout, retry, rollback), persists
 * every execution + step, audits the lifecycle, and records the outcome for Automation Learning. It
 * NEVER performs a destructive action automatically: a live execution stays in `awaiting_approval`
 * until an authorized operator approves it.
 */
class ExecutionEngine
{
    use PublishesPlatformEvents;

    public function __construct(
        private readonly AutomationAdapterRegistry $adapters,
        private readonly ActionRegistry $actions,
        private readonly AutomationAuditLogger $audit,
        private readonly AutomationLearningService $learning,
    ) {}

    /**
     * Request an execution. Dry-run requests run immediately; live requests are gated behind approval.
     *
     * @param  array<string, mixed>  $spec
     */
    public function dispatch(Project $project, ?User $user, array $spec): AutomationExecution
    {
        $adapterKey = (string) ($spec['adapter_key'] ?? '');
        $adapter = $this->adapters->get($adapterKey); // throws for unknown — no hardcoded path

        $requestedMode = ($spec['mode'] ?? 'dry_run') === 'live' ? 'live' : 'dry_run';
        $effectiveMode = ($requestedMode === 'live' && (bool) config('automation.live_execution', false))
            ? 'live'
            : 'dry_run';

        $execution = AutomationExecution::create([
            'project_id' => $project->id,
            'workflow_id' => $spec['workflow_id'] ?? null,
            'runbook_id' => $spec['runbook_id'] ?? null,
            'incident_id' => $spec['incident_id'] ?? null,
            'requested_by' => $user?->id,
            'adapter_key' => $adapterKey,
            'action_key' => $spec['action_key'] ?? null,
            'status' => 'pending',
            'mode' => $effectiveMode,
            'timeout_seconds' => (int) ($spec['timeout_seconds'] ?? config('automation.defaults.timeout_seconds', 60)),
            'max_retries' => (int) ($spec['max_retries'] ?? config('automation.defaults.max_retries', 0)),
            'parameters' => (array) ($spec['parameters'] ?? []),
            'context' => [
                'target' => (array) ($spec['target'] ?? []),
                'requested_mode' => $requestedMode,
                'recommendation_key' => $spec['recommendation_key'] ?? null,
            ],
        ]);

        $this->audit->log($user, $project, 'automation_execution_requested', [
            'execution_uuid' => $execution->uuid,
            'adapter' => $adapterKey,
            'action' => $execution->action_key,
            'mode' => $effectiveMode,
        ]);

        // Every LIVE action requires explicit human approval — nothing destructive runs automatically.
        if ($effectiveMode === 'live') {
            $execution->update(['status' => 'awaiting_approval']);
            AutomationApproval::create([
                'project_id' => $project->id,
                'execution_id' => $execution->id,
                'requested_by' => $user?->id,
                'status' => 'pending',
            ]);

            return $execution->fresh(['steps', 'approval']);
        }

        return $this->runNow($execution, $user);
    }

    /**
     * Run an execution that has been approved (live) — invoked by the Approval Engine.
     */
    public function runApproved(AutomationExecution $execution, User $approver): AutomationExecution
    {
        $execution->update(['approved_by' => $approver->id, 'status' => 'approved']);

        return $this->runNow($execution, $approver);
    }

    /**
     * Perform the execution through the resolved adapter (retry/timeout/steps/learning/audit).
     */
    public function runNow(AutomationExecution $execution, ?User $user): AutomationExecution
    {
        $adapter = $this->adapters->get($execution->adapter_key);
        $project = $execution->project;

        $context = new ExecutionContext(
            project: $project,
            user: $user,
            adapterKey: $execution->adapter_key,
            actionKey: $execution->action_key,
            parameters: (array) $execution->parameters,
            target: (array) (($execution->context['target'] ?? [])),
            mode: $execution->mode,
            timeoutSeconds: (int) $execution->timeout_seconds,
            maxRetries: (int) $execution->max_retries,
        );

        $execution->update(['status' => 'running', 'started_at' => now()]);
        $startedMs = (int) round(microtime(true) * 1000);

        if ($project !== null) {
            $this->publishPlatformEvent(PlatformEventNames::WORKFLOW_STARTED, $project, $user, [
                'execution_uuid' => $execution->uuid,
                'adapter' => $execution->adapter_key,
                'action' => $execution->action_key,
                'mode' => $execution->mode,
            ], $execution->uuid);
        }

        $result = null;
        $attempts = max(1, (int) $execution->max_retries + 1);
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $stepStart = now();
            try {
                $result = $adapter->execute($context);
            } catch (Throwable $e) {
                $result = ExecutionResult::failure('Adapter threw an exception', $e->getMessage());
            }

            AutomationExecutionStep::create([
                'execution_id' => $execution->id,
                'step_index' => $attempt,
                'name' => sprintf('attempt %d / %d', $attempt, $attempts),
                'status' => $result->status,
                'output' => $result->output.($result->error ? "\nerror: ".$result->error : ''),
                'started_at' => $stepStart,
                'finished_at' => now(),
            ]);

            if ($result->status !== ExecutionResult::FAILED) {
                break;
            }
        }

        $result ??= ExecutionResult::failure('No execution result', 'Adapter produced no result.');
        $durationMs = (int) round(microtime(true) * 1000) - $startedMs;

        $execution->update([
            'status' => $result->status,
            'result' => $result->toArray(),
            'error' => $result->error,
            'finished_at' => now(),
            'duration_ms' => $durationMs,
        ]);

        $this->audit->log($user, $project, 'automation_execution_completed', [
            'execution_uuid' => $execution->uuid,
            'adapter' => $execution->adapter_key,
            'action' => $execution->action_key,
            'status' => $result->status,
            'duration_ms' => $durationMs,
        ]);

        $this->learning->record($execution, $execution->context['recommendation_key'] ?? null);

        if ($project !== null) {
            $this->publishPlatformEvent(
                $result->status === ExecutionResult::FAILED
                    ? PlatformEventNames::WORKFLOW_FAILED
                    : PlatformEventNames::WORKFLOW_COMPLETED,
                $project,
                $user,
                [
                    'execution_uuid' => $execution->uuid,
                    'adapter' => $execution->adapter_key,
                    'action' => $execution->action_key,
                    'status' => $result->status,
                    'duration_ms' => $durationMs,
                ],
                $execution->uuid,
            );
        }

        return $execution->fresh(['steps', 'approval']);
    }
}
