<?php

namespace App\Services\Ai;

use App\Models\AuditLog;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

/**
 * Audit logger for the AI Orchestration Platform. Records WHO accessed WHICH endpoint with
 * WHICH provider — never prompt or response CONTENT. Prompt logging is a separate, off-by
 * default feature handled by the conversation repository.
 */
class AiAccessAuditLogger
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function log(
        ?User $user,
        Project $project,
        string $action,
        string $endpoint,
        string $provider,
        array $metadata = [],
    ): void {
        if ($user === null || ! Schema::hasTable('audit_logs')) {
            return;
        }

        AuditLog::create([
            'user_id' => $user->id,
            'project_id' => $project->id,
            'action' => $action,
            'metadata' => array_filter(array_merge([
                'provider' => $provider,
                'endpoint' => $endpoint,
            ], $metadata), static fn ($value) => $value !== null && $value !== ''),
            'timestamp' => now(),
        ]);
    }
}
