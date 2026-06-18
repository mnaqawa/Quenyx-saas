<?php

use App\Http\Controllers\Compliance\ComplianceCorpusController;
use Illuminate\Support\Facades\Route;

$corpusReleaseConstraints = [
    'frameworkKey' => '[A-Za-z0-9\-]+',
    'releaseCode' => '[A-Za-z0-9:\-]+',
];

$corpusFullConstraints = [
    'frameworkKey' => '[A-Za-z0-9\-]+',
    'releaseCode' => '[A-Za-z0-9:\-]+',
    'domainCode' => '[A-Za-z0-9\-]+',
    'controlCode' => '[A-Za-z0-9\-]+',
];

/*
|--------------------------------------------------------------------------
| Global corpus routes (internal / future AI consumers)
| Auth: sanctum. No workspace entitlement.
|--------------------------------------------------------------------------
*/
Route::prefix('compliance/corpus')->middleware('throttle:compliance-corpus-read')->group(function () use ($corpusReleaseConstraints, $corpusFullConstraints) {
    Route::get('/frameworks', [ComplianceCorpusController::class, 'frameworks']);
    Route::get('/frameworks/{frameworkKey}/releases', [ComplianceCorpusController::class, 'releases'])
        ->where('frameworkKey', $corpusReleaseConstraints['frameworkKey']);
    Route::get('/frameworks/{frameworkKey}/releases/{releaseCode}/summary', [ComplianceCorpusController::class, 'summary'])
        ->where($corpusReleaseConstraints);
    Route::get('/frameworks/{frameworkKey}/releases/{releaseCode}/domains', [ComplianceCorpusController::class, 'domains'])
        ->where($corpusReleaseConstraints);
    Route::get('/frameworks/{frameworkKey}/releases/{releaseCode}/domains/{domainCode}', [ComplianceCorpusController::class, 'domain'])
        ->where(array_merge($corpusReleaseConstraints, ['domainCode' => $corpusFullConstraints['domainCode']]));
    Route::get('/frameworks/{frameworkKey}/releases/{releaseCode}/controls/{controlCode}', [ComplianceCorpusController::class, 'control'])
        ->where(array_merge($corpusReleaseConstraints, ['controlCode' => $corpusFullConstraints['controlCode']]));
});

Route::prefix('compliance/corpus')->middleware('throttle:compliance-corpus-search')->group(function () use ($corpusReleaseConstraints) {
    Route::get('/frameworks/{frameworkKey}/releases/{releaseCode}/search', [ComplianceCorpusController::class, 'search'])
        ->where($corpusReleaseConstraints);
});

/*
|--------------------------------------------------------------------------
| Workspace-scoped corpus routes (SaaS / QynShield frontend)
| Auth: sanctum + membership (ProjectPolicy) + qynshield entitlement
|--------------------------------------------------------------------------
*/
$registerWorkspaceCorpusRoutes = function (string $projectPrefix) use ($corpusReleaseConstraints, $corpusFullConstraints): void {
    Route::prefix("{$projectPrefix}/{project}/compliance/corpus")
        ->middleware(['project.qynshield', 'throttle:compliance-corpus-read'])
        ->group(function () use ($corpusReleaseConstraints, $corpusFullConstraints) {
            Route::get('/frameworks', [ComplianceCorpusController::class, 'workspaceFrameworks']);
            Route::get('/frameworks/{frameworkKey}/releases', [ComplianceCorpusController::class, 'workspaceReleases'])
                ->where('frameworkKey', $corpusReleaseConstraints['frameworkKey']);
            Route::get('/frameworks/{frameworkKey}/releases/{releaseCode}/summary', [ComplianceCorpusController::class, 'workspaceSummary'])
                ->where($corpusReleaseConstraints);
            Route::get('/frameworks/{frameworkKey}/releases/{releaseCode}/domains', [ComplianceCorpusController::class, 'workspaceDomains'])
                ->where($corpusReleaseConstraints);
            Route::get('/frameworks/{frameworkKey}/releases/{releaseCode}/domains/{domainCode}', [ComplianceCorpusController::class, 'workspaceDomain'])
                ->where(array_merge($corpusReleaseConstraints, ['domainCode' => $corpusFullConstraints['domainCode']]));
            Route::get('/frameworks/{frameworkKey}/releases/{releaseCode}/controls/{controlCode}', [ComplianceCorpusController::class, 'workspaceControl'])
                ->where(array_merge($corpusReleaseConstraints, ['controlCode' => $corpusFullConstraints['controlCode']]));
        });

    Route::prefix("{$projectPrefix}/{project}/compliance/corpus")
        ->middleware(['project.qynshield', 'throttle:compliance-corpus-search'])
        ->group(function () use ($corpusReleaseConstraints) {
            Route::get('/frameworks/{frameworkKey}/releases/{releaseCode}/search', [ComplianceCorpusController::class, 'workspaceSearch'])
                ->where($corpusReleaseConstraints);
        });
};

$registerWorkspaceCorpusRoutes('projects');
$registerWorkspaceCorpusRoutes('workspaces');
