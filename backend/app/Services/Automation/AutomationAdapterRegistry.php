<?php

declare(strict_types=1);

namespace App\Services\Automation;

use App\Contracts\Automation\ExecutionAdapter;
use InvalidArgumentException;

/**
 * Sprint 23 — the Automation Registry: dynamic registration and discovery of execution adapters.
 *
 * The Execution Engine resolves adapters ONLY through this registry — there is no hardcoded execution
 * path. Future runners (Docker, Kubernetes, cloud, ...) become available by registering one adapter,
 * exactly like the AI Adapter Platform. Process-wide singleton bound in AppServiceProvider.
 */
class AutomationAdapterRegistry
{
    /** @var array<string, ExecutionAdapter> */
    private array $adapters = [];

    public function register(ExecutionAdapter $adapter): void
    {
        $this->adapters[$adapter->key()] = $adapter;
    }

    public function has(string $key): bool
    {
        return isset($this->adapters[$key]);
    }

    public function get(string $key): ExecutionAdapter
    {
        if (! isset($this->adapters[$key])) {
            throw new InvalidArgumentException("No execution adapter registered for key: {$key}.");
        }

        return $this->adapters[$key];
    }

    /**
     * @return array<string, ExecutionAdapter>
     */
    public function all(): array
    {
        return $this->adapters;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->adapters);
    }

    /**
     * @return array<string, mixed>
     */
    public function describe(ExecutionAdapter $adapter): array
    {
        return [
            'key' => $adapter->key(),
            'name' => $adapter->name(),
            'description' => $adapter->description(),
            'category' => $adapter->category(),
            'capabilities' => $adapter->capabilities(),
            'supports_rollback' => $adapter->supportsRollback(),
            'operational' => $adapter->isOperational(),
            'parameter_schema' => $adapter->parameterSchema(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function describeAll(): array
    {
        return array_values(array_map(fn (ExecutionAdapter $a): array => $this->describe($a), $this->adapters));
    }
}
