<?php

namespace App\Services\Compliance\Mapping;

use App\Enums\Compliance\MappingConfidence;
use App\Exceptions\ComplianceCorpusNotFoundException;
use App\Models\Compliance\ComplianceControl;
use App\Models\Compliance\ComplianceControlObjective;
use App\Models\Compliance\ComplianceControlObjectiveMapping;
use App\Models\Compliance\ComplianceFrameworkRelease;
use Illuminate\Database\Eloquent\Collection;

/**
 * Discovers control ⇄ objective links and their CONFIDENCE BASIS, without any formatting,
 * AI, or fabricated data. Control objectives are global (cross-framework anchors); controls
 * are release-scoped.
 *
 * A control links to an objective via two deterministic paths:
 *  - corpus-native:  controls.control_objective_id (imported from the official source)  → official
 *  - curated mapping: compliance_control_objective_mappings rows                          → confidence_basis (default manual)
 *
 * Derived relationships (controls sharing an objective) are computed by the caller and carry
 * confidence = derived.
 */
class ComplianceControlObjectiveResolver
{
    /**
     * @return Collection<int, ComplianceControlObjective>
     */
    public function allObjectives(): Collection
    {
        return ComplianceControlObjective::query()
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get();
    }

    public function findObjective(string $code): ComplianceControlObjective
    {
        $objective = ComplianceControlObjective::query()
            ->where(function ($query) use ($code): void {
                $query->where('code', $code)->orWhere('slug', $code);
            })
            ->first();

        if ($objective === null) {
            throw new ComplianceCorpusNotFoundException("Control objective not found: {$code}.");
        }

        return $objective;
    }

    /**
     * Controls linked to an objective, with confidence basis. Release-scoped when provided.
     *
     * @return list<array{control: ComplianceControl, confidence: MappingConfidence, mapping_type: ?string, origin: string, mapping: ?ComplianceControlObjectiveMapping}>
     */
    public function controlLinksForObjective(ComplianceControlObjective $objective, ?ComplianceFrameworkRelease $release = null): array
    {
        $links = [];
        $seen = [];

        $nativeQuery = ComplianceControl::query()
            ->where('control_objective_id', $objective->id)
            ->with(['sourceDocument', 'domain']);
        if ($release !== null) {
            $nativeQuery->where('framework_release_id', $release->id);
        }
        foreach ($nativeQuery->orderBy('sort_order')->orderBy('code')->get() as $control) {
            $seen[$control->id] = true;
            $links[] = [
                'control' => $control,
                'confidence' => MappingConfidence::Official,
                'mapping_type' => null,
                'origin' => 'corpus',
                'mapping' => null,
            ];
        }

        $mappingQuery = ComplianceControlObjectiveMapping::query()
            ->where('control_objective_id', $objective->id)
            ->with(['control.sourceDocument', 'control.domain', 'sourceDocument']);
        if ($release !== null) {
            $mappingQuery->whereHas('control', fn ($q) => $q->where('framework_release_id', $release->id));
        }
        foreach ($mappingQuery->get() as $mapping) {
            $control = $mapping->control;
            if ($control === null || isset($seen[$control->id])) {
                continue;
            }
            $seen[$control->id] = true;
            $links[] = [
                'control' => $control,
                'confidence' => $mapping->confidence_basis ?? MappingConfidence::Manual,
                'mapping_type' => $mapping->mapping_type?->value,
                'origin' => 'mapping',
                'mapping' => $mapping,
            ];
        }

        return $links;
    }

