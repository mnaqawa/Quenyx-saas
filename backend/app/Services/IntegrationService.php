<?php

namespace App\Services;

use App\Repositories\IntegrationRepository;

class IntegrationService
{
    public function __construct(
        private IntegrationRepository $integrationRepository
    ) {}

    public function getIntegrations(): array
    {
        return $this->integrationRepository->getIntegrations();
    }

    public function getConfiguration(): array
    {
        return $this->integrationRepository->getApiConfiguration();
    }
}
