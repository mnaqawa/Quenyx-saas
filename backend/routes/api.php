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
// Readiness probe (GA): verifies DB/cache; returns 503 when not ready.
Route::get('/health/ready', [HealthController::class, 'ready']);

// Public agent installer endpoints (used from target hosts during enrollment — no QAG header).
Route::middleware(['throttle:120,1'])->group(function () {
    Route::get('/agents/availability/{platform}', [\App\Http\Controllers\AgentDownloadController::class, 'availability']);
    Route::get('/agents/download/{platform}', [\App\Http\Controllers\AgentDownloadController::class, 'download']);
});

// Agent ingestion API (token-auth, no user). When AGENT_REQUIRE_GATEWAY=true, agents reach Laravel via QAG only.
Route::middleware(['throttle:120,1', \App\Http\Middleware\EnsureAgentGateway::class])->group(function () {
    Route::post('/agents/register', [\App\Http\Controllers\AgentApiController::class, 'register']);
    Route::post('/agents/{agent}/heartbeat', [\App\Http\Controllers\AgentApiController::class, 'heartbeat']);
    Route::post('/agents/{agent}/metrics', [\App\Http\Controllers\AgentApiController::class, 'metrics']);
    Route::post('/agents/{agent}/inventory', [\App\Http\Controllers\AgentApiController::class, 'inventory']);
    Route::post('/agents/{agent}/evidence', [\App\Http\Controllers\AgentApiController::class, 'evidence']);
    Route::post('/agents/{agent}/diagnostics', [\App\Http\Controllers\AgentApiController::class, 'diagnostics']);
});

Route::prefix('auth')->group(function () {
    // GA HARDENING: brute-force protection on credential endpoints.
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:register');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
});

