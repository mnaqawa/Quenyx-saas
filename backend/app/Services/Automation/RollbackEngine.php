<?php

declare(strict_types=1);

namespace App\Services\Automation;

use App\DataTransferObjects\Automation\ExecutionContext;
use App\Models\Automation\AutomationExecution;
use App\Models\Automation\AutomationExecutionStep;
use App\Models\User;
use RuntimeException;
use Throwable;

/**
 * Sprint 23 — the Rollback Engine: undo a previously successful live execution via its adapter.
 *
 * Rollback is itself an adapter capability (resolved through the registry) and is fully audited and
 * recorded for learning. Dry-run executions have nothing to undo.
 */
class RollbackEngine
{
    public function __construct(
        private readonly AutomationAdapterRegistry $adapters,
        private readonly AutomationAuditLogger $audit,
        private readonly AutomationLearningService $learning,
    ) {}

    public function rollback(AutomationExecution $execution, User $user): AutomationExecution
    {
        $adapter = $this->adapters->get($execution->adapter_key);

        if (! $adapter->supportsRollback()) {
            throw new RuntimeException('This execution adapter does not support rollback.');
        }

        if ($execution->status !== 'succeeded') {
            throw new RuntimeException('Only a succeeded live execution can be rolled back.');
        }

        $token = (array) (($execution->result['rollback_token'] ?? []));
        $context = new ExecutionContext(
            project: $execution->project,
            user: $user,
            adapterKey: $execution->adapter_key,
            actionKey: $execution->action_key,
            parameters: (array) $execution->parameters,
            target: (array) (($execution->context['target'] ?? [])),
            mode: $execution->mode,
        );

        $stepStart = now();
        try {
            $result = $adapter->rollback($context, $token);
        } catch (Throwable $e) {
            $result = \App\DataTransferObjects\Automation\ExecutionResult::failure('Rollback threw an exception', $e->getMessage());
        }

        AutomationExecutionStep::create([
            'execution_id' => $execution->id,
            'step_index' => ($execution->steps()->max('step_index') ?? 0) + 1,
            'name' => 'rollback',
            'status' => $result->status,
            'output' => $result->output.($result->error ? "\nerror: ".$result->error : ''),
            'started_at' => $stepStart,
            'finished_at' => now(),
        ]);

        $execution->update(['rolled_back' => true, 'status' => 'rolled_back']);

        $this->audit->log($user, $execution->project, 'automation_execution_rolledback', [
            'execution_uuid' => $execution->uuid,
            'adapter' => $execution->adapter_key,
            'result' => $result->status,
        ]);

        $this->learning->record($execution);

        return $execution->fresh(['steps', 'approval']);
    }
}