    /**
     * Objectives linked to a control, with confidence basis.
     *
     * @return list<array{objective: ComplianceControlObjective, confidence: MappingConfidence, mapping_type: ?string, origin: string, mapping: ?ComplianceControlObjectiveMapping}>
     */
    public function objectiveLinksForControl(ComplianceControl $control): array
    {
        $links = [];
        $seen = [];

        if ($control->control_objective_id !== null) {
            $objective = ComplianceControlObjective::query()->whereKey($control->control_objective_id)->first();
            if ($objective !== null) {
                $seen[$objective->id] = true;
                $links[] = [
                    'objective' => $objective,
                    'confidence' => MappingConfidence::Official,
                    'mapping_type' => null,
                    'origin' => 'corpus',
                    'mapping' => null,
                ];
            }
        }

        $mappings = ComplianceControlObjectiveMapping::query()
            ->where('control_id', $control->id)
            ->with(['controlObjective', 'sourceDocument'])
            ->get();
        foreach ($mappings as $mapping) {
            $objective = $mapping->controlObjective;
            if ($objective === null || isset($seen[$objective->id])) {
                continue;
            }
            $seen[$objective->id] = true;
            $links[] = [
                'objective' => $objective,
                'confidence' => $mapping->confidence_basis ?? MappingConfidence::Manual,
                'mapping_type' => $mapping->mapping_type?->value,
                'origin' => 'mapping',
                'mapping' => $mapping,
            ];
        }

        return $links;
    }

    /**
     * @return list<int>
     */
    public function objectiveIdsForControl(ComplianceControl $control): array
    {
        $ids = [];
        if ($control->control_objective_id !== null) {
            $ids[] = (int) $control->control_objective_id;
        }
        foreach (ComplianceControlObjectiveMapping::query()->where('control_id', $control->id)->pluck('control_objective_id') as $id) {
            $ids[] = (int) $id;
        }

        return array_values(array_unique($ids));
    }

    /**
     * Controls that share at least one objective with the given control (excluding itself).
     * These are DERIVED relationships. Release-scoped when provided.
     *
     * @return list<array{control: ComplianceControl, confidence: MappingConfidence, shared_objectives: list<ComplianceControlObjective>}>
     */
    public function relatedControlLinks(ComplianceControl $control, ?ComplianceFrameworkRelease $release = null): array
    {
        $objectiveIds = $this->objectiveIdsForControl($control);
        if ($objectiveIds === []) {
            return [];
        }

        $objectivesById = ComplianceControlObjective::query()
            ->whereIn('id', $objectiveIds)
            ->get()
            ->keyBy('id');

        // control_id => list of shared objective ids
        $sharedByControl = [];

        $nativeQuery = ComplianceControl::query()
            ->whereIn('control_objective_id', $objectiveIds)
            ->whereKeyNot($control->id)
            ->with(['sourceDocument', 'domain']);
        if ($release !== null) {
            $nativeQuery->where('framework_release_id', $release->id);
        }
        $controlsById = [];
        foreach ($nativeQuery->get() as $candidate) {
            $controlsById[$candidate->id] = $candidate;
            $sharedByControl[$candidate->id][] = (int) $candidate->control_objective_id;
        }

        $mappingQuery = ComplianceControlObjectiveMapping::query()
            ->whereIn('control_objective_id', $objectiveIds)
            ->where('control_id', '!=', $control->id)
            ->with(['control.sourceDocument', 'control.domain']);
        if ($release !== null) {
            $mappingQuery->whereHas('control', fn ($q) => $q->where('framework_release_id', $release->id));
        }
        foreach ($mappingQuery->get() as $mapping) {
            $candidate = $mapping->control;
            if ($candidate === null) {
                continue;
            }
            $controlsById[$candidate->id] = $candidate;
            $sharedByControl[$candidate->id][] = (int) $mapping->control_objective_id;
        }

        $links = [];
        foreach ($controlsById as $controlId => $candidate) {
            $sharedIds = array_values(array_unique($sharedByControl[$controlId] ?? []));
            $sharedObjectives = [];
            foreach ($sharedIds as $objectiveId) {
                if ($objectivesById->has($objectiveId)) {
                    $sharedObjectives[] = $objectivesById->get($objectiveId);
                }
            }
            $links[] = [
                'control' => $candidate,
                'confidence' => MappingConfidence::Derived,
                'shared_objectives' => $sharedObjectives,
            ];
        }

        usort($links, fn ($a, $b) => strcmp((string) $a['control']->code, (string) $b['control']->code));

        return $links;
    }
}