Route::middleware(['auth.session.idle', 'auth:sanctum'])->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::get('/entitlements', [AuthController::class, 'entitlements']);
        Route::put('/me', [AuthController::class, 'update']);
        Route::put('/me/password', [AuthController::class, 'changePassword']);
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
    Route::delete('/projects/{project}/memberships/invites/{invite}', [ProjectMembershipController::class, 'destroyInvite']);
    Route::put('/projects/{project}/memberships/{membership}', [ProjectMembershipController::class, 'update']);
    Route::delete('/projects/{project}/memberships/{membership}', [ProjectMembershipController::class, 'destroy']);

    // Workspaces aliases (non-breaking API compatibility)
    // These routes are aliases of /api/projects endpoints, pointing to the same controllers
    Route::get('/workspaces', [ProjectController::class, 'index']); // GET /api/workspaces -> GET /api/projects
    Route::post('/workspaces', [ProjectController::class, 'store']); // POST /api/workspaces -> POST /api/projects
    Route::get('/workspaces/{project}', [ProjectController::class, 'show']); // GET /api/workspaces/{id} -> GET /api/projects/{id}
    Route::put('/workspaces/{project}', [ProjectController::class, 'update']); // PUT /api/workspaces/{id} -> PUT /api/projects/{id}
    Route::delete('/workspaces/{project}', [ProjectController::class, 'destroy']); // DELETE /api/workspaces/{id} -> DELETE /api/projects/{id}
    
    // Workspace memberships aliases
    Route::get('/workspaces/{project}/memberships', [ProjectMembershipController::class, 'index']);
    Route::post('/workspaces/{project}/memberships', [ProjectMembershipController::class, 'store']);
    Route::post('/workspaces/{project}/memberships/invite', [ProjectMembershipController::class, 'invite']);
    Route::delete('/workspaces/{project}/memberships/invites/{invite}', [ProjectMembershipController::class, 'destroyInvite']);
    Route::put('/workspaces/{project}/memberships/{membership}', [ProjectMembershipController::class, 'update']);
    Route::delete('/workspaces/{project}/memberships/{membership}', [ProjectMembershipController::class, 'destroy']);
    
    // Workspace integrations aliases
    Route::get('/workspaces/{project}/integrations', [ProjectIntegrationController::class, 'index']);
    Route::get('/workspaces/{project}/integrations/{integration}/configuration', [ProjectIntegrationController::class, 'showConfiguration']);
    Route::put('/workspaces/{project}/integrations/{integration}/configuration', [ProjectIntegrationController::class, 'upsertConfiguration']);
    
    // Workspace subscription/entitlements aliases
    Route::get('/workspaces/{project}/subscription', [ProjectSubscriptionController::class, 'show']);
    Route::put('/workspaces/{project}/subscription', [ProjectSubscriptionController::class, 'update']);
    Route::get('/workspaces/{project}/entitlements', [ProjectSubscriptionController::class, 'entitlements']);
    
    // Workspace modules aliases
    Route::get('/workspaces/{project}/modules/access', [ProjectModuleController::class, 'access']);
    Route::get('/workspaces/{project}/modules', [ProjectModuleController::class, 'index']);
    Route::put('/workspaces/{project}/modules/{moduleKey}/override', [ProjectModuleOverrideController::class, 'update']);
    
    // Workspace audit logs alias
    Route::get('/workspaces/{project}/audit-logs', [AuditLogController::class, 'index']);
    
    // Observe endpoints (workspace canonical) — requires QynSight module entitlement
    Route::middleware('project.module:qynsight')->group(function () {
    Route::get('/workspaces/{project}/observe/summary', [\App\Http\Controllers\ObserveController::class, 'summary']);
    Route::get('/workspaces/{project}/observe/services', [\App\Http\Controllers\ObserveController::class, 'services']);
    Route::post('/workspaces/{project}/observe/run-checks', [\App\Http\Controllers\ObserveController::class, 'runChecks']);
    Route::get('/workspaces/{project}/observe/service-definitions', [\App\Http\Controllers\ObserveController::class, 'serviceDefinitions']);
    Route::get('/workspaces/{project}/observe/performance/metrics', [\App\Http\Controllers\ObserveController::class, 'performanceMetrics']);
    Route::get('/workspaces/{project}/observe/capacity-planning/export', [\App\Http\Controllers\ObserveController::class, 'capacityPlanningExport']);
    Route::get('/workspaces/{project}/observe/capacity-planning', [\App\Http\Controllers\ObserveController::class, 'capacityPlanning']);
    Route::get('/workspaces/{project}/observe/capacity/metrics', [\App\Http\Controllers\ObserveController::class, 'capacityMetrics']);
    Route::get('/workspaces/{project}/observe/alerts/rules', [\App\Http\Controllers\ObserveController::class, 'alertRules']);
    Route::post('/workspaces/{project}/observe/alerts/rules', [\App\Http\Controllers\ObserveAlertController::class, 'storeRule']);
    Route::put('/workspaces/{project}/observe/alerts/rules/{rule}', [\App\Http\Controllers\ObserveAlertController::class, 'updateRule']);
    Route::delete('/workspaces/{project}/observe/alerts/rules/{rule}', [\App\Http\Controllers\ObserveAlertController::class, 'destroyRule']);
    Route::patch('/workspaces/{project}/observe/alerts/rules/{rule}/toggle', [\App\Http\Controllers\ObserveAlertController::class, 'toggleRule']);
    Route::get('/workspaces/{project}/observe/alerts/summary', [\App\Http\Controllers\ObserveController::class, 'alertSummary']);
    Route::get('/workspaces/{project}/observe/alerts/history', [\App\Http\Controllers\ObserveAlertController::class, 'history']);
    Route::post('/workspaces/{project}/observe/alerts/events/{event}/acknowledge', [\App\Http\Controllers\ObserveAlertController::class, 'acknowledgeEvent']);
    Route::get('/workspaces/{project}/observe/alerts/channels', [\App\Http\Controllers\ObserveAlertController::class, 'channels']);
    Route::get('/workspaces/{project}/observe/monitoring-profile', [\App\Http\Controllers\ObserveAlertController::class, 'monitoringProfile']);
    Route::put('/workspaces/{project}/observe/monitoring-profile', [\App\Http\Controllers\ObserveAlertController::class, 'updateMonitoringProfile']);
    Route::get('/workspaces/{project}/observe/instances', [\App\Http\Controllers\ObserveController::class, 'instances']);
    Route::get('/workspaces/{project}/observe/instances/summary', [\App\Http\Controllers\ObserveController::class, 'instanceSummary']);
    Route::get('/workspaces/{project}/observe/reports', [\App\Http\Controllers\ObserveController::class, 'reports']);
    Route::get('/workspaces/{project}/observe/reports/summary', [\App\Http\Controllers\ObserveController::class, 'reportSummary']);
    Route::get('/workspaces/{project}/observe/data-sources', [\App\Http\Controllers\ObserveController::class, 'dataSources']);
    Route::get('/workspaces/{project}/observe/data-sources/summary', [\App\Http\Controllers\ObserveController::class, 'dataSourceSummary']);
    Route::get('/workspaces/{project}/observe/real-time/metrics', [\App\Http\Controllers\ObserveController::class, 'realTimeMetrics']);
    Route::get('/workspaces/{project}/observe/real-time/system-info', [\App\Http\Controllers\ObserveController::class, 'systemInfo']);
    Route::get('/workspaces/{project}/observe/real-time/thresholds', [\App\Http\Controllers\ObserveController::class, 'performanceThresholds']);
    Route::get('/workspaces/{project}/observe/infrastructure/topology', [\App\Http\Controllers\ObserveController::class, 'networkTopology']);
    Route::get('/workspaces/{project}/observe/infrastructure/connections', [\App\Http\Controllers\ObserveController::class, 'infrastructureConnections']);
    Route::get('/workspaces/{project}/observe/infrastructure/port-scans', [\App\Http\Controllers\ObserveController::class, 'portScans']);
    Route::post('/workspaces/{project}/observe/infrastructure/port-scans/run', [\App\Http\Controllers\ObserveController::class, 'runPortScans']);

    // Observe targets endpoints (workspace canonical)
    Route::get('/workspaces/{project}/observe/targets', [\App\Http\Controllers\ObserveTargetsController::class, 'index']);
    Route::put('/workspaces/{project}/observe/targets', [\App\Http\Controllers\ObserveTargetsController::class, 'update']);
    Route::post('/workspaces/{project}/observe/targets/validate', [\App\Http\Controllers\ObserveTargetsController::class, 'validateTargetsPayload']);
    Route::get('/workspaces/{project}/observe/targets/{hostId}/port-scan', [\App\Http\Controllers\ObserveTargetsController::class, 'portScan']);
    });

    Route::get('/workspaces/{project}/billing/summary', [\App\Http\Controllers\BillingController::class, 'summary']);
    Route::get('/workspaces/{project}/billing/integrations', [\App\Http\Controllers\BillingController::class, 'integrations']);
    Route::post('/workspaces/{project}/billing/integrations', [\App\Http\Controllers\BillingController::class, 'storeIntegration']);

    // Observe endpoints (project aliases) — requires QynSight module entitlement
    Route::middleware('project.module:qynsight')->group(function () {
    Route::get('/projects/{project}/observe/summary', [\App\Http\Controllers\ObserveController::class, 'summary']);
    Route::get('/projects/{project}/observe/services', [\App\Http\Controllers\ObserveController::class, 'services']);
    Route::post('/projects/{project}/observe/run-checks', [\App\Http\Controllers\ObserveController::class, 'runChecks']);
    Route::get('/projects/{project}/observe/service-definitions', [\App\Http\Controllers\ObserveController::class, 'serviceDefinitions']);
    Route::get('/projects/{project}/observe/performance/metrics', [\App\Http\Controllers\ObserveController::class, 'performanceMetrics']);
    Route::get('/projects/{project}/observe/capacity-planning/export', [\App\Http\Controllers\ObserveController::class, 'capacityPlanningExport']);
    Route::get('/projects/{project}/observe/capacity-planning', [\App\Http\Controllers\ObserveController::class, 'capacityPlanning']);
        Route::get('/projects/{project}/observe/capacity/metrics', [\App\Http\Controllers\ObserveController::class, 'capacityMetrics']);
        Route::get('/projects/{project}/observe/alerts/rules', [\App\Http\Controllers\ObserveController::class, 'alertRules']);
    Route::post('/projects/{project}/observe/alerts/rules', [\App\Http\Controllers\ObserveAlertController::class, 'storeRule']);
    Route::put('/projects/{project}/observe/alerts/rules/{rule}', [\App\Http\Controllers\ObserveAlertController::class, 'updateRule']);
    Route::delete('/projects/{project}/observe/alerts/rules/{rule}', [\App\Http\Controllers\ObserveAlertController::class, 'destroyRule']);
    Route::patch('/projects/{project}/observe/alerts/rules/{rule}/toggle', [\App\Http\Controllers\ObserveAlertController::class, 'toggleRule']);
    Route::get('/projects/{project}/observe/alerts/summary', [\App\Http\Controllers\ObserveController::class, 'alertSummary']);
    Route::get('/projects/{project}/observe/alerts/history', [\App\Http\Controllers\ObserveAlertController::class, 'history']);
    Route::post('/projects/{project}/observe/alerts/events/{event}/acknowledge', [\App\Http\Controllers\ObserveAlertController::class, 'acknowledgeEvent']);
    Route::get('/projects/{project}/observe/alerts/channels', [\App\Http\Controllers\ObserveAlertController::class, 'channels']);
    Route::get('/projects/{project}/observe/monitoring-profile', [\App\Http\Controllers\ObserveAlertController::class, 'monitoringProfile']);
    Route::put('/projects/{project}/observe/monitoring-profile', [\App\Http\Controllers\ObserveAlertController::class, 'updateMonitoringProfile']);
    Route::get('/projects/{project}/observe/instances', [\App\Http\Controllers\ObserveController::class, 'instances']);
    Route::get('/projects/{project}/observe/instances/summary', [\App\Http\Controllers\ObserveController::class, 'instanceSummary']);
    Route::get('/projects/{project}/observe/reports', [\App\Http\Controllers\ObserveController::class, 'reports']);
    Route::get('/projects/{project}/observe/reports/summary', [\App\Http\Controllers\ObserveController::class, 'reportSummary']);
    Route::get('/projects/{project}/observe/data-sources', [\App\Http\Controllers\ObserveController::class, 'dataSources']);
    Route::get('/projects/{project}/observe/data-sources/summary', [\App\Http\Controllers\ObserveController::class, 'dataSourceSummary']);
    Route::get('/projects/{project}/observe/real-time/metrics', [\App\Http\Controllers\ObserveController::class, 'realTimeMetrics']);
    Route::get('/projects/{project}/observe/real-time/system-info', [\App\Http\Controllers\ObserveController::class, 'systemInfo']);
    Route::get('/projects/{project}/observe/real-time/thresholds', [\App\Http\Controllers\ObserveController::class, 'performanceThresholds']);
    Route::get('/projects/{project}/observe/infrastructure/topology', [\App\Http\Controllers\ObserveController::class, 'networkTopology']);
    Route::get('/projects/{project}/observe/infrastructure/connections', [\App\Http\Controllers\ObserveController::class, 'infrastructureConnections']);
    Route::get('/projects/{project}/observe/infrastructure/port-scans', [\App\Http\Controllers\ObserveController::class, 'portScans']);
        Route::post('/projects/{project}/observe/infrastructure/port-scans/run', [\App\Http\Controllers\ObserveController::class, 'runPortScans']);
        Route::get('/projects/{project}/observe/targets', [\App\Http\Controllers\ObserveTargetsController::class, 'index']);
        Route::put('/projects/{project}/observe/targets', [\App\Http\Controllers\ObserveTargetsController::class, 'update']);
        Route::post('/projects/{project}/observe/targets/validate', [\App\Http\Controllers\ObserveTargetsController::class, 'validateTargetsPayload']);
        Route::get('/projects/{project}/observe/targets/{hostId}/port-scan', [\App\Http\Controllers\ObserveTargetsController::class, 'portScan']);
    });

    Route::get('/projects/{project}/billing/summary', [\App\Http\Controllers\BillingController::class, 'summary']);
    Route::get('/projects/{project}/billing/integrations', [\App\Http\Controllers\BillingController::class, 'integrations']);
    Route::post('/projects/{project}/billing/integrations', [\App\Http\Controllers\BillingController::class, 'storeIntegration']);

    // Agents (workspace-scoped)
    Route::get('/workspaces/{project}/agents', [\App\Http\Controllers\AgentController::class, 'index']);
    Route::post('/workspaces/{project}/agents/enrollment-token', [\App\Http\Controllers\AgentController::class, 'createEnrollmentToken']);
    Route::get('/workspaces/{project}/agents/metadata', [\App\Http\Controllers\AgentController::class, 'metadata']);
    Route::get('/workspaces/{project}/agents/enrollment-tokens', [\App\Http\Controllers\AgentController::class, 'listEnrollmentTokens']);
    Route::post('/workspaces/{project}/agents/enrollment-tokens/{token}/revoke', [\App\Http\Controllers\AgentController::class, 'revokeEnrollmentToken']);
    Route::delete('/workspaces/{project}/agents/{agent}', [\App\Http\Controllers\AgentController::class, 'destroy']);
    Route::get('/projects/{project}/agents', [\App\Http\Controllers\AgentController::class, 'index']);
    Route::post('/projects/{project}/agents/enrollment-token', [\App\Http\Controllers\AgentController::class, 'createEnrollmentToken']);
    Route::get('/projects/{project}/agents/metadata', [\App\Http\Controllers\AgentController::class, 'metadata']);
    Route::get('/projects/{project}/agents/enrollment-tokens', [\App\Http\Controllers\AgentController::class, 'listEnrollmentTokens']);
    Route::post('/projects/{project}/agents/enrollment-tokens/{token}/revoke', [\App\Http\Controllers\AgentController::class, 'revokeEnrollmentToken']);
    Route::delete('/projects/{project}/agents/{agent}', [\App\Http\Controllers\AgentController::class, 'destroy']);

    // Quenyx Platform Agent (QPA) — platform-level APIs
    Route::prefix('platform/agents')->group(function () {
        Route::get('/metadata', [\App\Http\Controllers\Platform\PlatformAgentController::class, 'metadata']);
        Route::get('/fleet', [\App\Http\Controllers\Platform\PlatformAgentController::class, 'fleet']);
        Route::get('/health', [\App\Http\Controllers\Platform\PlatformAgentController::class, 'health']);
        Route::get('/updates', [\App\Http\Controllers\Platform\PlatformAgentController::class, 'updates']);
        Route::get('/configuration', [\App\Http\Controllers\Platform\PlatformAgentController::class, 'configuration']);
        Route::get('/certificates', [\App\Http\Controllers\Platform\PlatformAgentController::class, 'certificates']);
        Route::get('/queue', [\App\Http\Controllers\Platform\PlatformAgentController::class, 'queue']);
        Route::get('/installers', [\App\Http\Controllers\Platform\PlatformAgentController::class, 'installers']);
        Route::get('/gateways', [\App\Http\Controllers\Platform\PlatformAgentController::class, 'gateways']);
        Route::get('/', [\App\Http\Controllers\Platform\PlatformAgentController::class, 'index']);
        Route::post('/enrollment-tokens', [\App\Http\Controllers\Platform\PlatformAgentController::class, 'createEnrollmentToken']);
        Route::get('/{agent}/resources', [\App\Http\Controllers\Platform\PlatformAgentController::class, 'resources']);
        Route::get('/{agent}/plugins', [\App\Http\Controllers\Platform\PlatformAgentController::class, 'plugins']);
        Route::get('/{agent}/diagnostics', [\App\Http\Controllers\Platform\PlatformAgentController::class, 'diagnostics']);
        Route::post('/{agent}/diagnostics/generate', [\App\Http\Controllers\Platform\PlatformAgentController::class, 'generateDiagnostics']);
        Route::get('/{agent}/diagnostics/{bundle}', [\App\Http\Controllers\Platform\PlatformAgentController::class, 'downloadDiagnostics']);
        Route::get('/{agent}', [\App\Http\Controllers\Platform\PlatformAgentController::class, 'show']);
        Route::put('/{agent}/permissions', [\App\Http\Controllers\Platform\PlatformAgentController::class, 'updatePermissions']);
        Route::post('/{agent}/revoke', [\App\Http\Controllers\Platform\PlatformAgentController::class, 'revoke']);
        Route::delete('/{agent}', [\App\Http\Controllers\Platform\PlatformAgentController::class, 'destroy']);
    });

    Route::get('/platform/fleet/summary', [\App\Http\Controllers\Platform\PlatformAgentController::class, 'fleetSummary']);

    // QynSight host lifecycle (workspace-scoped)
    Route::prefix('workspaces/{project}/qynsight/hosts')->group(function () {
        Route::post('/{host}/disable-monitoring', [\App\Http\Controllers\Observe\HostLifecycleController::class, 'disableMonitoring']);
        Route::post('/{host}/suspend', [\App\Http\Controllers\Observe\HostLifecycleController::class, 'suspend']);
        Route::post('/{host}/archive', [\App\Http\Controllers\Observe\HostLifecycleController::class, 'archive']);
        Route::post('/{host}/restore', [\App\Http\Controllers\Observe\HostLifecycleController::class, 'restore']);
        Route::delete('/{host}', [\App\Http\Controllers\Observe\HostLifecycleController::class, 'destroy']);
    });
    Route::prefix('projects/{project}/qynsight/hosts')->group(function () {
        Route::post('/{host}/disable-monitoring', [\App\Http\Controllers\Observe\HostLifecycleController::class, 'disableMonitoring']);
        Route::post('/{host}/suspend', [\App\Http\Controllers\Observe\HostLifecycleController::class, 'suspend']);
        Route::post('/{host}/archive', [\App\Http\Controllers\Observe\HostLifecycleController::class, 'archive']);
        Route::post('/{host}/restore', [\App\Http\Controllers\Observe\HostLifecycleController::class, 'restore']);
        Route::delete('/{host}', [\App\Http\Controllers\Observe\HostLifecycleController::class, 'destroy']);
    });

    // NOTE (GA remediation): The legacy QynSight AI routes that previously registered
    //   POST /{projects|workspaces}/{project}/ai/chat  (App\Http\Controllers\AiAgentController)
    // were removed. The canonical, governed AI chat/stream endpoints are provided by
    // routes/ai-orchestration.php (App\Http\Controllers\Ai\AiOrchestrationController), which
    // already shadowed the legacy /ai/chat registration and is the single AI pipeline (provider
    // registry + narration + audit). The legacy controller called LlmClient directly (provider
    // bypass) and was not referenced by the frontend. /ai/chat remains a single canonical endpoint.

    // Knowledge base agent (OpenAI Responses API + File Search over Vector Store)
    Route::post('/ai-agent/query', [\App\Http\Controllers\API\AIAgentController::class, 'query']);

    // QCIF Compliance Intelligence Layer — see routes/compliance-corpus.php
    require base_path('routes/compliance-corpus.php');

    // QCIF Sprint 6 — AI Consumption Contract Layer (no AI execution) — see routes/compliance-ai-context.php
    require base_path('routes/compliance-ai-context.php');

    // QCIF Sprint 7 — Compliance Knowledge Graph Layer (no AI execution) — see routes/compliance-graph.php
    require base_path('routes/compliance-graph.php');

    // QCIF Sprint 8 — Cross-Framework Mapping Foundation (no AI execution) — see routes/compliance-mappings.php
    require base_path('routes/compliance-mappings.php');

    // QCIF Sprint 9 — AI Orchestration Platform (mocked unless AI explicitly enabled) — see routes/ai-orchestration.php
    require base_path('routes/ai-orchestration.php');

    // QCIF Sprint 11 — Evidence Intelligence Foundation (read-only, no AI execution) — see routes/compliance-evidence.php
    require base_path('routes/compliance-evidence.php');

    // QCIF Sprint 12 — Gap Assessment & Evidence Correlation Engine (read-only, deterministic, no AI) — see routes/compliance-gap.php
    require base_path('routes/compliance-gap.php');

    // QCIF Sprint 13 — Recommendation Engine (deterministic, rule-based, no AI) — see routes/compliance-recommendations.php
    require base_path('routes/compliance-recommendations.php');

    // QCIF Sprint 14 — Compliance Copilot v0 (skill orchestration + citation-enforced AI) — see routes/compliance-copilot.php
    require base_path('routes/compliance-copilot.php');

    // QCIF Sprint 15 — Retrieval & RAG Optimization Foundation (deterministic, no vector/embeddings) — see routes/compliance-retrieval.php
    require base_path('routes/compliance-retrieval.php');

    // QCIF Sprint 17 — RAG Runtime (hybrid retrieval + bounded cited context; feature-flagged) — see routes/compliance-rag.php
    require base_path('routes/compliance-rag.php');

    // QCIF Sprint 18 — Executive Demonstration Platform (read-only aggregation of existing intelligence) — see routes/compliance-executive.php
    require base_path('routes/compliance-executive.php');

    // QCIF Sprint 19 — Quenyx AI Platform Foundation (shared AI runtime capability catalog) — see routes/quenyx-ai.php
    require base_path('routes/quenyx-ai.php');

    // Sprint 20 — Unified AI Workspace (platform-level AI surface; workspace-scoped by UUID) — see routes/ai-workspace.php
    require base_path('routes/ai-workspace.php');

    // Sprint 21 — QynSight Operations Intelligence (reuses the Quenyx AI runtime; workspace-scoped by UUID) — see routes/qynsight-intelligence.php
    require base_path('routes/qynsight-intelligence.php');

    // Sprint 22 — QynAsset Asset Intelligence (second production AI adapter; reuses the Quenyx AI runtime) — see routes/qynasset-intelligence.php
    require base_path('routes/qynasset-intelligence.php');

    // Sprint 23 — QynRun Enterprise Automation Platform (registry-driven, safe-by-default execution) — see routes/qynrun-automation.php
    require base_path('routes/qynrun-automation.php');

    // Sprint 23 — QynReact Incident Workspace (cross-module orchestration via the AI adapter registry) — see routes/qynreact-incident.php
    require base_path('routes/qynreact-incident.php');

    // Sprint 24 — QynKnow Enterprise Knowledge Platform (registry-driven knowledge, enterprise search, graph, timeline) — see routes/qynknow-knowledge.php
    require base_path('routes/qynknow-knowledge.php');

    // Sprint 24 — QynSupport Service Desk (evidence-based Ticket Intelligence) — see routes/qynsupport-servicedesk.php
    require base_path('routes/qynsupport-servicedesk.php');

    // Sprint 24 — QynNotify Notification Center (deterministic routing + Notification Intelligence) — see routes/qynnotify-notifications.php
    require base_path('routes/qynnotify-notifications.php');

    // Sprint 24 — Collaboration Platform (shared comments/mentions/watchers/assignments for every module) — see routes/collaboration.php
    require base_path('routes/collaboration.php');

    // Sprint 25 — QynVA Enterprise AI Operator + Executive Intelligence, Enterprise Analytics, Platform Health, Event Bus — see routes/qynva-operator.php
    require base_path('routes/qynva-operator.php');

    // Sprint 25 — QynBalance Enterprise Cost Intelligence (real data, no fabricated financials) — see routes/qynbalance-cost.php
    require base_path('routes/qynbalance-cost.php');
});
