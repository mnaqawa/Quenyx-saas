<?php

namespace App\Services;

use App\Repositories\ModuleRepository;

class ModuleService
{
    public function __construct(
        private ModuleRepository $moduleRepository
    ) {}

    public function getModules(): array
    {
        return $this->moduleRepository->getModulesWithSubscriptions();
    }

    /**
     * Get all modules (catalog) without subscription info
     */
    public function getModulesCatalog(): array
    {
        return $this->moduleRepository->getAllModules();
    }
}
