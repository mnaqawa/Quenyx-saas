<?php

declare(strict_types=1);

namespace App\Services\Automation;

use App\Models\Automation\AutomationExecution;
use App\Models\Automation\AutomationRunbook;
use App\Models\User;
use InvalidArgumentException;

/**
 * Sprint 23 — the Runbook Engine.
 *
 * A runbook is a named, ordered set of automation steps (manual or AI-assisted). AI-assisted runbooks
 * are always editable drafts and are NEVER auto-executed: an operator must explicitly run them, and
 * live steps still flow through the approval gate. Steps run through the shared {@see ExecutionEngine}.
 */
class RunbookEngine
{
    public function __construct(
        private readonly ExecutionEngine $engine,
        private readonly AutomationAdapterRegistry $adapters,
    ) {}

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    public function normalizeDefinition(array $definition): array
    {
        $steps = $definition['steps'] ?? [];
        if (! is_array($steps) || $steps === []) {
            throw new InvalidArgumentException('A runbook must define at least one step.');
        }

        $normalized = [];
        foreach (array_values($steps) as $index => $step) {
            $adapterKey = (string) ($step['adapter_key'] ?? '');
            if (! $this->adapters->has($adapterKey)) {
                throw new InvalidArgumentException("Unknown execution adapter in step #{$index}: {$adapterKey}.");
            }
            $normalized[] = [
                'name' => (string) ($step['name'] ?? ('Step '.($index + 1))),
                'description' => (string) ($step['description'] ?? ''),
                'adapter_key' => $adapterKey,
                'action_key' => $step['action_key'] ?? null,
                'parameters' => (array) ($step['parameters'] ?? []),
                'target' => (array) ($step['target'] ?? []),
            ];
        }

        return ['steps' => $normalized];
    }

    /**
     * Run a runbook's steps in order. Live steps require approval per step.
     *
     * @param  array<string, mixed>  $options  mode, incident_id
     * @return list<AutomationExecution>
     */
    public function run(AutomationRunbook $runbook, ?User $user, array $options = []): array
    {
        $mode = ($options['mode'] ?? 'dry_run') === 'live' ? 'live' : 'dry_run';
        $executions = [];

        foreach (($runbook->definition['steps'] ?? []) as $step) {
            $executions[] = $this->engine->dispatch($runbook->project, $user, [
                'adapter_key' => $step['adapter_key'],
                'action_key' => $step['action_key'] ?? null,
                'parameters' => $step['parameters'] ?? [],
                'target' => $step['target'] ?? [],
                'mode' => $mode,
                'runbook_id' => $runbook->id,
                'incident_id' => $options['incident_id'] ?? null,
            ]);
        }

        return $executions;
    }
}
