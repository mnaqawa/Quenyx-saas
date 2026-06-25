<?php

namespace App\Services\Compliance\Executive;

use App\Models\Compliance\ComplianceCorpusImportRun;
use App\Models\Compliance\ComplianceCorpusRevision;
use App\Models\Compliance\ComplianceFrameworkRelease;
use App\Models\Compliance\Evidence\ComplianceEvidenceLifecycle;
use App\Models\Compliance\Gap\ComplianceGapAssessment;
use App\Models\Compliance\Recommendation\ComplianceRecommendation;
use App\Services\Compliance\Corpus\ComplianceFrameworkReleaseResolver;

/**
 * Executive timeline (QCIF Sprint 18) — chronological, real events only.
 *
 * It assembles a single time-ordered feed from the append-only records already produced by the
 * engine: corpus revisions, imports, evidence lifecycle transitions, gap assessments, and
 * recommendation generations. Nothing is fabricated; every event is a real row. UUID-only.
 */
class ComplianceExecutiveTimelineService
{
    public function __construct(private readonly ComplianceFrameworkReleaseResolver $releaseResolver) {}

    /**
     * @return array<string, mixed>
     */
    public function timeline(?string $frameworkKey, ?string $releaseCode, int $projectId, ?int $limit = null): array
    {
        $limit = $limit ?? (int) config('compliance.executive.timeline_limit', 50);
        $limit = max(1, min(200, $limit));

        $release = $this->resolveRelease($frameworkKey, $releaseCode);
        $releaseId = $release?->id;

        $events = array_merge(
            $this->corpusRevisionEvents($releaseId, $limit),
            $this->importEvents($releaseId, $limit),
            $this->evidenceLifecycleEvents($projectId, $limit),
            $this->gapAssessmentEvents($projectId, $limit),
            $this->recommendationEvents($projectId, $limit),
        );

        usort($events, static function (array $a, array $b): int {
            return strcmp((string) ($b['occurred_at'] ?? ''), (string) ($a['occurred_at'] ?? ''));
        });

        return [
            'context_type' => 'executive_timeline',
            'scope' => [
                'framework_release_uuid' => $release?->uuid,
                'release_code' => $release?->version_code,
                'project_scoped' => true,
            ],
            'count' => min(count($events), $limit),
            'events' => array_slice($events, 0, $limit),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    private function resolveRelease(?string $frameworkKey, ?string $releaseCode): ?ComplianceFrameworkRelease
    {
        if ($frameworkKey === null || $frameworkKey === '' || $releaseCode === null || $releaseCode === '') {
            return null;
        }

        return $this->releaseResolver->resolve($frameworkKey, $releaseCode);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function corpusRevisionEvents(?int $releaseId, int $limit): array
    {
        $query = ComplianceCorpusRevision::query()->with('frameworkRelease.framework')->orderByDesc('id')->limit($limit);
        if ($releaseId !== null) {
            $query->where('framework_release_id', $releaseId);
        }

        return $query->get()->map(fn (ComplianceCorpusRevision $r): array => [
            'type' => 'corpus_revision',
            'uuid' => $r->uuid,
            'occurred_at' => ($r->activated_at ?? $r->created_at)?->toIso8601String(),
            'title_en' => sprintf('Corpus revision #%s (%s)', $r->revision_number, $r->status?->value ?? 'unknown'),
            'title_ar' => sprintf('مراجعة المدونة رقم %s (%s)', $r->revision_number, $r->status?->value ?? 'unknown'),
            'framework' => $r->frameworkRelease?->framework?->key,
            'release_code' => $r->frameworkRelease?->version_code,
            'status' => $r->status?->value,
            'metadata' => ['checksum_sha256' => $r->checksum_sha256, 'entity_counts' => $r->entity_counts],
        ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function importEvents(?int $releaseId, int $limit): array
    {
        $query = ComplianceCorpusImportRun::query()->with('frameworkRelease.framework')->orderByDesc('id')->limit($limit);
        if ($releaseId !== null) {
            $query->where('framework_release_id', $releaseId);
        }

        return $query->get()->map(fn (ComplianceCorpusImportRun $i): array => [
            'type' => 'corpus_import',
            'uuid' => $i->uuid,
            'occurred_at' => ($i->completed_at ?? $i->started_at ?? $i->created_at)?->toIso8601String(),
            'title_en' => sprintf('Corpus import (%s, %s)', $i->import_type?->value ?? 'import', $i->status?->value ?? 'unknown'),
            'title_ar' => sprintf('استيراد المدونة (%s, %s)', $i->import_type?->value ?? 'import', $i->status?->value ?? 'unknown'),
            'framework' => $i->frameworkRelease?->framework?->key,
            'release_code' => $i->frameworkRelease?->version_code,
            'status' => $i->status?->value,
            'metadata' => ['dry_run' => (bool) $i->dry_run, 'format' => $i->format],
        ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function evidenceLifecycleEvents(int $projectId, int $limit): array
    {
        return ComplianceEvidenceLifecycle::query()
            ->whereHas('evidence', fn ($q) => $q->where('project_id', $projectId))
            ->with('evidence:id,uuid')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (ComplianceEvidenceLifecycle $e): array => [
                'type' => 'evidence_lifecycle',
                'uuid' => $e->uuid,
                'occurred_at' => $e->created_at?->toIso8601String(),
                'title_en' => sprintf('Evidence %s → %s', $e->from_status?->value ?? 'new', $e->to_status?->value ?? 'unknown'),
                'title_ar' => sprintf('دليل %s ← %s', $e->from_status?->value ?? 'new', $e->to_status?->value ?? 'unknown'),
                'status' => $e->to_status?->value,
                'metadata' => ['evidence_uuid' => $e->evidence?->uuid, 'reason' => $e->reason],
            ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function gapAssessmentEvents(int $projectId, int $limit): array
    {
        return ComplianceGapAssessment::query()
            ->with('frameworkRelease.framework')
            ->where('project_id', $projectId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(function (ComplianceGapAssessment $a): array {
                $totals = (array) ($a->summary['totals'] ?? []);

                return [
                    'type' => 'gap_assessment',
                    'uuid' => $a->uuid,
                    'occurred_at' => ($a->assessed_at ?? $a->created_at)?->toIso8601String(),
                    'title_en' => sprintf('Gap assessment: %d requirement(s), %d gap(s)', (int) ($totals['requirements'] ?? 0), (int) ($totals['gaps'] ?? 0)),
                    'title_ar' => sprintf('تقييم الفجوات: %d متطلب، %d فجوة', (int) ($totals['requirements'] ?? 0), (int) ($totals['gaps'] ?? 0)),
                    'framework' => $a->frameworkRelease?->framework?->key,
                    'release_code' => $a->frameworkRelease?->version_code,
                    'metadata' => ['totals' => $totals],
                ];
            })->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recommendationEvents(int $projectId, int $limit): array
    {
        // Group persisted recommendations into generation events by their batch timestamp.
        $rows = ComplianceRecommendation::query()
            ->where('project_id', $projectId)
            ->orderByDesc('id')
            ->limit($limit * 20)
            ->get(['uuid', 'created_at', 'priority']);

        $batches = [];
        foreach ($rows as $row) {
            $key = $row->created_at?->toIso8601String() ?? 'unknown';
            $batches[$key] ??= ['count' => 0, 'by_priority' => [], 'sample_uuid' => $row->uuid];
            $batches[$key]['count']++;
            $p = (string) $row->priority;
            $batches[$key]['by_priority'][$p] = ($batches[$key]['by_priority'][$p] ?? 0) + 1;
        }

        $events = [];
        foreach ($batches as $occurredAt => $batch) {
            $events[] = [
                'type' => 'recommendation_generation',
                'uuid' => $batch['sample_uuid'],
                'occurred_at' => $occurredAt === 'unknown' ? null : $occurredAt,
                'title_en' => sprintf('Recommendations generated: %d', $batch['count']),
                'title_ar' => sprintf('توصيات تم إنشاؤها: %d', $batch['count']),
                'metadata' => ['count' => $batch['count'], 'by_priority' => $batch['by_priority']],
            ];
        }

        return array_slice($events, 0, $limit);
    }
}
