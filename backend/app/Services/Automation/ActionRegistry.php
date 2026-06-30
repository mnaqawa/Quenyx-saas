<?php

declare(strict_types=1);

namespace App\Services\Automation;

use InvalidArgumentException;

/**
 * Sprint 23 — the Action Registry: a catalog of reusable, named automation actions.
 *
 * An action binds a stable key (e.g. "restart_service") to an execution adapter and a parameter
 * schema, and declares whether it is destructive (requires approval) and rollbackable. Workflows and
 * runbooks reference actions by key; the engine resolves the adapter through the
 * {@see AutomationAdapterRegistry}. Seeded from `config('automation.actions')` and extensible at
 * runtime — no hardcoded action list in the engine.
 */
class ActionRegistry
{
    /** @var array<string, array<string, mixed>> */
    private array $actions = [];

    /**
     * @param  list<array<string, mixed>>  $seed
     */
    public function __construct(array $seed = [])
    {
        foreach ($seed as $action) {
            if (isset($action['key'])) {
                $this->register($action);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $action
     */
    public function register(array $action): void
    {
        $key = (string) $action['key'];
        $this->actions[$key] = array_merge([
            'key' => $key,
            'label' => $key,
            'description' => '',
            'adapter_key' => 'script',
            'category' => 'general',
            'destructive' => false,
            'supports_rollback' => false,
            'parameter_schema' => [],
        ], $action);
    }

    public function has(string $key): bool
    {
        return isset($this->actions[$key]);
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $key): array
    {
        if (! isset($this->actions[$key])) {
            throw new InvalidArgumentException("No automation action registered for key: {$key}.");
        }

        return $this->actions[$key];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return array_values($this->actions);
    }

    public function isDestructive(string $key): bool
    {
        return $this->has($key) ? (bool) $this->actions[$key]['destructive'] : false;
    }
}
