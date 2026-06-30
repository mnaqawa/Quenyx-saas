<?php

declare(strict_types=1);

namespace App\Contracts\Automation;

use App\DataTransferObjects\Automation\ExecutionContext;
use App\DataTransferObjects\Automation\ExecutionResult;

/**
 * Sprint 23 — the contract every execution adapter implements (SSH, PowerShell, REST, Webhook,
 * Script, Docker, Kubernetes, OCI, AWS, Azure, GCP, and any future runner).
 *
 * This is the automation analogue of the AI Adapter Platform: adapters register with the
 * {@see \App\Services\Automation\AutomationAdapterRegistry} and the Execution Engine drives them
 * uniformly — there is NO hardcoded execution path and NO duplicated execution logic. Adapters are
 * the ONLY place where a real side effect can occur, and every adapter MUST be safe by default:
 * when {@see ExecutionContext::isDryRun()} is true it returns a deterministic plan and performs no
 * side effect.
 */
interface ExecutionAdapter
{
    /** Stable adapter identifier (e.g. "ssh", "rest", "kubernetes"). */
    public function key(): string;

    /** Human-readable name (e.g. "SSH", "REST API"). */
    public function name(): string;

    /** Short description of what this adapter does. */
    public function description(): string;

    /** Category grouping (e.g. "remote_shell", "http", "container", "cloud", "script"). */
    public function category(): string;

    /**
     * Declared capabilities (machine-readable, e.g. ["dry_run","rollback","retry","timeout"]).
     *
     * @return list<string>
     */
    public function capabilities(): array;

    /** Whether this adapter can undo a successful live action. */
    public function supportsRollback(): bool;

    /**
     * Whether the adapter is actually operational (configured + allowed) for LIVE execution in this
     * deployment. When false, the engine keeps live requests in `skipped` (honest "not configured").
     */
    public function isOperational(): bool;

    /**
     * The parameter schema for UI rendering and validation (declared fields, not values).
     *
     * @return array<string, mixed>
     */
    public function parameterSchema(): array;

    /**
     * PLAN (dry-run) or PERFORM (live) the action. Implementations MUST honor the context mode and
     * MUST NOT throw for expected failures — they return an {@see ExecutionResult} instead.
     */
    public function execute(ExecutionContext $context): ExecutionResult;

    /**
     * Undo a previously successful live action using the stored rollback token.
     *
     * @param  array<string, mixed>  $rollbackToken
     */
    public function rollback(ExecutionContext $context, array $rollbackToken): ExecutionResult;
}
