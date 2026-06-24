<?php

namespace App\Services\Compliance\Evidence;

use App\Models\Compliance\ComplianceControl;
use App\Models\Compliance\ComplianceDomain;
use App\Models\Compliance\ComplianceFramework;
use App\Models\Compliance\ComplianceRequirement;
use App\Models\Compliance\Evidence\ComplianceEvidence;
use App\Models\Compliance\Evidence\ComplianceEvidenceRelationship;

/**
 * Resolves the corpus relationship chain for a piece of evidence:
 *
 *   Evidence → Requirement → Control → Domain → Framework
 *
 * A single evidence can satisfy MULTIPLE requirements/controls, so this returns one link per
 * relationship (plus the primary control/requirement anchor). Output is UUID-only and derived
 * purely from corpus data — no fabrication, no AI.
 */
class EvidenceRelationshipService
{
    /**
     * @return array<string, mixed>
     */
    public function relationshipsFor(ComplianceEvidence $evidence): array
    {
        $links = [];
        $requirementUuids = [];
        $controlUuids = [];

        foreach ($this->collectTargets($evidence) as $target) {
            $link = $this->buildLink($target['entity_type'], (int) $target['entity_id'], $target['relationship_type']);
            if ($link === null) {
                continue;
            }
            $links[] = $link;

            foreach ($link['chain'] as $node) {
                if ($node['entity_type'] === 'requirement') {
                    $requirementUuids[$node['uuid']] = true;
                } elseif ($node['entity_type'] === 'control') {
                    $controlUuids[$node['uuid']] = true;
                }
            }
        }

        return [
            'links' => $links,
            'counts' => [
                'links' => count($links),
                'requirements' => count($requirementUuids),
                'controls' => count($controlUuids),
            ],
        ];
    }

    /**
     * Merge the primary anchor (control_id / requirement_id) with the relationships table so a
     * single evidence's full set of satisfied entities is represented.
     *
     * @return list<array{entity_type: string, entity_id: int, relationship_type: string}>
     */
    private function collectTargets(ComplianceEvidence $evidence): array
    {
        $targets = [];
        $seen = [];

        $add = function (string $type, ?int $id, string $relType) use (&$targets, &$seen): void {
            if ($id === null) {
                return;
            }
            $key = $type.':'.$id.':'.$relType;
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $targets[] = ['entity_type' => $type, 'entity_id' => $id, 'relationship_type' => $relType];
            }
        };

        $add('requirement', $evidence->requirement_id, 'satisfies');
        $add('control', $evidence->control_id, 'satisfies');

        $relationships = $evidence->relationLoaded('relationships')
            ? $evidence->relationships
            : $evidence->relationships()->get();

        foreach ($relationships as $relationship) {
            /** @var ComplianceEvidenceRelationship $relationship */
            $add((string) $relationship->entity_type, (int) $relationship->entity_id, (string) $relationship->relationship_type);
        }

        return $targets;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildLink(string $entityType, int $entityId, string $relationshipType): ?array
    {
        $chain = match ($entityType) {
            'requirement' => $this->requirementChain($entityId),
            'control' => $this->controlChain($entityId),
            'domain' => $this->domainChain($entityId),
            'framework' => $this->frameworkChain($entityId),
            default => null,
        };

        if ($chain === null || $chain === []) {
            return null;
        }

        return [
            'relationship_type' => $relationshipType,
            'target' => $chain[0],
            'chain' => $chain,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function requirementChain(int $requirementId): array
    {
        $requirement = ComplianceRequirement::query()
            ->with(['control.domain', 'control.framework'])
            ->find($requirementId);

        if ($requirement === null) {
            return [];
        }

        $chain = [$this->node('requirement', $requirement)];
        $control = $requirement->control;
        if ($control !== null) {
            $chain = array_merge($chain, $this->controlUpward($control));
        }

        return $chain;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function controlChain(int $controlId): array
    {
        $control = ComplianceControl::query()->with(['domain', 'framework'])->find($controlId);

        return $control === null ? [] : $this->controlUpward($control);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function controlUpward(ComplianceControl $control): array
    {
        $nodes = [$this->node('control', $control)];

        $domain = $control->domain;
        if ($domain !== null) {
            $nodes[] = $this->node('domain', $domain);
        }

        $framework = $control->framework;
        if ($framework !== null) {
            $nodes[] = $this->node('framework', $framework);
        }

        return $nodes;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function domainChain(int $domainId): array
    {
        $domain = ComplianceDomain::query()->with('framework')->find($domainId);
        if ($domain === null) {
            return [];
        }

        $nodes = [$this->node('domain', $domain)];
        $framework = $domain->framework;
        if ($framework !== null) {
            $nodes[] = $this->node('framework', $framework);
        }

        return $nodes;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function frameworkChain(int $frameworkId): array
    {
        $framework = ComplianceFramework::query()->find($frameworkId);

        return $framework === null ? [] : [$this->node('framework', $framework)];
    }

    /**
     * @return array<string, mixed>
     */
    private function node(string $entityType, ComplianceRequirement|ComplianceControl|ComplianceDomain|ComplianceFramework $entity): array
    {
        return [
            'entity_type' => $entityType,
            'uuid' => $entity->uuid,
            'code' => $entity->code ?? null,
            'title_en' => $entity->title_en ?? null,
            'title_ar' => $entity->title_ar ?? null,
        ];
    }
}
