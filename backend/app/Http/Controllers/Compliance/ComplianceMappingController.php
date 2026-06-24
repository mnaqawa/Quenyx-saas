<?php

namespace App\Http\Controllers\Compliance;

use App\Exceptions\ComplianceCorpusNotFoundException;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\Compliance\ComplianceCorpusAccessAuditLogger;
use App\Services\Compliance\ComplianceCorpusCacheService;
use App\Services\Compliance\Mapping\ComplianceFrameworkComparisonService;
use App\Services\Compliance\Mapping\ComplianceMappingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Workspace-scoped, read-only API for the Cross-Framework Mapping Foundation (QCIF Sprint 8).
 *
 * Returns deterministic, UUID-only objective-based mappings. Empty where no data exists; no
 * fabricated relationships; confidence is a basis (official|manual|derived), never a numeric
 * score. NO AI execution. Access requires sanctum auth, project membership, and the QynShield
 * entitlement; every request is audit-logged and cached against the active corpus revision.
 */
class ComplianceMappingController extends Controller
{
    public function __construct(
        private readonly ComplianceMappingService $mappingService,
        private readonly ComplianceFrameworkComparisonService $comparisonService,
        private readonly ComplianceCorpusCacheService $cacheService,
        private readonly ComplianceCorpusAccessAuditLogger $auditLogger,
    ) {}

    public function objectives(Request $request, Project $project): JsonResponse
    {
        [$frameworkKey, $releaseCode] = $this->contextCodes($request);

        return $this->respond(
            $project,
            'control_objectives',
            'mappings.objectives',
            'map:objectives',
            $frameworkKey,
            $releaseCode,
            fn () => $this->mappingService->getControlObjectives($frameworkKey, $releaseCode),
        );
    }

    public function objective(Request $request, Project $project, string $objectiveCode): JsonResponse
    {
        [$frameworkKey, $releaseCode] = $this->contextCodes($request);

        return $this->respond(
            $project,
            'objective_mapping',
            'mappings.objective',
            'map:objective:'.md5($objectiveCode),
            $frameworkKey,
            $releaseCode,
            fn () => $this->mappingService->getObjectiveMapping($objectiveCode, $frameworkKey, $releaseCode),
        );
    }

    public function control(Request $request, Project $project, string $controlCode): JsonResponse
    {
        [$frameworkKey, $releaseCode] = $this->contextCodes($request);

        return $this->respond(
            $project,
            'control_mapping',
            'mappings.control',
            'map:control:'.md5($controlCode),
            $frameworkKey,
            $releaseCode,
            fn () => $this->mappingService->getControlMapping($controlCode, $frameworkKey, $releaseCode),
        );
    }

    public function coverage(Request $request, Project $project, string $frameworkKey): JsonResponse
    {
        $releaseCode = $this->stringQuery($request, 'release');

        return $this->respond(
            $project,
            'framework_coverage',
            'mappings.coverage',
            'map:coverage:'.md5($frameworkKey.':'.($releaseCode ?? '')),
            $frameworkKey,
            $releaseCode,
            fn () => $this->comparisonService->getFrameworkCoverage($frameworkKey, $releaseCode),
        );
    }

    public function compare(Request $request, Project $project): JsonResponse
    {
        $source = $this->stringQuery($request, 'source');
        $target = $this->stringQuery($request, 'target');
        $sourceRelease = $this->stringQuery($request, 'source_release');
        $targetRelease = $this->stringQuery($request, 'target_release');

        if ($source === null || $target === null) {
            return $this->error("Both 'source' and 'target' framework keys are required.", 'invalid_comparison', 422);
        }

        $segment = 'map:compare:'.md5(implode(':', [$source, $sourceRelease ?? '', $target, $targetRelease ?? '']));

        return $this->respond(
            $project,
            'framework_comparison',
            'mappings.compare',
            $segment,
            $source,
            $sourceRelease,
            fn () => $this->comparisonService->getFrameworkComparison($source, $target, $sourceRelease, $targetRelease),
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function contextCodes(Request $request): array
    {
        return $this->mappingService->resolveContextCodes(
            $this->stringQuery($request, 'framework'),
            $this->stringQuery($request, 'release'),
        );
    }

    private function stringQuery(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  callable(): array<string, mixed>  $builder
     */
    private function respond(
        Project $project,
        string $contextType,
        string $endpoint,
        string $segment,
        ?string $frameworkKey,
        ?string $releaseCode,
        callable $builder,
    ): JsonResponse {
        $this->authorize('view', $project);

        $user = request()->user();
        if ($user !== null) {
            $this->auditLogger->logMapping($user, $project, $contextType, $endpoint, $frameworkKey, $releaseCode);
        }

        try {
            if ($frameworkKey !== null && $releaseCode !== null) {
                $data = $this->cacheService->remember($frameworkKey, $releaseCode, $segment, $builder);
            } else {
                $data = $this->cacheService->rememberStatic($segment, $builder);
            }
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
