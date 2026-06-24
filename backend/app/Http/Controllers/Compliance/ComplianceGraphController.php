<?php

namespace App\Http\Controllers\Compliance;

use App\Exceptions\ComplianceCorpusNotFoundException;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\Compliance\ComplianceCorpusAccessAuditLogger;
use App\Services\Compliance\ComplianceCorpusCacheService;
use App\Services\Compliance\Graph\ComplianceKnowledgeGraphService;
use Illuminate\Http\JsonResponse;

/**
 * Workspace-scoped, read-only API for the Compliance Knowledge Graph Layer (QCIF Sprint 7).
 *
 * Returns deterministic, UUID-only intra-framework graph context (Domain → Control →
 * Requirement, plus control self-hierarchy). It performs NO AI execution, vectors, RAG,
 * scoring, or assessment. Access requires sanctum auth, project membership, and the
 * QynShield module entitlement; every request is audit-logged and results are cached
 * against the active corpus revision.
 */
class ComplianceGraphController extends Controller
{
    public function __construct(
        private readonly ComplianceKnowledgeGraphService $graphService,
        private readonly ComplianceCorpusCacheService $cacheService,
        private readonly ComplianceCorpusAccessAuditLogger $auditLogger,
    ) {}

    public function framework(Project $project, string $frameworkKey, string $releaseCode): JsonResponse
    {
        return $this->respond(
            $project,
            'framework_context',
            'graph.framework',
            'graph:framework',
            $frameworkKey,
            $releaseCode,
            fn () => $this->graphService->getFrameworkContext($frameworkKey, $releaseCode),
        );
    }

    public function domain(Project $project, string $frameworkKey, string $releaseCode, string $domainCode): JsonResponse
    {
        return $this->respond(
            $project,
            'domain_context',
            'graph.domain',
            "graph:domain:{$domainCode}",
            $frameworkKey,
            $releaseCode,
            fn () => $this->graphService->getDomainContext($frameworkKey, $releaseCode, $domainCode),
        );
    }

    public function control(Project $project, string $frameworkKey, string $releaseCode, string $controlCode): JsonResponse
    {
        return $this->respond(
            $project,
            'control_context',
            'graph.control',
            "graph:control:{$controlCode}",
            $frameworkKey,
            $releaseCode,
            fn () => $this->graphService->getControlContext($frameworkKey, $releaseCode, $controlCode),
        );
    }

    public function requirement(Project $project, string $frameworkKey, string $releaseCode, string $requirementCode): JsonResponse
    {
        return $this->respond(
            $project,
            'requirement_context',
            'graph.requirement',
            "graph:requirement:{$requirementCode}",
            $frameworkKey,
            $releaseCode,
            fn () => $this->graphService->getRequirementContext($frameworkKey, $releaseCode, $requirementCode),
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param  callable(): array<string, mixed>  $builder
     */
    private function respond(
        Project $project,
        string $contextType,
        string $endpoint,
        string $segment,
        string $frameworkKey,
        string $releaseCode,
        callable $builder,
    ): JsonResponse {
        $this->authorize('view', $project);

        $user = request()->user();
        if ($user !== null) {
            $this->auditLogger->logGraph($user, $project, $contextType, $endpoint, $frameworkKey, $releaseCode);
        }

        try {
            $data = $this->cacheService->remember($frameworkKey, $releaseCode, $segment, $builder);
        } catch (ComplianceCorpusNotFoundException $e) {
            return $this->error($e->getMessage(), 'corpus_not_found', 404);
        }

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    private function error(string $message, string $code, int $status): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'code' => $code,
        ], $status);
    }
}
