<?php

namespace App\Services\Compliance\Corpus;

use App\Enums\Compliance\CorpusRevisionStatus;
use App\Models\Compliance\ComplianceCorpusImportRun;
use App\Models\Compliance\ComplianceCorpusRevision;
use App\Models\Compliance\ComplianceFrameworkRelease;

/**
 * Creates corpus revision records after successful imports (architecture preparation).
 *
 * Empty corpus imports (0 domains, 0 controls, 0 requirements) do not create a revision.
 */
class ComplianceCorpusRevisionCreator
{
    /**
     * @param array<string, mixed> $stats
     */
    public function createFromImportRun(
        ComplianceCorpusImportRun $run,
        ComplianceFrameworkRelease $release,
        array $stats,
        string $checksumSha256,
        ?int $createdBy = null,
    ): ?ComplianceCorpusRevision {
        $entityCounts = $this->buildEntityCounts($stats);

        if (! $this->hasCorpusEntities($entityCounts)) {
            return null;
        }

        $previousActive = ComplianceCorpusRevision::query()
            ->where('framework_release_id', $release->id)
            ->where('status', CorpusRevisionStatus::Active)
            ->orderByDesc('revision_number')
            ->first();

        $nextNumber = (int) (ComplianceCorpusRevision::query()
            ->where('framework_release_id', $release->id)
            ->max('revision_number') ?? 0) + 1;

        return ComplianceCorpusRevision::query()->create([
            'framework_release_id' => $release->id,
            'revision_number' => $nextNumber,
            'parent_revision_id' => $previousActive?->id,
            'import_run_id' => $run->id,
            'status' => CorpusRevisionStatus::Active,
            'entity_counts' => $entityCounts,
            'checksum_sha256' => $checksumSha256,
            'created_by' => $createdBy ?? $run->initiated_by,
            'activated_at' => now(),
            'metadata' => [
                'import_type' => $run->import_type?->value ?? null,
                'source_path' => $run->source_path,
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $stats
     */
    public function shouldCreateRevision(array $stats): bool
    {
        return $this->hasCorpusEntities($this->buildEntityCounts($stats));
    }

    /**
     * @param array<string, int> $entityCounts
     */
    private function hasCorpusEntities(array $entityCounts): bool
    {
        return ($entityCounts['domains'] ?? 0) > 0
            || ($entityCounts['controls'] ?? 0) > 0
            || ($entityCounts['requirements'] ?? 0) > 0;
    }

    /**
     * @param array<string, mixed> $stats
     * @return array<string, int>
     */
    private function buildEntityCounts(array $stats): array
    {
        $created = $stats['created'] ?? [];
        $updated = $stats['updated'] ?? [];

        return [
            'domains' => (int) (($created['compliance_domains'] ?? 0) + ($updated['compliance_domains'] ?? 0)),
            'controls' => (int) (($created['compliance_controls'] ?? 0) + ($updated['compliance_controls'] ?? 0)),
            'requirements' => (int) (($created['compliance_requirements'] ?? 0) + ($updated['compliance_requirements'] ?? 0)),
            'guidance_items' => (int) (($created['compliance_guidance_items'] ?? 0) + ($updated['compliance_guidance_items'] ?? 0)),
        ];
    }
}
