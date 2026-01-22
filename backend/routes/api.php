<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ModuleController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectIntegrationController;
use App\Http\Controllers\ProjectSubscriptionController;
use App\Http\Controllers\ProjectModuleController;
use App\Http\Controllers\ProjectModuleOverrideController;
use App\Http\Controllers\ProjectMembershipController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\InviteController;

Route::get('/health', [HealthController::class, 'index']);

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::put('/me', [AuthController::class, 'update']);
    });
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/modules', [ModuleController::class, 'index']);
    Route::get('/plans', [PlanController::class, 'index']);
    Route::post('/invites/{token}/accept', [InviteController::class, 'accept']);
    Route::get('/integrations', [IntegrationController::class, 'index']);
    Route::get('/integrations/configuration', [IntegrationController::class, 'configuration']);
    Route::apiResource('projects', ProjectController::class);
    Route::get('/projects/{project}/integrations', [ProjectIntegrationController::class, 'index']);
    Route::get('/projects/{project}/integrations/{integration}/configuration', [ProjectIntegrationController::class, 'showConfiguration']);
    Route::put('/projects/{project}/integrations/{integration}/configuration', [ProjectIntegrationController::class, 'upsertConfiguration']);
    Route::get('/projects/{project}/subscription', [ProjectSubscriptionController::class, 'show']);
    Route::put('/projects/{project}/subscription', [ProjectSubscriptionController::class, 'update']);
    Route::get('/projects/{project}/entitlements', [ProjectSubscriptionController::class, 'entitlements']);
    Route::get('/projects/{project}/modules/access', [ProjectModuleController::class, 'access']);
    Route::get('/projects/{project}/modules', [ProjectModuleController::class, 'index']);
    Route::put('/projects/{project}/modules/{moduleKey}/override', [ProjectModuleOverrideController::class, 'update']);
    Route::get('/projects/{project}/audit-logs', [AuditLogController::class, 'index']);
    
    // Project memberships
    Route::get('/projects/{project}/memberships', [ProjectMembershipController::class, 'index']);
    Route::post('/projects/{project}/memberships', [ProjectMembershipController::class, 'store']);
    Route::post('/projects/{project}/memberships/invite', [ProjectMembershipController::class, 'invite']);
    Route::put('/projects/{project}/memberships/{membership}', [ProjectMembershipController::class, 'update']);
    Route::delete('/projects/{project}/memberships/{membership}', [ProjectMembershipController::class, 'destroy']);

    // Workspaces aliases (non-breaking API compatibility)
    // These routes are aliases of /api/projects endpoints, pointing to the same controllers
    Route::get('/workspaces', [ProjectController::class, 'index']); // GET /api/workspaces -> GET /api/projects
    Route::get('/workspaces/{project}/memberships', [ProjectMembershipController::class, 'index']);
    Route::post('/workspaces/{project}/memberships', [ProjectMembershipController::class, 'store']);
    Route::post('/workspaces/{project}/memberships/invite', [ProjectMembershipController::class, 'invite']);
    Route::put('/workspaces/{project}/memberships/{membership}', [ProjectMembershipController::class, 'update']);
    Route::delete('/workspaces/{project}/memberships/{membership}', [ProjectMembershipController::class, 'destroy']);
});
