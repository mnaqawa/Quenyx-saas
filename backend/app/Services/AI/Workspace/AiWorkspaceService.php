<?php

namespace App\Services\Ai\Workspace;

use App\Enums\Ai\AiCapability;
use App\Models\Ai\AiConversation;
use App\Models\Ai\AiPromptTemplate;
use App\Models\Ai\AiProviderSetting;
use App\Models\AuditLog;
use App\Models\Project;
use App\Services\Ai\AiProviderCatalog;
use App\Services\Ai\AiProviderRegistry;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;

/**
 * Sprint 20 — read-model for the Unified AI Workspace. Every figure is DERIVED from data the
 * platform already records (ai_conversations token counts, ai_conversation_messages, audit_logs):
 * nothing here is fabricated. When a workspace has no AI activity yet, totals read 0 and feeds are
 * empty — the UI renders honest empty states.
 */
class AiWorkspaceService
{
    /** Stable namespace for deriving UUIDs for audit-derived feed items (no numeric IDs exposed). */
    private const FEED_NAMESPACE = '6f9619ff-8b86-d011-b42d-00cf4fc964ff';

    public function __construct(
        private readonly AiCostCalculator $costs,
        private readonly AiProviderRegistry $registry,
        private readonly AiProviderCatalog $catalog,
    ) {}

    /**
     * High-level workspace AI summary card data — enterprise operational metrics derived ONLY from
     * real data (token counts, audit events) and real platform configuration (provider registry +
     * catalog, loaded skills/capabilities). Nothing here is fabricated; when no provider is
     * configured `default_provider` is null and `has_provider` is false so the UI shows an honest
     * "no provider configured" state.
     *
     * @return array<string, mixed>
     */
    public function summary(Project $project): array
    {
        $conversations = AiConversation::query()->where('project_id', $project->id);

        $totals = (clone $conversations)
            ->selectRaw('COUNT(*) as conversation_count')
            ->selectRaw('COALESCE(SUM(message_count),0) as message_count')
            ->selectRaw('COALESCE(SUM(prompt_tokens),0) as prompt_tokens')
            ->selectRaw('COALESCE(SUM(completion_tokens),0) as completion_tokens')
            ->selectRaw('COALESCE(SUM(total_tokens),0) as total_tokens')
            ->first();

        $lastActivity = (clone $conversations)->max('updated_at');

        $defaultKey = $this->registry->defaultKey();
        $savedSettings = AiProviderSetting::query()->where('project_id', $project->id)->get();

        return [
            'ai_enabled' => (bool) config('ai.feature_flags.enabled', false),
            'workspace_enabled' => (bool) config('ai.feature_flags.workspace_enabled', true),
            'has_provider' => $defaultKey !== '',
            'default_provider' => $defaultKey !== '' ? $defaultKey : null,
            'conversation_count' => (int) ($totals->conversation_count ?? 0),
            'message_count' => (int) ($totals->message_count ?? 0),
            'prompt_tokens' => (int) ($totals->prompt_tokens ?? 0),
            'completion_tokens' => (int) ($totals->completion_tokens ?? 0),
            'total_tokens' => (int) ($totals->total_tokens ?? 0),
            'template_count' => AiPromptTemplate::query()->where('project_id', $project->id)->count(),
            // Provider governance counts (real config + workspace state, never fabricated).
            'catalog_provider_count' => count($this->catalog->visibleKeys()),
            'executable_provider_count' => count(array_filter($this->catalog->visibleKeys(), fn (string $k) => $this->registry->has($k))),
            'configured_provider_count' => $savedSettings->filter(fn ($s) => $s->hasSecret())->count(),
            'enabled_provider_count' => $savedSettings->where('enabled', true)->count(),
            // Loaded intelligence (from config — the AI runtime catalog).
            'skills_loaded' => $this->loadedSkillCount(),
            'capabilities_loaded' => count(AiCapability::cases()),
            'pricing_configured' => $this->hasAnyPricing(),
            'last_activity_at' => $lastActivity ? \Illuminate\Support\Carbon::parse($lastActivity)->toIso8601String() : null,
        ];
    }

    /**
     * Count enabled skills registered in the AI runtime config.
     */
    private function loadedSkillCount(): int
    {
        if (! (bool) config('ai.skills.enabled', true)) {
            return 0;
        }

        $registered = (array) config('ai.skills.registered', []);

        return count(array_filter($registered, fn ($s) => (bool) ($s['enabled'] ?? false)));
    }

