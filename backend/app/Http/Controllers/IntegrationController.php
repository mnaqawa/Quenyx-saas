<?php

namespace App\Http\Controllers;

use App\Services\IntegrationService;
use Illuminate\Http\JsonResponse;

class IntegrationController extends Controller
{
    public function __construct(
        private IntegrationService $integrationService
    ) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->integrationService->getIntegrations(),
        ]);
    }

    public function configuration(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->integrationService->getConfiguration(),
        ]);
    }
}
