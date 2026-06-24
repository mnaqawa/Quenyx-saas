<?php

use App\Http\Controllers\Compliance\ComplianceEvidenceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| QCIF Sprint 11 — Evidence Intelligence Foundation (workspace-scoped only)
|--------------------------------------------------------------------------
| Read-only, deterministic, UUID-only tenant evidence context, type catalog,
| and lifecycle status catalog. NO AI execution, no uploads/blob/OCR.
| Auth: sanctum (outer group) + project membership (ProjectPolicy::view)
| + QynShield entitlement (project.qynshield) + audit logging + rate limit.
*/

$registerWorkspaceEvidenceRoutes = function (string $projectPrefix): void {
    Route::prefix("{$projectPrefix}/{project}/compliance/evidence")
        ->middleware(['project.qynshield', 'throttle:compliance-evidence-read'])
        ->group(function () {
            Route::post('/context', [ComplianceEvidenceController::class, 'context']);
            Route::get('/types', [ComplianceEvidenceController::class, 'types']);
            Route::get('/statuses', [ComplianceEvidenceController::class, 'statuses']);
        });
};

$registerWorkspaceEvidenceRoutes('projects');
$registerWorkspaceEvidenceRoutes('workspaces');
