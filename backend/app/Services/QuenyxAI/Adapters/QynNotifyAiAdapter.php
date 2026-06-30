<?php

namespace App\Services\QuenyxAI\Adapters;

use App\Models\Notification\Notification;
use App\Models\Project;

/**
 * QynNotify's adapter into the Quenyx AI Platform (Sprint 24 — Notification Intelligence).
 *
 * Exposes digest/executive summarization and a notification copilot over the workspace's REAL active
 * notifications (urgency, correlation, channels). Reuses the shared Quenyx AI runtime — no duplicated AI
 * logic and no fake routing.
 */
class QynNotifyAiAdapter extends AbstractAiModuleAdapter
{
    public function moduleKey(): string
    {
        return 'qynnotify';
    }

    public function moduleName(): string
    {
        return 'QynNotify';
    }

    public function moduleDescription(): string
    {
        return 'Intelligent notification routing — deterministic deduplication, correlation, urgency scoring, '
            .'recipient/channel selection, escalation, and AI digests/executive summaries over real notifications.';
    }

    public function moduleCategory(): string
    {
        return 'Notifications';
    }

    public function moduleIcon(): string
    {
        return 'bell';
    }

    /**
     * @return list<string>
     */
    public function capabilities(): array
    {
        return [
            'notification_intelligence',
            'notification_digest',
            'notification_copilot',
        ];
    }

    /**
     * @return list<string>
     */
    public function supportedEntities(): array
    {
        return ['workspace', 'notification'];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function buildContext(Project $project, array $options = []): array
    {
        return [
            'module' => $this->moduleKey(),
            'workspace_uuid' => (string) $project->uuid,
            'generated_at' => now()->toIso8601String(),
            'counts' => [
                'active' => Notification::where('project_id', $project->id)->whereIn('status', ['new', 'escalated'])->count(),
                'total' => Notification::where('project_id', $project->id)->count(),
            ],
            'by_severity' => Notification::where('project_id', $project->id)
                ->selectRaw('severity, count(*) as c')->groupBy('severity')->pluck('c', 'severity')->all(),
            'guardrails' => [
                'Use only real active notifications and correlation groups provided in this context.',
                'Recipients and channels are real workspace selections — never invent routing.',
                'Prioritize by deterministic urgency and surface correlated signals together.',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function availableActions(): array
    {
        $base = '/api/qynnotify/intelligence';

        return [
            ['key' => 'digest', 'capability' => 'notification_digest', 'target' => 'workspace', 'label' => 'Generate Digest', 'method' => 'POST', 'endpoint' => "{$base}/digest"],
            ['key' => 'executive_summary', 'capability' => 'notification_intelligence', 'target' => 'workspace', 'label' => 'Executive Summary', 'method' => 'POST', 'endpoint' => "{$base}/executive"],
            ['key' => 'copilot', 'capability' => 'notification_copilot', 'target' => 'workspace', 'label' => 'Ask Quenyx AI', 'method' => 'POST', 'endpoint' => "{$base}/copilot"],
        ];
    }
}
