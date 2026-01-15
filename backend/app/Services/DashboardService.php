<?php

namespace App\Services;

use App\Repositories\DashboardRepository;
use App\Repositories\ModuleRepository;

class DashboardService
{
    public function __construct(
        private DashboardRepository $dashboardRepository,
        private ModuleRepository $moduleRepository
    ) {}

    public function getDashboardData(): array
    {
        $modules = $this->moduleRepository->getModulesWithSubscriptions();

        return [
            'platform_health' => $this->dashboardRepository->getPlatformHealth(),
            'modules' => $modules,
            'performance_series' => $this->dashboardRepository->getPerformanceSeries($modules),
            'weekly_uptime' => $this->dashboardRepository->getWeeklyUptime($modules),
            'alerts_by_module' => $this->dashboardRepository->getAlertsByModule($modules),
        ];
    }
}