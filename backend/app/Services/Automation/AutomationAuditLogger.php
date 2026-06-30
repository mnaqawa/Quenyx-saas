<?php

declare(strict_types=1);

namespace App\Services\Automation;

use App\Models\AuditLog;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 23 — audit logger for the Automation Platform. Records WHO requested/approved/executed/
 * rolled-back WHICH action in WHICH workspace and the OUTCOME — every automation side effect is
 * traceable. System-triggered executions (scheduled/event) are logged with a null user.
 */
class AutomationAuditLogger
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function log(?User $user, Project $project, string $action, array $metadata = []): void
    {
        if (! Schema::hasTable('audit_logs')) {
            return;
        }

        AuditLog::create([
            'user_id' => $user?->id,
            'project_id' => $project->id,
            'action' => $action,
            'metadata' => array_filter($metadata, static fn ($v) => $v !== null && $v !== ''),
            'timestamp' => now(),
        ]);
    }
}
