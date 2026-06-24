<?php

namespace App\Services\Compliance\Gap;

use App\Enums\Compliance\Gap\ComplianceGapStatus;

/**
 * Deterministic coverage aggregation (QCIF Sprint 12).
 *
 * Rolls requirement-level findings upward:  Requirement → Control → Domain → Framework → Workspace.
 * Aggregation is pure counting — totals by status plus a rolled-up status derived by a fixed rule.
 * There is NO score and NO percentage; "coverage" means deterministic counts (e.g. 8 of 10
 * requirements satisfied).
 *
 * Aggregate status rule (given child requirement statuses):
 *   total == 0                 → NotAssessed
 *   satisfied == total         → Compliant
 *   satisfied == 0             → NonCompliant
 *   otherwise                  → PartiallyCompliant
 */
class GapCoverageService
{
    /**
     * @param  list<array<string, mixed>>  $findings  Normalized findings (see GapAssessmentService)
     * @return array{
     *     workspace: array<string, mixed>,
     *     framework: array<string, mixed>|null,
     *     domains: list<array<string, mixed>>,
     *     controls: list<array<string, mixed>>
     * }
     */
    public function aggregate(array $findings, ?array $frameworkNode = null): array
    {
        $byControl = [];
        $byDomain = [];

        foreach ($findings as $finding) {
            $statusValue = $finding['status'] instanceof ComplianceGapStatus
                ? $finding['status']->value
                : (string) $finding['status'];

            $controlUuid = $finding['control']['uuid'] ?? null;
            if ($controlUuid !== null) {
                $byControl[$controlUuid] ??= ['node' => $finding['control'], 'statuses' => []];
                $byControl[$controlUuid]['statuses'][] = $statusValue;
            }

            $domainUuid = $finding['domain']['uuid'] ?? null;
            if ($domainUuid !== null) {
                $byDomain[$domainUuid] ??= ['node' => $finding['domain'], 'statuses' => []];
                $byDomain[$domainUuid]['statuses'][] = $statusValue;
            }
        }

        $controls = [];
        foreach ($byControl as $entry) {
            $controls[] = $this->scopeNode('control', $entry['node'], $entry['statuses']);
        }

        $domains = [];
        foreach ($byDomain as $entry) {
            $domains[] = $this->scopeNode('domain', $entry['node'], $entry['statuses']);
        }

        $allStatuses = array_map(
            fn ($f) => $f['status'] instanceof ComplianceGapStatus ? $f['status']->value : (string) $f['status'],
            $findings,
        );

        $framework = $frameworkNode === null
            ? null
            : $this->scopeNode('framework', $frameworkNode, $allStatuses);

        $workspace = $this->scopeNode('workspace', ['uuid' => null, 'code' => null], $allStatuses);

        return [
            'workspace' => $workspace,
            'framework' => $framework,
            'domains' => $domains,
            'controls' => $controls,
        ];
    }

    /**
     * Build a coverage node for one scope from its child requirement statuses.
     *
     * @param  array<string, mixed>  $node
     * @param  list<string>  $statuses
     * @return array<string, mixed>
     */
    public function scopeNode(string $scopeType, array $node, array $statuses): array
    {
        $total = count($statuses);
        $byStatus = $this->countByStatus($statuses);
        $satisfied = $byStatus[ComplianceGapStatus::Compliant->value] ?? 0;
        $status = $this->aggregateStatus($total, $satisfied);

        return array_merge(
            ['scope' => $scopeType],
            $this->identity($node),
            [
                'status' => $status->value,
                'status_label_en' => $status->labelEn(),
                'status_label_ar' => $status->labelAr(),
                'totals' => [
                    'requirements' => $total,
                    'satisfied' => $satisfied,
                    'by_status' => $byStatus,
                ],
            ],
        );
    }

    public function aggregateStatus(int $total, int $satisfied): ComplianceGapStatus
    {
        if ($total === 0) {
            return ComplianceGapStatus::NotAssessed;
        }
        if ($satisfied >= $total) {
            return ComplianceGapStatus::Compliant;
        }
        if ($satisfied === 0) {
            return ComplianceGapStatus::NonCompliant;
        }

        return ComplianceGapStatus::PartiallyCompliant;
    }

    /**
     * @param  list<string>  $statuses
     * @return array<string, int>
     */
    public function countByStatus(array $statuses): array
    {
        $counts = [];
        foreach (ComplianceGapStatus::values() as $value) {
            $counts[$value] = 0;
        }
        foreach ($statuses as $status) {
            if (! array_key_exists($status, $counts)) {
                $counts[$status] = 0;
            }
            $counts[$status]++;
        }

        return $counts;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function identity(array $node): array
    {
        return [
            'uuid' => $node['uuid'] ?? null,
            'code' => $node['code'] ?? null,
            'title_en' => $node['title_en'] ?? null,
            'title_ar' => $node['title_ar'] ?? null,
        ];
    }
}
