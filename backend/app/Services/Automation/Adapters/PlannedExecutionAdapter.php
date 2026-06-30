<?php

declare(strict_types=1);

namespace App\Services\Automation\Adapters;

use App\DataTransferObjects\Automation\ExecutionContext;
use App\DataTransferObjects\Automation\ExecutionResult;

/**
 * Sprint 23 — a registry-driven, plan-only adapter used for execution surfaces whose runners are not
 * yet provisioned in this deployment (Docker, Kubernetes, OCI, AWS, Azure, GCP, ...).
 *
 * It demonstrates the platform's openness: each surface is a first-class, discoverable adapter that
 * plugs into the same registry and contract — a real runner is added later by swapping the
 * implementation, with NO change to the engine, registry, or APIs. It always produces a deterministic
 * plan and honestly skips live requests; it NEVER fabricates an execution result.
 */
class PlannedExecutionAdapter extends AbstractExecutionAdapter
{
    /**
     * @param  array<string, mixed>  $schema
     */
    public function __construct(
        private readonly string $key,
        private readonly string $name,
        private readonly string $category,
        private readonly string $description,
        private readonly array $schema = [],
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function category(): string
    {
        return $this->category;
    }

    /**
     * @return array<string, mixed>
     */
    public function parameterSchema(): array
    {
        return $this->schema;
    }

    public function execute(ExecutionContext $context): ExecutionResult
    {
        if ($context->isDryRun()) {
            return ExecutionResult::dryRun(
                sprintf('PLAN: %s action "%s" (dry-run — no %s runner provisioned).', $this->name, (string) $context->actionKey, $this->name),
                ['adapter' => $this->key, 'parameters' => $context->parameters],
            );
        }

        return ExecutionResult::skipped(
            sprintf('Live %s execution is not available: no %s runner is provisioned in this deployment.', $this->name, $this->name),
            ['adapter' => $this->key, 'reason' => 'no_runner'],
        );
    }
}
