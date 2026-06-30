<?php

declare(strict_types=1);

namespace App\Services\Automation;

use App\Models\Automation\AutomationExecution;
use App\Models\Automation\AutomationWorkflow;
use App\Models\User;
use InvalidArgumentException;

/**
 * Sprint 23 — the Workflow Engine.
 *
 * A workflow is a data-only definition: trigger → conditions → actions → approval → execution →
 * verification → notification → audit. The engine validates the definition and runs its ordered
 * actions through the shared {@see ExecutionEngine} (so all safety, approval, and audit rules apply
 * uniformly). It runs in dry-run by default; live runs flow through the approval gate per action.
 */
class WorkflowEngine
{
    public function __construct(
        private readonly ExecutionEngine $engine,
        private readonly AutomationAdapterRegistry $adapters,
    ) {}

    /**
     * Validate + normalize a workflow definition. Throws on an invalid definition.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    public function normalizeDefinition(array $definition): array
    {
        $actions = $definition['actions'] ?? [];
        if (! is_array($actions) || $actions === []) {
            throw new InvalidArgumentException('A workflow must define at least one action.');
        }

        $normalizedActions = [];
        foreach (array_values($actions) as $index => $action) {
            $adapterKey = (string) ($action['adapter_key'] ?? '');
            if (! $this->adapters->has($adapterKey)) {
                throw new InvalidArgumentException("Unknown execution adapter in action #{$index}: {$adapterKey}.");
            }
            $normalizedActions[] = [
                'name' => (string) ($action['name'] ?? ('Step '.($index + 1))),
                'adapter_key' => $adapterKey,
                'action_key' => $action['action_key'] ?? null,
                'parameters' => (array) ($action['parameters'] ?? []),
                'target' => (array) ($action['target'] ?? []),
            ];
        }

        return [
            'trigger' => $definition['trigger'] ?? ['type' => 'manual'],
            'conditions' => array_values((array) ($definition['conditions'] ?? [])),
            'actions' => $normalizedActions,
            'verification' => $definition['verification'] ?? null,
            'notification' => $definition['notification'] ?? null,
        ];
    }

    /**
     * Run a workflow's actions in order.
     *
     * @param  array<string, mixed>  $options  mode, incident_id
     * @return list<AutomationExecution>
     */
    public function run(AutomationWorkflow $workflow, ?User $user, array $options = []): array
    {
        $mode = ($options['mode'] ?? 'dry_run') === 'live' ? 'live' : 'dry_run';
        $executions = [];

        foreach (($workflow->definition['actions'] ?? []) as $action) {
            $executions[] = $this->engine->dispatch($workflow->project, $user, [
                'adapter_key' => $action['adapter_key'],
                'action_key' => $action['action_key'] ?? null,
                'parameters' => $action['parameters'] ?? [],
                'target' => $action['target'] ?? [],
                'mode' => $mode,
                'workflow_id' => $workflow->id,
                'incident_id' => $options['incident_id'] ?? null,
            ]);
        }

        return $executions;
    }
}
