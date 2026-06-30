<?php

declare(strict_types=1);

namespace App\Services\Asset\Intelligence;

use App\Models\Project;

/**
 * Sprint 22 — License Intelligence.
 *
 * This product collects no software-license inventory, so this service NEVER fabricates utilization,
 * unused seats, or compliance risk. It returns an honest "not collected" review plus the concrete
 * integration that would enable it. The capability is still exposed so the gap is visible rather than
 * silently absent.
 */
class LicenseAdvisorService
{
    public function __construct(
        private readonly AssetEvidenceCollector $evidence,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function review(Project $project): array
    {
        $licenses = $this->evidence->licenses($project);

        return [
            'licenses' => $licenses,
            'compliance_risk' => $licenses['available'] ? null : 'unknown',
            'optimization_opportunities' => [],
            'required_integration' => 'A license/inventory integration (e.g. GLPI/FusionInventory) must be connected to enable License Intelligence.',
        ];
    }
}