    /**
     * Whether any provider pricing is configured (drives honest cost display vs token-only mode).
     */
    private function hasAnyPricing(): bool
    {
        foreach ((array) config('ai.workspace.pricing', []) as $pair) {
            if (! empty($pair['prompt']) || ! empty($pair['completion'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Usage breakdown: totals, per-provider, and a daily token series (real data only).
     *
     * @return array<string, mixed>
     */
    public function usage(Project $project): array
    {
        $base = AiConversation::query()->where('project_id', $project->id);

        $byProvider = (clone $base)
            ->select('provider')
            ->selectRaw('COUNT(*) as conversation_count')
            ->selectRaw('COALESCE(SUM(prompt_tokens),0) as prompt_tokens')
            ->selectRaw('COALESCE(SUM(completion_tokens),0) as completion_tokens')
            ->selectRaw('COALESCE(SUM(total_tokens),0) as total_tokens')
            ->groupBy('provider')
            ->orderByDesc('total_tokens')
            ->get()
            ->map(fn ($row) => [
                'provider' => (string) $row->provider,
                'conversation_count' => (int) $row->conversation_count,
                'prompt_tokens' => (int) $row->prompt_tokens,
                'completion_tokens' => (int) $row->completion_tokens,
                'total_tokens' => (int) $row->total_tokens,
            ])
            ->all();

        $daily = (clone $base)
            ->selectRaw('DATE(created_at) as day')
            ->selectRaw('COALESCE(SUM(total_tokens),0) as total_tokens')
            ->selectRaw('COUNT(*) as conversation_count')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->map(fn ($row) => [
                'date' => (string) $row->day,
                'total_tokens' => (int) $row->total_tokens,
                'conversation_count' => (int) $row->conversation_count,
            ])
            ->all();

        return [
            'totals' => [
                'prompt_tokens' => (int) (clone $base)->sum('prompt_tokens'),
                'completion_tokens' => (int) (clone $base)->sum('completion_tokens'),
                'total_tokens' => (int) (clone $base)->sum('total_tokens'),
                'conversation_count' => (int) (clone $base)->count(),
            ],
            'by_provider' => $byProvider,
            'daily' => $daily,
        ];
    }

    /**
     * Cost breakdown derived from real tokens × configured pricing. No pricing ⇒ no fabricated cost.
     *
     * @return array<string, mixed>
     */
    public function costs(Project $project): array
    {
        $byProvider = AiConversation::query()
            ->where('project_id', $project->id)
            ->select('provider')
            ->selectRaw('COALESCE(SUM(prompt_tokens),0) as prompt_tokens')
            ->selectRaw('COALESCE(SUM(completion_tokens),0) as completion_tokens')
            ->groupBy('provider')
            ->get();

        $lines = [];
        $totalCost = 0.0;
        $anyPriced = false;

        foreach ($byProvider as $row) {
            $line = $this->costs->forProvider((string) $row->provider, (int) $row->prompt_tokens, (int) $row->completion_tokens);
            if ($line['pricing_configured'] && $line['cost'] !== null) {
                $anyPriced = true;
                $totalCost += $line['cost'];
            }
            $lines[] = $line;
        }

        return [
            'currency' => (string) config('ai.workspace.currency', 'USD'),
            'pricing_configured' => $anyPriced,
            'total_cost' => $anyPriced ? round($totalCost, 6) : null,
            'by_provider' => $lines,
        ];
    }

    /**
     * Unified AI activity timeline from real audit events (action LIKE "ai%") for this workspace.
     *
     * @return list<array<string, mixed>>
     */
    public function activity(Project $project, int $limit): array
    {
        if (! Schema::hasTable('audit_logs')) {
            return [];
        }

        return AuditLog::query()
            ->where('project_id', $project->id)
            ->where('action', 'like', 'ai%')
            ->orderByDesc('timestamp')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (AuditLog $log) => [
                'uuid' => $this->feedUuid('activity', (int) $log->id),
                'action' => (string) $log->action,
                'actor_id' => $log->user_id ? $this->feedUuid('user', (int) $log->user_id) : null,
                'provider' => $log->metadata['provider'] ?? null,
                'endpoint' => $log->metadata['endpoint'] ?? null,
                'metadata' => $this->safeMetadata((array) ($log->metadata ?? [])),
                'occurred_at' => optional($log->timestamp)->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Notifications derived from significant AI governance events (provider/permission/template
     * changes) — real audit events only, surfaced as actionable items.
     *
     * @return list<array<string, mixed>>
     */
    public function notifications(Project $project, int $limit): array
    {
        if (! Schema::hasTable('audit_logs')) {
            return [];
        }

        $actions = [
            'ai_provider_settings_updated',
            'ai_prompt_template_created',
            'ai_prompt_template_updated',
            'ai_prompt_template_deleted',
            'ai_permissions_updated',
        ];

        return AuditLog::query()
            ->where('project_id', $project->id)
            ->whereIn('action', $actions)
            ->orderByDesc('timestamp')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (AuditLog $log) => [
                'uuid' => $this->feedUuid('notification', (int) $log->id),
                'type' => (string) $log->action,
                'metadata' => $this->safeMetadata((array) ($log->metadata ?? [])),
                'created_at' => optional($log->timestamp)->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Derive a stable UUIDv5 so feed items never expose numeric primary keys.
     */
    private function feedUuid(string $kind, int $id): string
    {
        return Uuid::uuid5(self::FEED_NAMESPACE, "quenyx:ai:{$kind}:{$id}")->toString();
    }

    /**
     * Strip any potentially sensitive keys from audit metadata before exposing it.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function safeMetadata(array $metadata): array
    {
        unset($metadata['api_key'], $metadata['secret'], $metadata['token'], $metadata['organization']);

        return $metadata;
    }
}
