<?php

namespace App\Services\Compliance\Gap;

use App\Models\Compliance\ComplianceControl;
use App\Models\Compliance\Evidence\ComplianceEvidence;
use App\Services\Compliance\Evidence\EvidenceNormalizationService;

/**
 * Deterministic Evidence Correlation Engine (QCIF Sprint 12).
 *
 * Builds an in-memory index linking workspace evidence to the corpus entities it satisfies:
 * Requirements, Controls, Domains, Frameworks, and Control Objectives. Supports:
 *   - one evidence → many requirements (via the relationships table)
 *   - many evidence → one requirement
 *   - cross-control / cross-domain aggregation (control-level evidence flows to its requirements)
 *
 * Pure correlation by id membership — NO AI, NO scoring, NO fabrication. A piece of evidence is
 * only correlated where an explicit corpus link exists (primary anchor or relationship row).
 */
class EvidenceCorrelationService
{
    public function __construct(
        private readonly EvidenceNormalizationService $normalization = new EvidenceNormalizationService(),
    ) {}

    /**
     * Build the correlation index for a workspace.
     *
     * @return array{
     *     evidence_by_id: array<int, ComplianceEvidence>,
     *     requirement_evidence: array<int, list<int>>,
     *     control_evidence: array<int, list<int>>,
     *     domain_evidence: array<int, list<int>>,
     *     framework_evidence: array<int, list<int>>,
     *     objective_evidence: array<int, list<int>>,
     *     counts: array<string, int>
     * }
     */
    public function buildIndex(int $projectId): array
    {
        $evidence = $this->normalization->correlationEvidence($projectId);

        $evidenceById = [];
        $requirementEvidence = [];
        $controlEvidence = [];
        $domainEvidence = [];
        $frameworkEvidence = [];

        foreach ($evidence as $record) {
            /** @var ComplianceEvidence $record */
            $evidenceById[$record->id] = $record;

            $this->pushUnique($requirementEvidence, $record->requirement_id, $record->id);
            $this->pushUnique($controlEvidence, $record->control_id, $record->id);

            $relationships = $record->relationLoaded('relationships')
                ? $record->relationships
                : $record->relationships()->get();

            foreach ($relationships as $relationship) {
                $entityId = (int) $relationship->entity_id;
                match ((string) $relationship->entity_type) {
                    'requirement' => $this->pushUnique($requirementEvidence, $entityId, $record->id),
                    'control' => $this->pushUnique($controlEvidence, $entityId, $record->id),
                    'domain' => $this->pushUnique($domainEvidence, $entityId, $record->id),
                    'framework' => $this->pushUnique($frameworkEvidence, $entityId, $record->id),
                    default => null,
                };
            }
        }

        // Control Objective correlation is derived from the controls that have evidence: every
        // control belongs to at most one control objective in the corpus.
        $objectiveEvidence = $this->correlateObjectives($controlEvidence);

        return [
            'evidence_by_id' => $evidenceById,
            'requirement_evidence' => $requirementEvidence,
            'control_evidence' => $controlEvidence,
            'domain_evidence' => $domainEvidence,
            'framework_evidence' => $frameworkEvidence,
            'objective_evidence' => $objectiveEvidence,
            'counts' => [
                'evidence' => count($evidenceById),
                'requirements_with_evidence' => count($requirementEvidence),
                'controls_with_evidence' => count($controlEvidence),
                'domains_with_evidence' => count($domainEvidence),
                'frameworks_with_evidence' => count($frameworkEvidence),
                'objectives_with_evidence' => count($objectiveEvidence),
            ],
        ];
    }

    /**
     * The evidence applicable to a single requirement: evidence linked directly to the
     * requirement PLUS evidence linked to its parent control (control-level evidence satisfies
     * the requirements beneath it — deterministic cross-control aggregation). Each item is tagged
     * with the link origin so findings can explain WHY the evidence was considered.
     *
     * @param  array<string, mixed>  $index
     * @return list<array{evidence: ComplianceEvidence, origin: string}>
     */
    public function applicableEvidenceForRequirement(array $index, int $requirementId, ?int $controlId): array
    {
        $evidenceById = $index['evidence_by_id'];
        $items = [];
        $seen = [];

        foreach ($index['requirement_evidence'][$requirementId] ?? [] as $evidenceId) {
            if (isset($evidenceById[$evidenceId]) && ! isset($seen[$evidenceId])) {
                $seen[$evidenceId] = true;
                $items[] = ['evidence' => $evidenceById[$evidenceId], 'origin' => 'requirement'];
            }
        }

        if ($controlId !== null) {
            foreach ($index['control_evidence'][$controlId] ?? [] as $evidenceId) {
                if (isset($evidenceById[$evidenceId]) && ! isset($seen[$evidenceId])) {
                    $seen[$evidenceId] = true;
                    $items[] = ['evidence' => $evidenceById[$evidenceId], 'origin' => 'control'];
                }
            }
        }

        return $items;
    }

    /**
     * @param  array<int, list<int>>  $controlEvidence
     * @return array<int, list<int>>
     */
    private function correlateObjectives(array $controlEvidence): array
    {
        $controlIds = array_keys($controlEvidence);
        if ($controlIds === []) {
            return [];
        }

        $objectiveByControl = ComplianceControl::query()
            ->whereIn('id', $controlIds)
            ->whereNotNull('control_objective_id')
            ->pluck('control_objective_id', 'id');

        $objectiveEvidence = [];
        foreach ($objectiveByControl as $controlId => $objectiveId) {
            foreach ($controlEvidence[$controlId] ?? [] as $evidenceId) {
                $this->pushUnique($objectiveEvidence, (int) $objectiveId, $evidenceId);
            }
        }

        return $objectiveEvidence;
    }

    /**
     * @param  array<int, list<int>>  $bucket
     */
    private function pushUnique(array &$bucket, ?int $key, int $value): void
    {
        if ($key === null) {
            return;
        }
        if (! isset($bucket[$key])) {
            $bucket[$key] = [];
        }
        if (! in_array($value, $bucket[$key], true)) {
            $bucket[$key][] = $value;
        }
    }
}
