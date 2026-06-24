<?php

namespace App\Services\Compliance\Recommendation;

use App\Enums\Compliance\Recommendation\ComplianceRecommendationPriority;

/**
 * Builds the deterministic recommendation summary (QCIF Sprint 13): counts by priority, by type,
 * and by originating gap status. Pure counting — no scores, no AI.
 */
class RecommendationSummaryService
{
    /**
     * @param  list<array<string, mixed>>  $recommendations
     * @return array<string, mixed>
     */
    public function summarize(array $recommendations): array
    {
        $byPriority = array_fill_keys(ComplianceRecommendationPriority::values(), 0);
        $byType = [];
        $byGapStatus = [];

        foreach ($recommendations as $rec) {
            $priority = (string) ($rec['priority'] ?? '');
            $type = (string) ($rec['recommendation_type'] ?? '');
            $gapStatus = (string) ($rec['gap_status'] ?? '');

            if (array_key_exists($priority, $byPriority)) {
                $byPriority[$priority]++;
            }
            $byType[$type] = ($byType[$type] ?? 0) + 1;
            if ($gapStatus !== '') {
                $byGapStatus[$gapStatus] = ($byGapStatus[$gapStatus] ?? 0) + 1;
            }
        }

        return [
            'totals' => [
                'recommendations' => count($recommendations),
                'by_priority' => $byPriority,
                'by_type' => $byType,
                'by_gap_status' => $byGapStatus,
            ],
        ];
    }
}
