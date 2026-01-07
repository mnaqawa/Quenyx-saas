<?php

namespace App\Services;

use App\Repositories\DashboardRepository;

class DashboardService
{
    public function __construct(
        private DashboardRepository $dashboardRepository
    ) {}

    public function getDashboardData(): array
    {
        return [
            'platform_health' => $this->dashboardRepository->getPlatformHealth(),
            'modules' => $this->dashboardRepository->getModulesWithSubscriptions(),
        ];
    }
}