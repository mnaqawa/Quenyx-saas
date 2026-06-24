<?php

namespace App\Services\Compliance\Gap;

use App\Enums\Compliance\Gap\ComplianceGapSeverity;
use App\Enums\Compliance\Gap\ComplianceGapStatus;

/**
 * Builds the deterministic workspace gap summary (QCIF Sprint 12): requirement counts by status
 * and by severity, plus the rolled-up workspace/framework status from the coverage tree. Pure
 * counting — no scores, no AI.
 */
class GapSummaryService
{
    /**
     * @param  list<array<string, mixed>>  $findings
     * @param  array<string, mixed>  $coverage
     * @return array<string, mixed>
     */
    public function summarize(array $findings, array $coverage): array
    {
        $byStatus = $this->zeroed(ComplianceGapStatus::values());
        $bySeverity = $this->zeroed(ComplianceGapSeverity::values());

        foreach ($findings as $finding) {
            $statusValue = $finding['status'] instanceof ComplianceGapStatus
                ? $finding['status']->value
                : (string) $finding['status'];
            $severityValue = $finding['severity'] instanceof ComplianceGapSeverity
                ? $finding['severity']->value
                : (string) $finding['severity'];

            $byStatus[$statusValue] = ($byStatus[$statusValue] ?? 0) + 1;
            $bySeverity[$severityValue] = ($bySeverity[$severityValue] ?? 0) + 1;
        }

        $total = count($findings);
        $satisfied = $byStatus[ComplianceGapStatus::Compliant->value] ?? 0;

        return [
            'totals' => [
                'requirements' => $total,
                'satisfied' => $satisfied,
                'gaps' => $total - $satisfied,
                'by_status' => $byStatus,
                'by_severity' => $bySeverity,
            ],
            'workspace_status' => $coverage['workspace']['status'] ?? ComplianceGapStatus::NotAssessed->value,
            'framework_status' => $coverage['framework']['status'] ?? null,
            'domain_count' => count($coverage['domains'] ?? []),
            'control_count' => count($coverage['controls'] ?? []),
        ];
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, int>
     */
    private function zeroed(array $keys): array
    {
        return array_fill_keys($keys, 0);
    }
}
