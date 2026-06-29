<?php

namespace App\Services\Ai\Workspace;

use App\Models\AuditLog;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 20 — audit trail for AI workspace governance actions (conversations, provider updates,
 * prompt template changes, permission changes). Records WHO did WHAT, never secret values or
 * message content. Mirrors the shape used by AiAccessAuditLogger / the audit_logs table.
 */
class AiWorkspaceAuditLogger
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(?User $user, Project $project, string $action, array $metadata = []): void
    {
        if ($user === null || ! Schema::hasTable('audit_logs')) {
            return;
        }

        // Never persist secret-like values in the audit trail.
        unset($metadata['api_key'], $metadata['secret'], $metadata['token'], $metadata['organization'], $metadata['settings']);

        AuditLog::create([
            'user_id' => $user->id,
            'project_id' => $project->id,
            'action' => $action,
            'metadata' => array_filter($metadata, static fn ($v) => $v !== null && $v !== ''),
            'timestamp' => now(),
        ]);
    }
}
