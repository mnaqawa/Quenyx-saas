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
        $modules = $this->dashboardRepository->getModulesWithSubscriptions();

        return [
            'platform_health' => $this->dashboardRepository->getPlatformHealth(),
            'modules' => $modules,
            'performance_series' => $this->dashboardRepository->getPerformanceSeries($modules),
            'weekly_uptime' => $this->dashboardRepository->getWeeklyUptime($modules),
            'alerts_by_module' => $this->dashboardRepository->getAlertsByModule($modules),
        ];
    }
}