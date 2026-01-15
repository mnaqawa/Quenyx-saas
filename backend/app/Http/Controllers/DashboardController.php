<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __construct(
        private DashboardService $dashboardService
    ) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->dashboardService->getDashboardData(),
        ]);
    }
}
