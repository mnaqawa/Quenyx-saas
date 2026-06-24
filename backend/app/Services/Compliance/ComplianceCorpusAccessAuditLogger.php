<?php

namespace App\Services\Compliance;

use App\Models\AuditLog;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

class ComplianceCorpusAccessAuditLogger
{
    public function log(
        User $user,
        Project $project,
        string $endpoint,
        ?string $framework = null,
        ?string $release = null,
    ): void {
        if (! Schema::hasTable('audit_logs')) {
            return;
        }

        AuditLog::create([
            'user_id' => $user->id,
            'project_id' => $project->id,
            'action' => 'compliance_corpus_access',
            'metadata' => array_filter([
                'framework' => $framework,
                'release' => $release,
                'endpoint' => $endpoint,
            ], fn ($value) => $value !== null && $value !== ''),
            'timestamp' => now(),
        ]);
    }

    /**
     * Audit access to the AI Consumption Contract layer (deterministic payload assembly,
     * no AI execution). Captures the requested context type alongside framework/release.
     */
    public function logAiContext(
        User $user,
        Project $project,
        string $contextType,
        string $endpoint,
        ?string $framework = null,
        ?string $release = null,
    ): void {
        if (! Schema::hasTable('audit_logs')) {
            return;
        }

        AuditLog::create([
            'user_id' => $user->id,
            'project_id' => $project->id,
            'action' => 'compliance_ai_context_access',
            'metadata' => array_filter([
                'context_type' => $contextType,
                'framework' => $framework,
                'release' => $release,
                'endpoint' => $endpoint,
            ], fn ($value) => $value !== null && $value !== ''),
            'timestamp' => now(),
        ]);
    }
}
