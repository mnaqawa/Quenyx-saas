<?php

namespace App\Http\Controllers;

use App\Services\ModuleService;
use Illuminate\Http\JsonResponse;

class ModuleController extends Controller
{
    public function __construct(
        private ModuleService $moduleService
    ) {}

    public function index(): JsonResponse
    {
        // Return catalog (all modules with key, name, description, status)
        return response()->json([
            'success' => true,
            'data' => $this->moduleService->getModulesCatalog(),
        ]);
    }
}
