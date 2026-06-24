<?php

use App\Http\Controllers\Compliance\ComplianceAiContextController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| QCIF Sprint 6 — AI Consumption Contract Layer (workspace-scoped only)
|--------------------------------------------------------------------------
| Read-only, deterministic AI-ready corpus payloads. NO AI execution.
| Auth: sanctum (outer group) + project membership (ProjectPolicy::view)
| + QynShield entitlement (project.qynshield) + audit logging + rate limit + cache.
|
| Global AI-context endpoints are intentionally NOT registered in this sprint.
*/

$aiReleaseConstraints = [
    'frameworkKey' => '[A-Za-z0-9\-]+',
    'releaseCode' => '[A-Za-z0-9:\-]+',
];

$aiFullConstraints = [
    'frameworkKey' => '[A-Za-z0-9\-]+',
    'releaseCode' => '[A-Za-z0-9:\-]+',
    'domainCode' => '[A-Za-z0-9\-]+',
    'controlCode' => '[A-Za-z0-9\-]+',
];

$registerWorkspaceAiContextRoutes = function (string $projectPrefix) use ($aiReleaseConstraints, $aiFullConstraints): void {
    Route::prefix("{$projectPrefix}/{project}/compliance/ai-context")
        ->middleware(['project.qynshield', 'throttle:compliance-ai-context-read'])
        ->group(function () use ($aiReleaseConstraints, $aiFullConstraints) {
            Route::get('/frameworks/{frameworkKey}/releases/{releaseCode}/summary', [ComplianceAiContextController::class, 'summary'])
                ->where($aiReleaseConstraints);
            Route::get('/frameworks/{frameworkKey}/releases/{releaseCode}/domains/{domainCode}', [ComplianceAiContextController::class, 'domain'])
                ->where(array_merge($aiReleaseConstraints, ['domainCode' => $aiFullConstraints['domainCode']]));
            Route::get('/frameworks/{frameworkKey}/releases/{releaseCode}/controls/{controlCode}', [ComplianceAiContextController::class, 'control'])
                ->where(array_merge($aiReleaseConstraints, ['controlCode' => $aiFullConstraints['controlCode']]));
        });

    Route::prefix("{$projectPrefix}/{project}/compliance/ai-context")
        ->middleware(['project.qynshield', 'throttle:compliance-ai-context-search'])
        ->group(function () use ($aiReleaseConstraints) {
            Route::get('/frameworks/{frameworkKey}/releases/{releaseCode}/search', [ComplianceAiContextController::class, 'search'])
                ->where($aiReleaseConstraints);
        });
};

$registerWorkspaceAiContextRoutes('projects');
$registerWorkspaceAiContextRoutes('workspaces');
