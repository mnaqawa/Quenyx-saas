<?php

namespace App\Http\Controllers;

use App\Models\IntegrationConfiguration;
use App\Models\ObserveAlertEvent;
use App\Models\ObserveAlertRule;
use App\Models\Project;
use App\Services\MonitoringProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class ObserveAlertController extends Controller
{
    public function rules(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        if (! Schema::hasTable('observe_alert_rules')) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $rules = ObserveAlertRule::where('workspace_id', $project->id)
            ->orderByDesc('enabled')
            ->orderBy('name')
            ->get()
            ->map(fn (ObserveAlertRule $r) => $this->formatRule($r));

        return response()->json(['success' => true, 'data' => $rules]);
    }

    public function storeRule(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $validated = $this->validateRulePayload($request);

        $rule = ObserveAlertRule::create(array_merge($validated, [
            'workspace_id' => $project->id,
            'created_by' => $request->user()?->id,
        ]));

        return response()->json(['success' => true, 'data' => $this->formatRule($rule)], 201);
    }

    public function updateRule(Request $request, Project $project, ObserveAlertRule $rule): JsonResponse
    {
        $this->authorize('update', $project);

        if ($rule->workspace_id !== $project->id) {
            return response()->json(['success' => false, 'message' => 'Rule not found'], 404);
        }

        $validated = $this->validateRulePayload($request, partial: true);
        $rule->update($validated);

        return response()->json(['success' => true, 'data' => $this->formatRule($rule->fresh())]);
    }

    public function destroyRule(Project $project, ObserveAlertRule $rule): JsonResponse
    {
        $this->authorize('update', $project);

        if ($rule->workspace_id !== $project->id) {
            return response()->json(['success' => false, 'message' => 'Rule not found'], 404);
        }

        $rule->delete();

        return response()->json(['success' => true, 'data' => ['deleted' => true]]);
    }

    public function toggleRule(Request $request, Project $project, ObserveAlertRule $rule): JsonResponse
    {
        $this->authorize('update', $project);

        if ($rule->workspace_id !== $project->id) {
            return response()->json(['success' => false, 'message' => 'Rule not found'], 404);
        }

        $rule->update(['enabled' => ! $rule->enabled]);

        return response()->json(['success' => true, 'data' => $this->formatRule($rule->fresh())]);
    }

    public function summary(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $activeAlerts = 0;
        $criticalAlerts = 0;
        $acknowledgedAlerts = 0;
        $resolvedToday = 0;
        $rulesTotal = 0;
        $rulesEnabled = 0;
        $channelsActive = 0;
        $channelsTotal = 0;
        $avgResponse = '—';

        if (Schema::hasTable('observe_alert_events')) {
            $base = ObserveAlertEvent::where('workspace_id', $project->id);

            $activeAlerts = (clone $base)
                ->whereIn('status', ['open', 'active'])
                ->count();

            $criticalAlerts = (clone $base)
                ->whereIn('status', ['open', 'active'])
                ->where('severity', 'critical')
                ->count();

            $acknowledgedAlerts = (clone $base)
                ->where('status', 'acknowledged')
                ->count();

            $resolvedToday = (clone $base)
                ->where('status', 'resolved')
                ->where('resolved_at', '>=', now()->startOfDay())
                ->count();

            $resolved = ObserveAlertEvent::where('workspace_id', $project->id)
                ->whereNotNull('resolved_at')
                ->whereNotNull('acknowledged_at')
                ->where('triggered_at', '>=', now()->subDay())
                ->get();

            if ($resolved->isNotEmpty()) {
                $avgMinutes = $resolved->avg(fn ($e) => $e->acknowledged_at->diffInMinutes($e->triggered_at));
                $avgResponse = round($avgMinutes, 1) . ' min';
            }
        }

        if (Schema::hasTable('observe_alert_rules')) {
            $rulesTotal = ObserveAlertRule::where('workspace_id', $project->id)->count();
            $rulesEnabled = ObserveAlertRule::where('workspace_id', $project->id)->where('enabled', true)->count();
        }

        $channels = $this->notificationChannelsList($project);
        $channelsTotal = count($channels);
        $channelsActive = count(array_filter($channels, fn ($c) => $c['configured']));

        return response()->json([
            'success' => true,
            'data' => [
                'activeAlerts' => [
                    'total' => $activeAlerts,
                    'critical' => $criticalAlerts,
                    'warning' => max(0, $activeAlerts - $criticalAlerts),
                ],
                'criticalAlerts' => $criticalAlerts,
                'acknowledgedAlerts' => $acknowledgedAlerts,
                'resolvedToday' => $resolvedToday,
                'alertRules' => [
                    'total' => $rulesTotal,
                    'enabled' => $rulesEnabled,
                ],
                'avgResponseTime' => $avgResponse,
                'notificationChannels' => [
                    'active' => $channelsActive,
                    'total' => $channelsTotal,
                ],
            ],
        ]);
    }

    public function history(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        if (! Schema::hasTable('observe_alert_events')) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $validated = $request->validate([
            'status' => ['nullable', 'string', 'max:32'],
            'severity' => ['nullable', Rule::in(['critical', 'warning'])],
            'target' => ['nullable', 'string', 'max:255'],
            'rule' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $query = ObserveAlertEvent::where('workspace_id', $project->id)
            ->with('rule:id,name')
            ->orderByDesc('triggered_at');

        if (! empty($validated['status'])) {
            $status = $validated['status'] === 'open'
                ? ['open', 'active']
                : [$validated['status']];
            $query->whereIn('status', $status);
        }

        if (! empty($validated['severity'])) {
            $query->where('severity', $validated['severity']);
        }

        if (! empty($validated['target'])) {
            $target = $validated['target'];
            $query->where(function ($q) use ($target) {
                $q->where('host_name', 'like', '%' . $target . '%')
                    ->orWhere('service_name', 'like', '%' . $target . '%');
            });
        }

        if (! empty($validated['rule'])) {
            $query->where('alert_rule_id', (int) $validated['rule']);
        }

        if (! empty($validated['date_from'])) {
            $query->where('triggered_at', '>=', $this->normalizeFilterDateTime($validated['date_from'], endOfDay: false));
        }

        if (! empty($validated['date_to'])) {
            $query->where('triggered_at', '<=', $this->normalizeFilterDateTime($validated['date_to'], endOfDay: true));
        }

        $limit = $validated['limit'] ?? 100;
        $events = $query->limit($limit)->get()->map(fn (ObserveAlertEvent $e) => $this->formatEvent($e));

        return response()->json(['success' => true, 'data' => $events]);
    }

    public function acknowledgeEvent(Request $request, Project $project, ObserveAlertEvent $event): JsonResponse
    {
        $this->authorize('acknowledgeAlert', $project);

        if ($event->workspace_id !== $project->id) {
            return response()->json(['success' => false, 'message' => 'Alert not found'], 404);
        }

        if ($event->status === 'resolved') {
            return response()->json(['success' => false, 'message' => 'Resolved alerts are read-only'], 422);
        }

        if ($event->status === 'acknowledged') {
            return response()->json(['success' => true, 'data' => $this->formatEvent($event)]);
        }

        $event->update([
            'status' => 'acknowledged',
            'acknowledged_at' => now(),
        ]);

        return response()->json(['success' => true, 'data' => $this->formatEvent($event->fresh())]);
    }

    public function channels(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json([
            'success' => true,
            'data' => $this->notificationChannelsList($project),
        ]);
    }

    public function monitoringProfile(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $data = app(MonitoringProfileService::class)->getWorkspaceProfile($project->id);

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function updateMonitoringProfile(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $validated = $request->validate([
            'checks' => ['required', 'array'],
            'checks.*.service_key' => ['required', 'string', 'max:64'],
            'checks.*.check_args' => ['nullable', 'array'],
            'checks.*.enabled' => ['nullable', 'boolean'],
        ]);

        $data = app(MonitoringProfileService::class)->updateWorkspaceProfile(
            $project->id,
            $validated['checks']
        );

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function notificationChannelsList(Project $project): array
    {
        $channels = [];

        if (Schema::hasTable('integration_configurations')) {
            $configs = IntegrationConfiguration::where('project_id', $project->id)->get();
            foreach ($configs as $config) {
                if ($config->slack_webhook_url) {
                    $channels[] = [
                        'id' => 'slack-' . $config->id,
                        'type' => 'slack',
                        'name' => 'Slack Webhook',
                        'configured' => true,
                    ];
                }
                if ($config->primary_webhook_url) {
                    $channels[] = [
                        'id' => 'webhook-primary-' . $config->id,
                        'type' => 'webhook',
                        'name' => 'Primary Webhook',
                        'configured' => true,
                    ];
                }
                if ($config->backup_webhook_url) {
                    $channels[] = [
                        'id' => 'webhook-backup-' . $config->id,
                        'type' => 'webhook',
                        'name' => 'Backup Webhook',
                        'configured' => true,
                    ];
                }
            }
        }

        return $channels;
    }

    private function formatRule(ObserveAlertRule $rule): array
    {
        $condition = $rule->metric_condition . ' ' . $rule->operator . ' ' . $rule->threshold_value;
        if ($rule->duration_seconds > 0) {
            $condition .= ' for ' . $rule->duration_seconds . 's';
        }

        $channels = $rule->notification_channel ? [$rule->notification_channel] : [];

        return [
            'id' => (string) $rule->id,
            'name' => $rule->name,
            'condition' => $condition,
            'enabled' => $rule->enabled,
            'severity' => $rule->severity,
            'notificationChannels' => $channels,
            'lastTriggered' => $rule->last_triggered_at?->toIso8601String() ?? '—',
            'triggerCount7d' => $rule->trigger_count_7d,
            'target_scope' => $rule->target_scope,
            'target_host_id' => $rule->target_host_id,
            'target_service_key' => $rule->target_service_key,
            'metric_condition' => $rule->metric_condition,
            'operator' => $rule->operator,
            'threshold_value' => $rule->threshold_value,
            'duration_seconds' => $rule->duration_seconds,
            'notification_channel' => $rule->notification_channel,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validateRulePayload(Request $request, bool $partial = false): array
    {
        $rules = [
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:160'],
            'severity' => [$partial ? 'sometimes' : 'required', Rule::in(['critical', 'warning'])],
            'target_scope' => [$partial ? 'sometimes' : 'required', Rule::in(['all', 'selected_target', 'selected_service'])],
            'target_host_id' => ['nullable', 'integer'],
            'target_service_key' => ['nullable', 'string', 'max:64'],
            'metric_condition' => [$partial ? 'sometimes' : 'required', 'string', 'max:64'],
            'operator' => [$partial ? 'sometimes' : 'required', Rule::in(['>', '>=', '<', '<=', '=', '==', '!='])],
            'threshold_value' => [$partial ? 'sometimes' : 'required', 'numeric'],
            'duration_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'notification_channel' => ['nullable', 'string', 'max:64'],
            'enabled' => ['nullable', 'boolean'],
        ];

        $validated = $request->validate($rules);

        if (isset($validated['target_scope']) && $validated['target_scope'] === 'selected_target' && empty($validated['target_host_id'])) {
            abort(422, 'target_host_id is required for selected target scope');
        }

        return $validated;
    }

    private function normalizeFilterDateTime(string $value, bool $endOfDay): string
    {
        $trimmed = trim(str_replace('T', ' ', $value));

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed)) {
            return $endOfDay ? $trimmed . ' 23:59:59' : $trimmed . ' 00:00:00';
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $trimmed)) {
            return $trimmed . ':00';
        }

        return $trimmed;
    }

  /**
     * @return array<string, mixed>
     */
    private function formatEvent(ObserveAlertEvent $event): array
    {
        $status = $event->status === 'active' ? 'open' : $event->status;

        return [
            'id' => (string) $event->id,
            'rule_id' => $event->alert_rule_id ? (string) $event->alert_rule_id : null,
            'rule_name' => $event->rule?->name,
            'severity' => $event->severity,
            'title' => $event->title,
            'message' => $event->message,
            'status' => $status,
            'host_name' => $event->host_name,
            'service_name' => $event->service_name,
            'triggered_at' => $event->triggered_at->toIso8601String(),
            'opened_at' => $event->opened_at?->toIso8601String(),
            'acknowledged_at' => $event->acknowledged_at?->toIso8601String(),
            'resolved_at' => $event->resolved_at?->toIso8601String(),
            'last_seen_at' => $event->last_seen_at?->toIso8601String(),
            'occurrence_count' => $event->occurrence_count ?? 1,
            'metadata' => $event->metadata,
        ];
    }
}
