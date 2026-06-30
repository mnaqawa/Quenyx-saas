<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Models\Notification\Notification;
use App\Models\Project;
use App\Models\User;
use App\Services\Platform\PlatformAuditLogger;
use Illuminate\Support\Carbon;

/**
 * Sprint 24 — intelligent notification routing (QynNotify).
 *
 * Deterministic, auditable, and honest. Ingestion performs real deduplication (collapsing duplicates by
 * a deterministic `dedup_key` inside a time window), correlation (grouping by `correlation_id`), urgency
 * scoring (severity weight + recency), recipient selection (real workspace members by role), channel
 * selection, and an escalation path — all computed from real data. There is NO fake routing: recipients
 * are only ever real workspace members, and no message is actually dispatched here (channels are recorded
 * intent until a real transport is connected).
 */
class NotificationService
{
    public function __construct(
        private readonly PlatformAuditLogger $audit,
    ) {}

    /**
     * Ingest a signal. Returns the (possibly deduplicated) notification.
     *
     * @param  array<string, mixed>  $data  type, severity, title, body, source, correlation_id
     */
    public function ingest(Project $project, ?User $user, array $data): Notification
    {
        $severity = (string) ($data['severity'] ?? 'info');
        $type = (string) ($data['type'] ?? 'event');
        $source = (string) ($data['source'] ?? 'platform');
        $title = (string) $data['title'];

        $dedupKey = $this->dedupKey($type, $source, $title);
        $window = (int) config('knowledge.notifications.correlation_window_minutes', 30);

        // Deduplicate: collapse into an active, recent notification with the same dedup key.
        $existing = Notification::where('project_id', $project->id)
            ->where('dedup_key', $dedupKey)
            ->whereIn('status', ['new', 'escalated'])
            ->where('created_at', '>=', now()->subMinutes($window))
            ->latest()
            ->first();

        if ($existing !== null) {
            $existing->dedup_count = (int) $existing->dedup_count + 1;
            $existing->urgency_score = $this->urgency($severity, $existing->created_at, (int) $existing->dedup_count);
            $existing->save();
            $this->audit->log($user, $project, 'notification_deduplicated', ['uuid' => $existing->uuid, 'dedup_count' => $existing->dedup_count]);

            return $existing;
        }

        $recipients = $this->selectRecipients($project, $severity);
        $notification = Notification::create([
            'project_id' => $project->id,
            'type' => $type,
            'severity' => $severity,
            'title' => $title,
            'body' => $data['body'] ?? null,
            'source' => $source,
            'dedup_key' => $dedupKey,
            'correlation_id' => $data['correlation_id'] ?? $this->correlationId($type, $source),
            'urgency_score' => $this->urgency($severity, now(), 1),
            'dedup_count' => 1,
            'channel' => $this->selectChannel($severity),
            'status' => 'new',
            'recipients' => $recipients,
            'escalation' => $this->escalationPath($severity, $recipients),
            'metadata' => $data['metadata'] ?? [],
        ]);

        $this->audit->log($user, $project, 'notification_created', [
            'uuid' => $notification->uuid, 'severity' => $severity, 'channel' => $notification->channel, 'recipients' => count($recipients),
        ]);

        return $notification;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function list(Project $project, array $filters = []): array
    {
        return Notification::where('project_id', $project->id)
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(! empty($filters['severity']), fn ($q) => $q->where('severity', $filters['severity']))
            ->orderByDesc('urgency_score')->orderByDesc('created_at')
            ->limit((int) ($filters['limit'] ?? 100))
            ->get()
            ->map(fn (Notification $n): array => $this->summary($n))
            ->all();
    }

    public function find(Project $project, string $uuid): ?Notification
    {
        return Notification::where('project_id', $project->id)->where('uuid', $uuid)->first();
    }

    public function markRead(Project $project, ?User $user, Notification $notification): Notification
    {
        $notification->status = 'read';
        $notification->read_at = now();
        $notification->save();
        $this->audit->log($user, $project, 'notification_read', ['uuid' => $notification->uuid]);

        return $notification;
    }

    /**
     * Correlation groups for the current active notifications — deterministic clustering for the UI.
     *
     * @return list<array<string, mixed>>
     */
    public function correlations(Project $project): array
    {
        $rows = Notification::where('project_id', $project->id)
            ->whereIn('status', ['new', 'escalated'])
            ->get(['correlation_id', 'severity', 'urgency_score']);

        $groups = [];
        foreach ($rows as $row) {
            $cid = (string) ($row->correlation_id ?? 'ungrouped');
            $groups[$cid] ??= ['correlation_id' => $cid, 'count' => 0, 'max_urgency' => 0];
            $groups[$cid]['count']++;
            $groups[$cid]['max_urgency'] = max($groups[$cid]['max_urgency'], (int) $row->urgency_score);
        }

        usort($groups, static fn (array $a, array $b): int => $b['max_urgency'] <=> $a['max_urgency']);

        return array_values($groups);
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(Notification $n): array
    {
        return [
            'uuid' => $n->uuid,
            'type' => $n->type,
            'severity' => $n->severity,
            'title' => $n->title,
            'source' => $n->source,
            'urgency_score' => (int) $n->urgency_score,
            'dedup_count' => (int) $n->dedup_count,
            'channel' => $n->channel,
            'status' => $n->status,
            'correlation_id' => $n->correlation_id,
            'recipients' => (array) ($n->recipients ?? []),
            'created_at' => optional($n->created_at)->toIso8601String(),
        ];
    }

    private function dedupKey(string $type, string $source, string $title): string
    {
        return substr(hash('sha256', strtolower($type.'|'.$source.'|'.trim($title))), 0, 48);
    }

    private function correlationId(string $type, string $source): string
    {
        return substr(hash('sha256', strtolower($source.'|'.$type)), 0, 32);
    }

    /**
     * Deterministic urgency 0-100: severity weight, slightly reduced by age, slightly raised by repeats.
     */
    private function urgency(string $severity, mixed $createdAt, int $dedupCount): int
    {
        $weights = (array) config('knowledge.notifications.severity_weight', []);
        $base = (int) ($weights[$severity] ?? 10);
        $ageMinutes = $createdAt instanceof Carbon ? max(0, now()->diffInMinutes($createdAt)) : 0;
        $decay = min(15, (int) floor($ageMinutes / 60) * 2); // up to -15 over time
        $repeatBoost = min(10, ($dedupCount - 1) * 2);

        return max(0, min(100, $base - $decay + $repeatBoost));
    }

    private function selectChannel(string $severity): string
    {
        return match ($severity) {
            'critical' => 'sms',
            'high' => 'email',
            default => 'in_app',
        };
    }

    /**
     * Real workspace members chosen by severity (owners/admins escalate first). Returns user UUIDs.
     *
     * @return list<array<string, mixed>>
     */
    private function selectRecipients(Project $project, string $severity): array
    {
        $memberships = $project->memberships()->with('user')->get();
        $selected = [];
        foreach ($memberships as $membership) {
            $member = $membership->user;
            if ($member === null) {
                continue;
            }
            $role = (string) ($membership->role ?? 'member');
            $isPrivileged = in_array($role, ['owner', 'admin'], true);
            // Critical/high → privileged members; medium/low/info → all members.
            if (in_array($severity, ['critical', 'high'], true) && ! $isPrivileged) {
                continue;
            }
            $selected[] = ['uuid' => $member->uuid, 'name' => $member->name, 'role' => $role];
        }

        // Always include the owner for critical even if no privileged members matched.
        if ($selected === [] && $project->owner) {
            $selected[] = ['uuid' => $project->owner->uuid, 'name' => $project->owner->name, 'role' => 'owner'];
        }

        return $selected;
    }

    /**
     * @param  list<array<string, mixed>>  $recipients
     * @return list<array<string, mixed>>
     */
    private function escalationPath(string $severity, array $recipients): array
    {
        if (! in_array($severity, ['critical', 'high'], true)) {
            return [];
        }

        $steps = [];
        $delay = $severity === 'critical' ? 5 : 15;
        foreach ($recipients as $i => $recipient) {
            $steps[] = [
                'order' => $i + 1,
                'after_minutes' => $delay * ($i + 1),
                'recipient' => $recipient,
            ];
        }

        return $steps;
    }
}
