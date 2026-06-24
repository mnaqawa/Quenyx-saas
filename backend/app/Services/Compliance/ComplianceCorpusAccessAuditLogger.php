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

    /**
     * Audit access to the Knowledge Graph layer (deterministic intra-framework graph
     * navigation, no AI execution). Captures the requested graph context type.
     */
    public function logGraph(
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
            'action' => 'compliance_graph_access',
            'metadata' => array_filter([
                'context_type' => $contextType,
                'framework' => $framework,
                'release' => $release,
                'endpoint' => $endpoint,
            ], fn ($value) => $value !== null && $value !== ''),
            'timestamp' => now(),
        ]);
    }

    /**
     * Audit access to the Cross-Framework Mapping Foundation (deterministic objective-based
     * mappings, no AI execution). Captures the requested mapping context type.
     */
    public function logMapping(
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
            'action' => 'compliance_mapping_access',
            'metadata' => array_filter([
                'context_type' => $contextType,
                'framework' => $framework,
                'release' => $release,
                'endpoint' => $endpoint,
            ], fn ($value) => $value !== null && $value !== ''),
            'timestamp' => now(),
        ]);
    }

    /**
     * Audit access to the Gap Assessment & Evidence Correlation Engine (QCIF Sprint 12). The
     * engine is fully deterministic with NO AI execution. Captures the requested gap context type
     * alongside framework/release. Never logs evidence content — only the access event.
     */
    public function logGap(
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
            'action' => 'compliance_gap_access',
            'metadata' => array_filter([
                'context_type' => $contextType,
                'framework' => $framework,
                'release' => $release,
                'endpoint' => $endpoint,
            ], fn ($value) => $value !== null && $value !== ''),
            'timestamp' => now(),
        ]);
    }

    /**
     * Audit access to the Evidence Intelligence Foundation (read-only tenant evidence context,
     * no AI execution). Captures the requested evidence context type. Never logs evidence
     * content — only the access event.
     */
    public function logEvidence(
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
            'action' => 'compliance_evidence_access',
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
