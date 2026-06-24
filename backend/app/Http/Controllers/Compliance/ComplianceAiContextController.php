<?php

namespace App\Http\Controllers\Compliance;

use App\Exceptions\ComplianceAiContextException;
use App\Exceptions\ComplianceCorpusNotFoundException;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\Compliance\Ai\ComplianceAiContextService;
use App\Services\Compliance\Ai\ComplianceAiGuardrailService;
use App\Services\Compliance\ComplianceCorpusAccessAuditLogger;
use App\Services\Compliance\ComplianceCorpusCacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Workspace-scoped, read-only API for the AI Consumption Contract Layer.
 *
 * Returns deterministic, structured, AI-ready corpus payloads. It performs NO AI
 * execution (no OpenAI/LLM/RAG/embeddings/vectors). Access requires sanctum auth, project
 * membership, and the QynShield module entitlement; every request is audit-logged and
 * results are cached against the active corpus revision.
 */
class ComplianceAiContextController extends Controller
{
    public function __construct(
        private readonly ComplianceAiContextService $contextService,
        private readonly ComplianceCorpusCacheService $cacheService,
        private readonly ComplianceCorpusAccessAuditLogger $auditLogger,
    ) {}

    public function summary(Project $project, string $frameworkKey, string $releaseCode): JsonResponse
    {
        return $this->respond(
            $project,
            ComplianceAiGuardrailService::CONTEXT_CORPUS_SUMMARY,
            'ai-context.summary',
            'ai:summary',
            $frameworkKey,
            $releaseCode,
            fn () => $this->contextService->build(
                ComplianceAiGuardrailService::CONTEXT_CORPUS_SUMMARY,
                $frameworkKey,
                $releaseCode,
            ),
        );
    }

    public function domain(Project $project, string $frameworkKey, string $releaseCode, string $domainCode): JsonResponse
    {
        return $this->respond(
            $project,
            ComplianceAiGuardrailService::CONTEXT_DOMAIN_PROFILE,
            'ai-context.domain',
            "ai:domain:{$domainCode}",
            $frameworkKey,
            $releaseCode,
            fn () => $this->contextService->build(
                ComplianceAiGuardrailService::CONTEXT_DOMAIN_PROFILE,
                $frameworkKey,
                $releaseCode,
                ['domainCode' => $domainCode],
            ),
        );
    }

    public function control(Project $project, string $frameworkKey, string $releaseCode, string $controlCode): JsonResponse
    {
        return $this->respond(
            $project,
            ComplianceAiGuardrailService::CONTEXT_CONTROL_PROFILE,
            'ai-context.control',
            "ai:control:{$controlCode}",
            $frameworkKey,
            $releaseCode,
            fn () => $this->contextService->build(
                ComplianceAiGuardrailService::CONTEXT_CONTROL_PROFILE,
                $frameworkKey,
                $releaseCode,
                ['controlCode' => $controlCode],
            ),
        );
    }

    public function search(Request $request, Project $project, string $frameworkKey, string $releaseCode): JsonResponse
    {
        $query = (string) $request->query('q', '');
        $limit = $request->query('limit');
        $limitInt = $limit !== null ? (int) $limit : null;
        $segment = 'ai:search:'.md5($query.':'.($limitInt ?? 'default'));

        return $this->respond(
            $project,
            ComplianceAiGuardrailService::CONTEXT_SEARCH_CONTEXT,
            'ai-context.search',
            $segment,
            $frameworkKey,
            $releaseCode,
            fn () => $this->contextService->build(
                ComplianceAiGuardrailService::CONTEXT_SEARCH_CONTEXT,
                $frameworkKey,
                $releaseCode,
                ['query' => $query, 'limit' => $limitInt],
            ),
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
            $this->auditLogger->logAiContext($user, $project, $contextType, $endpoint, $frameworkKey, $releaseCode);
        }

        try {
            $data = $this->cacheService->remember($frameworkKey, $releaseCode, $segment, $builder);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 'invalid_search_query', 422);
        } catch (ComplianceAiContextException $e) {
            return $this->error($e->getMessage(), $e->errorCode(), 422);
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
