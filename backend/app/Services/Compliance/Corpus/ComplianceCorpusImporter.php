<?php

namespace App\Services\Compliance\Corpus;

use App\Enums\Compliance\ImportLogLevel;
use App\Enums\Compliance\ImportRunStatus;
use App\Enums\Compliance\PublicationStatus;
use App\Models\Compliance\ComplianceControl;
use App\Models\Compliance\ComplianceControlObjective;
use App\Models\Compliance\ComplianceControlObjectiveMapping;
use App\Models\Compliance\ComplianceCorpusImportLog;
use App\Models\Compliance\ComplianceCorpusImportRun;
use App\Models\Compliance\ComplianceDomain;
use App\Models\Compliance\ComplianceEvidenceExpectation;
use App\Models\Compliance\ComplianceEvidenceType;
use App\Models\Compliance\ComplianceFramework;
use App\Models\Compliance\ComplianceGuidanceItem;
use App\Models\Compliance\ComplianceRequirement;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Idempotent importer for human-curated NCA ECC corpus payloads (JSON).
 */
class ComplianceCorpusImporter
{
    public function __construct(
        private readonly ComplianceCorpusValidator $validator = new ComplianceCorpusValidator(),
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function importFromArray(
        array $payload,
        ComplianceFramework $framework,
        ComplianceCorpusImportRun $run,
        bool $dryRun = false,
    ): ComplianceCorpusImportRun {
        $validation = $this->validator->validate($payload, $framework);
        foreach ($validation['warnings'] as $warning) {
            $this->log($run, ImportLogLevel::Warning, null, null, $warning);
        }
        if (! $validation['valid']) {
            foreach ($validation['errors'] as $error) {
                $this->log($run, ImportLogLevel::Error, null, null, $error);
            }
            $run->update([
                'status' => ImportRunStatus::Failed,
                'failure_message' => 'Validation failed',
                'completed_at' => now(),
            ]);
            throw ComplianceCorpusImportException::validationFailed($validation['errors']);
        }

        $run->update([
            'status' => ImportRunStatus::Running,
            'started_at' => now(),
            'framework_id' => $framework->id,
            'content_hash' => ComplianceCorpusValidator::contentHash($payload),
            'dry_run' => $dryRun,
        ]);

        $stats = [
            'created' => [],
            'updated' => [],
            'skipped' => 0,
        ];
        $rollback = [
            'created' => [],
            'updated' => [],
        ];

        try {
            DB::transaction(function () use ($payload, $framework, $run, $dryRun, &$stats, &$rollback): void {
                if (isset($payload['framework']) && is_array($payload['framework'])) {
                    $this->syncFrameworkMetadata($framework, $payload['framework'], $stats, $rollback, $dryRun);
                }

                $objectiveMap = [];
                if (isset($payload['control_objectives']) && is_array($payload['control_objectives'])) {
                    $objectiveMap = $this->importControlObjectives($payload['control_objectives'], $stats, $rollback, $dryRun);
                }

                $controlMap = [];
                if (isset($payload['domains']) && is_array($payload['domains'])) {
                    $controlMap = $this->importDomainsAndControls(
                        $framework,
                        $payload['domains'],
                        $objectiveMap,
                        $stats,
                        $rollback,
                        $dryRun,
                        $run,
                    );
                }

                if (isset($payload['objective_mappings']) && is_array($payload['objective_mappings'])) {
                    $this->importObjectiveMappings($payload['objective_mappings'], $objectiveMap, $controlMap, $stats, $rollback, $dryRun);
                }

                if ($dryRun) {
                    throw new DryRunRollbackException();
                }
            });
        } catch (DryRunRollbackException) {
            $run->update([
                'status' => ImportRunStatus::Completed,
                'completed_at' => now(),
                'stats' => array_merge($stats, ['dry_run' => true]),
            ]);
            $this->log($run, ImportLogLevel::Info, null, null, 'Dry run completed; no changes persisted.');

            return $run->fresh() ?? $run;
        } catch (\Throwable $e) {
            $run->update([
                'status' => ImportRunStatus::Failed,
                'failure_message' => $e->getMessage(),
                'completed_at' => now(),
            ]);
            $this->log($run, ImportLogLevel::Error, null, null, $e->getMessage());
            throw $e;
        }

        $run->update([
            'status' => ImportRunStatus::Completed,
            'completed_at' => now(),
            'stats' => $stats,
            'rollback_data' => $rollback,
        ]);
        $this->log($run, ImportLogLevel::Info, null, null, 'Import completed successfully.');

        return $run->fresh() ?? $run;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, int> $stats
     * @param array<string, mixed> $rollback
     */
    private function syncFrameworkMetadata(
        ComplianceFramework $framework,
        array $data,
        array &$stats,
        array &$rollback,
        bool $dryRun,
    ): void {
        $before = $framework->only(array_keys($data));
        $attributes = $this->filterAttributes($data, [
            'title_en', 'title_ar', 'description_en', 'description_ar',
            'authority', 'authority_en', 'authority_ar',
            'effective_from', 'effective_to', 'status', 'published_at', 'deprecated_at',
            'sort_order', 'source_reference', 'tags', 'migration_reference',
        ]);

        if ($attributes === []) {
            return;
        }

        if (! $dryRun) {
            $framework->fill($attributes);
            $framework->save();
            $this->trackUpdated('compliance_frameworks', $framework->id, $before, $rollback);
        }
        $stats['updated']['compliance_frameworks'] = ($stats['updated']['compliance_frameworks'] ?? 0) + 1;
    }

    /**
     * @param list<array<string, mixed>> $objectives
     * @return array<string, int> code => id
     */
    private function importControlObjectives(array $objectives, array &$stats, array &$rollback, bool $dryRun): array
    {
        $map = [];
        foreach ($objectives as $row) {
            $code = (string) $row['code'];
            $existing = ComplianceControlObjective::query()->where('code', $code)->first();
            $attributes = $this->buildObjectiveAttributes($row);

            if ($existing !== null) {
                if (! $dryRun) {
                    $before = $existing->getAttributes();
                    $existing->fill($attributes);
                    $existing->save();
                    $this->trackUpdated('compliance_control_objectives', $existing->id, $before, $rollback);
                }
                $stats['updated']['compliance_control_objectives'] = ($stats['updated']['compliance_control_objectives'] ?? 0) + 1;
                $map[$code] = $existing->id;
                continue;
            }

            if (! $dryRun) {
                $created = ComplianceControlObjective::query()->create($attributes);
                $this->trackCreated('compliance_control_objectives', $created->id, $rollback);
                $map[$code] = $created->id;
            } else {
                $map[$code] = -1;
            }
            $stats['created']['compliance_control_objectives'] = ($stats['created']['compliance_control_objectives'] ?? 0) + 1;
        }

        return $map;
    }

    /**
     * @param list<array<string, mixed>> $domains
     * @param array<string, int> $objectiveMap
     * @return array<string, int> control_code => id
     */
    private function importDomainsAndControls(
        ComplianceFramework $framework,
        array $domains,
        array $objectiveMap,
        array &$stats,
        array &$rollback,
        bool $dryRun,
        ComplianceCorpusImportRun $run,
    ): array {
        $domainIdByCode = [];
        $controlMap = [];

        foreach ($domains as $domainRow) {
            $domainCode = (string) $domainRow['code'];
            $parentId = null;
            if (filled($domainRow['parent_code'] ?? null)) {
                $parentCode = (string) $domainRow['parent_code'];
                $parentId = $domainIdByCode[$parentCode] ?? null;
                if ($parentId === null) {
                    $this->log($run, ImportLogLevel::Error, 'domain', $domainCode, "Unknown parent_code: {$parentCode}");
                    throw new ComplianceCorpusImportException("Unknown parent domain code: {$parentCode}");
                }
            }

            $domain = ComplianceDomain::query()->firstOrNew([
                'framework_id' => $framework->id,
                'code' => $domainCode,
            ]);

            $isNewDomain = ! $domain->exists;
            $domainAttributes = $this->buildDomainAttributes($domainRow, $framework->id, $parentId);
            if (! $dryRun) {
                if ($domain->exists) {
                    $before = $domain->getAttributes();
                    $domain->fill($domainAttributes);
                    $domain->save();
                    $this->trackUpdated('compliance_domains', $domain->id, $before, $rollback);
                    $stats['updated']['compliance_domains'] = ($stats['updated']['compliance_domains'] ?? 0) + 1;
                } else {
                    $domain->fill($domainAttributes);
                    $domain->save();
                    $this->trackCreated('compliance_domains', $domain->id, $rollback);
                    $stats['created']['compliance_domains'] = ($stats['created']['compliance_domains'] ?? 0) + 1;
                }
                $domainIdByCode[$domainCode] = $domain->id;
            } else {
                $domainIdByCode[$domainCode] = $isNewDomain ? -1 : ($domain->id ?? -1);
            }

            foreach ($domainRow['controls'] ?? [] as $controlRow) {
                $controlCode = (string) $controlRow['code'];
                $control = ComplianceControl::query()->firstOrNew([
                    'framework_id' => $framework->id,
                    'code' => $controlCode,
                ]);

                $objectiveId = null;
                if (filled($controlRow['control_objective_code'] ?? null)) {
                    $objectiveId = $objectiveMap[(string) $controlRow['control_objective_code']] ?? null;
                }

                $controlAttributes = $this->buildControlAttributes(
                    $controlRow,
                    $framework->id,
                    $domainIdByCode[$domainCode],
                    $objectiveId,
                );

                if (! $dryRun) {
                    if ($control->exists) {
                        $before = $control->getAttributes();
                        $control->fill($controlAttributes);
                        $control->save();
                        $this->trackUpdated('compliance_controls', $control->id, $before, $rollback);
                        $stats['updated']['compliance_controls'] = ($stats['updated']['compliance_controls'] ?? 0) + 1;
                    } else {
                        $control->fill($controlAttributes);
                        $control->save();
                        $this->trackCreated('compliance_controls', $control->id, $rollback);
                        $stats['created']['compliance_controls'] = ($stats['created']['compliance_controls'] ?? 0) + 1;
                    }
                    $controlMap[$controlCode] = $control->id;
                    $this->importRequirements($control, $controlRow['requirements'] ?? [], $stats, $rollback, $dryRun);
                } else {
                    $controlMap[$controlCode] = $control->exists ? $control->id : -1;
                }
            }
        }

        return $controlMap;
    }

    /**
     * @param list<array<string, mixed>> $requirements
     */
    private function importRequirements(
        ComplianceControl $control,
        array $requirements,
        array &$stats,
        array &$rollback,
        bool $dryRun,
    ): void {
        foreach ($requirements as $reqRow) {
            $requirement = ComplianceRequirement::query()->firstOrNew([
                'control_id' => $control->id,
                'code' => (string) $reqRow['code'],
            ]);

            $attributes = $this->buildRequirementAttributes($reqRow, $control->id);
            if ($requirement->exists) {
                $before = $requirement->getAttributes();
                $requirement->fill($attributes);
                $requirement->save();
                $this->trackUpdated('compliance_requirements', $requirement->id, $before, $rollback);
                $stats['updated']['compliance_requirements'] = ($stats['updated']['compliance_requirements'] ?? 0) + 1;
            } else {
                $requirement->fill($attributes);
                $requirement->save();
                $this->trackCreated('compliance_requirements', $requirement->id, $rollback);
                $stats['created']['compliance_requirements'] = ($stats['created']['compliance_requirements'] ?? 0) + 1;
            }

            $this->importGuidance($requirement, $reqRow['guidance'] ?? [], $stats, $rollback, $dryRun);
            $this->importEvidenceExpectations($requirement, $reqRow['evidence_expectations'] ?? [], $stats, $rollback, $dryRun);
        }
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function importGuidance(
        ComplianceRequirement $requirement,
        array $items,
        array &$stats,
        array &$rollback,
        bool $dryRun,
    ): void {
        foreach ($items as $row) {
            $item = ComplianceGuidanceItem::query()->firstOrNew([
                'requirement_id' => $requirement->id,
                'code' => (string) $row['code'],
            ]);
            $attributes = [
                'requirement_id' => $requirement->id,
                'code' => (string) $row['code'],
                'slug' => $row['slug'] ?? Str::slug((string) $row['code']),
                'guidance_en' => (string) $row['guidance_en'],
                'guidance_ar' => (string) $row['guidance_ar'],
                'guidance_type' => $row['guidance_type'] ?? 'implementation',
                'status' => $row['status'] ?? PublicationStatus::Draft->value,
                'published_at' => $row['published_at'] ?? null,
                'deprecated_at' => $row['deprecated_at'] ?? null,
                'sort_order' => (int) ($row['sort_order'] ?? 0),
                'source_reference' => $row['source_reference'] ?? null,
                'tags' => $row['tags'] ?? null,
            ];
            $this->persistChild($item, $attributes, 'compliance_guidance_items', $stats, $rollback);
        }
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function importEvidenceExpectations(
        ComplianceRequirement $requirement,
        array $items,
        array &$stats,
        array &$rollback,
        bool $dryRun,
    ): void {
        foreach ($items as $row) {
            $type = ComplianceEvidenceType::query()->where('key', (string) $row['evidence_type_key'])->first();
            if ($type === null) {
                throw new ComplianceCorpusImportException(
                    "Unknown evidence_type_key: {$row['evidence_type_key']}"
                );
            }

            $item = ComplianceEvidenceExpectation::query()->firstOrNew([
                'requirement_id' => $requirement->id,
                'code' => (string) $row['code'],
            ]);

            $attributes = [
                'requirement_id' => $requirement->id,
                'evidence_type_id' => $type->id,
                'code' => (string) $row['code'],
                'slug' => $row['slug'] ?? Str::slug((string) $row['code']),
                'title_en' => $row['title_en'] ?? null,
                'title_ar' => $row['title_ar'] ?? null,
                'description_en' => $row['description_en'] ?? null,
                'description_ar' => $row['description_ar'] ?? null,
                'is_required' => (bool) ($row['is_required'] ?? true),
                'recency_days' => isset($row['recency_days']) ? (int) $row['recency_days'] : null,
                'status' => $row['status'] ?? PublicationStatus::Draft->value,
                'published_at' => $row['published_at'] ?? null,
                'deprecated_at' => $row['deprecated_at'] ?? null,
                'sort_order' => (int) ($row['sort_order'] ?? 0),
                'source_reference' => $row['source_reference'] ?? null,
                'tags' => $row['tags'] ?? null,
            ];
            $this->persistChild($item, $attributes, 'compliance_evidence_expectations', $stats, $rollback);
        }
    }

    /**
     * @param list<array<string, mixed>> $mappings
     * @param array<string, int> $objectiveMap
     * @param array<string, int> $controlMap
     */
    private function importObjectiveMappings(
        array $mappings,
        array $objectiveMap,
        array $controlMap,
        array &$stats,
        array &$rollback,
        bool $dryRun,
    ): void {
        foreach ($mappings as $row) {
            $objectiveId = $objectiveMap[(string) $row['control_objective_code']] ?? null;
            $controlId = $controlMap[(string) $row['control_code']] ?? null;
            if ($objectiveId === null || $controlId === null || $objectiveId < 1 || $controlId < 1) {
                throw new ComplianceCorpusImportException(
                    'Objective mapping references unknown control_objective_code or control_code.'
                );
            }

            $mapping = ComplianceControlObjectiveMapping::query()->firstOrNew([
                'control_objective_id' => $objectiveId,
                'control_id' => $controlId,
            ]);

            $attributes = [
                'control_objective_id' => $objectiveId,
                'control_id' => $controlId,
                'mapping_type' => $row['mapping_type'] ?? 'related',
                'confidence' => $row['confidence'] ?? 'high',
                'notes_en' => $row['notes_en'] ?? null,
                'notes_ar' => $row['notes_ar'] ?? null,
                'status' => $row['status'] ?? PublicationStatus::Draft->value,
                'published_at' => $row['published_at'] ?? null,
                'source_reference' => $row['source_reference'] ?? null,
            ];
            $this->persistChild($mapping, $attributes, 'compliance_control_objective_mappings', $stats, $rollback);
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function buildObjectiveAttributes(array $row): array
    {
        return [
            'code' => (string) $row['code'],
            'slug' => $row['slug'] ?? Str::slug((string) $row['code']),
            'title_en' => (string) $row['title_en'],
            'title_ar' => (string) $row['title_ar'],
            'description_en' => $row['description_en'] ?? null,
            'description_ar' => $row['description_ar'] ?? null,
            'category_en' => $row['category_en'] ?? null,
            'category_ar' => $row['category_ar'] ?? null,
            'status' => $row['status'] ?? PublicationStatus::Draft->value,
            'published_at' => $row['published_at'] ?? null,
            'deprecated_at' => $row['deprecated_at'] ?? null,
            'sort_order' => (int) ($row['sort_order'] ?? 0),
            'source_reference' => $row['source_reference'] ?? null,
            'tags' => $row['tags'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function buildDomainAttributes(array $row, int $frameworkId, ?int $parentId): array
    {
        return [
            'framework_id' => $frameworkId,
            'parent_domain_id' => $parentId,
            'code' => (string) $row['code'],
            'slug' => $row['slug'] ?? Str::slug((string) $row['code']),
            'title_en' => (string) $row['title_en'],
            'title_ar' => (string) $row['title_ar'],
            'description_en' => $row['description_en'] ?? null,
            'description_ar' => $row['description_ar'] ?? null,
            'status' => $row['status'] ?? PublicationStatus::Draft->value,
            'published_at' => $row['published_at'] ?? null,
            'deprecated_at' => $row['deprecated_at'] ?? null,
            'sort_order' => (int) ($row['sort_order'] ?? 0),
            'source_reference' => $row['source_reference'] ?? null,
            'tags' => $row['tags'] ?? null,
            'migration_reference' => $row['migration_reference'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function buildControlAttributes(array $row, int $frameworkId, int $domainId, ?int $objectiveId): array
    {
        return [
            'framework_id' => $frameworkId,
            'domain_id' => $domainId,
            'control_objective_id' => $objectiveId,
            'code' => (string) $row['code'],
            'slug' => $row['slug'] ?? Str::slug(str_replace('.', '-', (string) $row['code'])),
            'title_en' => (string) $row['title_en'],
            'title_ar' => (string) $row['title_ar'],
            'description_en' => $row['description_en'] ?? null,
            'description_ar' => $row['description_ar'] ?? null,
            'control_type' => $row['control_type'] ?? null,
            'status' => $row['status'] ?? PublicationStatus::Draft->value,
            'published_at' => $row['published_at'] ?? null,
            'deprecated_at' => $row['deprecated_at'] ?? null,
            'sort_order' => (int) ($row['sort_order'] ?? 0),
            'source_reference' => $row['source_reference'] ?? null,
            'tags' => $row['tags'] ?? null,
            'migration_reference' => $row['migration_reference'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function buildRequirementAttributes(array $row, int $controlId): array
    {
        return [
            'control_id' => $controlId,
            'code' => (string) $row['code'],
            'slug' => $row['slug'] ?? Str::slug((string) $row['code']),
            'title_en' => (string) $row['title_en'],
            'title_ar' => (string) $row['title_ar'],
            'description_en' => $row['description_en'] ?? null,
            'description_ar' => $row['description_ar'] ?? null,
            'requirement_text_en' => (string) $row['requirement_text_en'],
            'requirement_text_ar' => (string) $row['requirement_text_ar'],
            'status' => $row['status'] ?? PublicationStatus::Draft->value,
            'published_at' => $row['published_at'] ?? null,
            'deprecated_at' => $row['deprecated_at'] ?? null,
            'sort_order' => (int) ($row['sort_order'] ?? 0),
            'source_reference' => $row['source_reference'] ?? null,
            'tags' => $row['tags'] ?? null,
            'migration_reference' => $row['migration_reference'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function persistChild(
        ComplianceGuidanceItem|ComplianceEvidenceExpectation|ComplianceControlObjectiveMapping $model,
        array $attributes,
        string $table,
        array &$stats,
        array &$rollback,
    ): void {
        if ($model->exists) {
            $before = $model->getAttributes();
            $model->fill($attributes);
            $model->save();
            $this->trackUpdated($table, $model->id, $before, $rollback);
            $stats['updated'][$table] = ($stats['updated'][$table] ?? 0) + 1;
        } else {
            $model->fill($attributes);
            $model->save();
            $this->trackCreated($table, $model->id, $rollback);
            $stats['created'][$table] = ($stats['created'][$table] ?? 0) + 1;
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $allowed
     * @return array<string, mixed>
     */
    private function filterAttributes(array $data, array $allowed): array
    {
        return Arr::only($data, $allowed);
    }

    private function trackCreated(string $table, int $id, array &$rollback): void
    {
        $rollback['created'][$table] ??= [];
        $rollback['created'][$table][] = $id;
    }

    /**
     * @param array<string, mixed> $before
     */
    private function trackUpdated(string $table, int $id, array $before, array &$rollback): void
    {
        $rollback['updated'][$table] ??= [];
        $rollback['updated'][$table][] = ['id' => $id, 'before' => $before];
    }

    private function log(
        ComplianceCorpusImportRun $run,
        ImportLogLevel $level,
        ?string $entityType,
        ?string $entityKey,
        string $message,
        ?int $rowNumber = null,
        ?array $payload = null,
    ): void {
        ComplianceCorpusImportLog::query()->create([
            'import_run_id' => $run->id,
            'level' => $level,
            'entity_type' => $entityType,
            'entity_key' => $entityKey,
            'message' => $message,
            'row_number' => $rowNumber,
            'payload' => $payload,
            'created_at' => now(),
        ]);
    }

    public function rollback(ComplianceCorpusImportRun $run): void
    {
        if ($run->status !== ImportRunStatus::Completed || $run->dry_run) {
            throw new ComplianceCorpusImportException('Only completed non-dry-run imports can be rolled back.');
        }

        $rollback = $run->rollback_data ?? [];
        DB::transaction(function () use ($rollback, $run): void {
            foreach ($rollback['updated'] ?? [] as $table => $entries) {
                foreach ($entries as $entry) {
                    $this->restoreRow($table, $entry['id'], $entry['before']);
                }
            }

            $deleteOrder = [
                'compliance_control_objective_mappings',
                'compliance_evidence_expectations',
                'compliance_guidance_items',
                'compliance_requirements',
                'compliance_controls',
                'compliance_domains',
                'compliance_control_objectives',
            ];

            foreach ($deleteOrder as $table) {
                foreach ($rollback['created'][$table] ?? [] as $id) {
                    DB::table($table)->where('id', $id)->delete();
                }
            }

            $run->update(['status' => ImportRunStatus::RolledBack]);
        });
    }

    /**
     * @param array<string, mixed> $before
     */
    private function restoreRow(string $table, int $id, array $before): void
    {
        unset($before['id'], $before['uuid'], $before['created_at'], $before['updated_at']);
        DB::table($table)->where('id', $id)->update($before);
    }
}

/**
 * Internal sentinel to roll back dry-run transactions.
 */
class DryRunRollbackException extends RuntimeException
{
}
