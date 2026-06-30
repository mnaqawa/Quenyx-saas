<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Models\AuditLog;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 24 — shared audit logger for the Knowledge & Collaboration Platform (knowledge, service desk,
 * notifications, collaboration). Records WHO did WHAT in WHICH workspace over the shared `audit_logs`
 * table, mirroring {@see \App\Services\Automation\AutomationAuditLogger}. System-triggered events are
 * logged with a null user. There is no separate audit system per module.
 */
class PlatformAuditLogger
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
