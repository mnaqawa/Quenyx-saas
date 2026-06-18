<?php

namespace App\Http\Controllers\Compliance;

use App\Exceptions\ComplianceCorpusNotFoundException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Compliance\ComplianceControlResource;
use App\Http\Resources\Compliance\ComplianceCorpusRevisionResource;
use App\Http\Resources\Compliance\ComplianceDomainResource;
use App\Http\Resources\Compliance\ComplianceFrameworkReleaseResource;
use App\Http\Resources\Compliance\ComplianceFrameworkResource;
use App\Http\Resources\Compliance\ComplianceRequirementResource;
use App\Http\Resources\Compliance\ComplianceSourceDocumentResource;
use App\Models\Project;
use App\Services\Compliance\ComplianceCorpusAccessAuditLogger;
use App\Services\Compliance\ComplianceCorpusCacheService;
use App\Services\Compliance\ComplianceCorpusNavigationService;
use App\Services\Compliance\ComplianceCorpusQueryService;
use App\Services\Compliance\ComplianceCorpusSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class ComplianceCorpusController extends Controller
{
    public function __construct(
        private readonly ComplianceCorpusQueryService $queryService,
        private readonly ComplianceCorpusNavigationService $navigationService,
        private readonly ComplianceCorpusSearchService $searchService,
        private readonly ComplianceCorpusCacheService $cacheService,
        private readonly ComplianceCorpusAccessAuditLogger $auditLogger,
    ) {}

    // -------------------------------------------------------------------------
    // Global corpus API (internal / future AI — auth:sanctum only)
    // -------------------------------------------------------------------------

    public function frameworks(): JsonResponse
    {
        try {
            $data = $this->cacheService->rememberStatic('frameworks', fn () => $this->buildFrameworksData());
        } catch (ComplianceCorpusNotFoundException $e) {
            return $this->notFound($e->getMessage());
        }

        return $this->success($data);
    }

    public function releases(string $frameworkKey): JsonResponse
    {
        try {
            $data = $this->cacheService->rememberStatic(
                "releases:{$frameworkKey}",
                fn () => $this->buildReleasesData($frameworkKey),
            );
        } catch (ComplianceCorpusNotFoundException $e) {
            return $this->notFound($e->getMessage());
        }

        return $this->success($data);
    }

    public function summary(string $frameworkKey, string $releaseCode): JsonResponse
    {
        try {
            $data = $this->cacheService->remember(
                $frameworkKey,
                $releaseCode,
                'summary',
                fn () => $this->buildSummaryData($frameworkKey, $releaseCode),
            );
        } catch (ComplianceCorpusNotFoundException $e) {
            return $this->notFound($e->getMessage());
        }

        return $this->success($data);
    }

    public function domains(string $frameworkKey, string $releaseCode): JsonResponse
    {
        try {
            $data = $this->cacheService->remember(
                $frameworkKey,
                $releaseCode,
                'domains',
                fn () => $this->buildDomainsData($frameworkKey, $releaseCode),
            );
        } catch (ComplianceCorpusNotFoundException $e) {
            return $this->notFound($e->getMessage());
        }

        return $this->success($data);
    }

    public function domain(string $frameworkKey, string $releaseCode, string $domainCode): JsonResponse
    {
        try {
            $data = $this->cacheService->remember(
                $frameworkKey,
                $releaseCode,
                "domain:{$domainCode}",
                fn () => $this->buildDomainData($frameworkKey, $releaseCode, $domainCode),
            );
        } catch (ComplianceCorpusNotFoundException $e) {
            return $this->notFound($e->getMessage());
        }

        return $this->success($data);
    }

    public function control(string $frameworkKey, string $releaseCode, string $controlCode): JsonResponse
    {
        try {
            $data = $this->cacheService->remember(
                $frameworkKey,
                $releaseCode,
                "control:{$controlCode}",
                fn () => $this->buildControlData($frameworkKey, $releaseCode, $controlCode),
            );
        } catch (ComplianceCorpusNotFoundException $e) {
            return $this->notFound($e->getMessage());
        }

        return $this->success($data);
    }

    public function search(Request $request, string $frameworkKey, string $releaseCode): JsonResponse
    {
        $query = (string) $request->query('q', '');
        $limit = $request->query('limit');
        $limitInt = $limit !== null ? (int) $limit : null;
        $segment = 'search:'.md5($query.':'.($limitInt ?? 'default'));

        try {
            $data = $this->cacheService->remember(
                $frameworkKey,
                $releaseCode,
                $segment,
                fn () => $this->buildSearchData($frameworkKey, $releaseCode, $query, $limitInt),
            );
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'code' => 'invalid_search_query',
            ], 422);
        } catch (ComplianceCorpusNotFoundException $e) {
            return $this->notFound($e->getMessage());
        }

        return $this->success($data);
    }

    // -------------------------------------------------------------------------
    // Workspace-scoped corpus API (SaaS / QynShield — membership + entitlement)
    // -------------------------------------------------------------------------

    public function workspaceFrameworks(Project $project): JsonResponse
    {
        return $this->withWorkspaceAccess($project, 'frameworks', fn () => $this->frameworks());
    }

    public function workspaceReleases(Project $project, string $frameworkKey): JsonResponse
    {
        return $this->withWorkspaceAccess($project, 'releases', fn () => $this->releases($frameworkKey), $frameworkKey);
    }

    public function workspaceSummary(Project $project, string $frameworkKey, string $releaseCode): JsonResponse
    {
        return $this->withWorkspaceAccess($project, 'summary', fn () => $this->summary($frameworkKey, $releaseCode), $frameworkKey, $releaseCode);
    }

    public function workspaceDomains(Project $project, string $frameworkKey, string $releaseCode): JsonResponse
    {
        return $this->withWorkspaceAccess($project, 'domains', fn () => $this->domains($frameworkKey, $releaseCode), $frameworkKey, $releaseCode);
    }

    public function workspaceDomain(Project $project, string $frameworkKey, string $releaseCode, string $domainCode): JsonResponse
    {
        return $this->withWorkspaceAccess($project, 'domain', fn () => $this->domain($frameworkKey, $releaseCode, $domainCode), $frameworkKey, $releaseCode);
    }

    public function workspaceControl(Project $project, string $frameworkKey, string $releaseCode, string $controlCode): JsonResponse
    {
        return $this->withWorkspaceAccess($project, 'control', fn () => $this->control($frameworkKey, $releaseCode, $controlCode), $frameworkKey, $releaseCode);
    }

    public function workspaceSearch(Request $request, Project $project, string $frameworkKey, string $releaseCode): JsonResponse
    {
        return $this->withWorkspaceAccess(
            $project,
            'search',
            fn () => $this->search($request, $frameworkKey, $releaseCode),
            $frameworkKey,
            $releaseCode,
        );
    }

    // -------------------------------------------------------------------------
    // Payload builders (uncached data assembly)
    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function buildFrameworksData(): array
    {
        $frameworks = $this->navigationService->listFrameworks();

        return ComplianceFrameworkResource::collection($frameworks)->resolve();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildReleasesData(string $frameworkKey): array
    {
        $this->queryService->resolveReleaseFramework($frameworkKey);
        $releases = $this->navigationService->listReleases($frameworkKey);

        return ComplianceFrameworkReleaseResource::collection($releases)->resolve();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSummaryData(string $frameworkKey, string $releaseCode): array
    {
        $summary = $this->queryService->getSummary($frameworkKey, $releaseCode);

        return [
            'framework' => ComplianceFrameworkResource::make($summary['framework'])->resolve(),
            'release' => ComplianceFrameworkReleaseResource::make($summary['release'])->resolve(),
            'active_revision' => ComplianceCorpusRevisionResource::make($summary['active_revision'])->resolve(),
            'counts' => $summary['counts'],
            'source_documents' => ComplianceSourceDocumentResource::collection($summary['source_documents'])->resolve(),
            'pending_manual_review' => $summary['pending_manual_review'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDomainsData(string $frameworkKey, string $releaseCode): array
    {
        $release = $this->queryService->resolveRelease($frameworkKey, $releaseCode);
        $revision = $this->queryService->getActiveRevision($release);
        $domains = $this->navigationService->listDomains($frameworkKey, $releaseCode);

        return [
            'framework' => ComplianceFrameworkResource::make($release->framework)->resolve(),
            'release' => ComplianceFrameworkReleaseResource::make($release)->resolve(),
            'active_revision' => ComplianceCorpusRevisionResource::make($revision)->resolve(),
            'domains' => ComplianceDomainResource::collection($domains)->resolve(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDomainData(string $frameworkKey, string $releaseCode, string $domainCode): array
    {
        $release = $this->queryService->resolveRelease($frameworkKey, $releaseCode);
        $revision = $this->queryService->getActiveRevision($release);
        $payload = $this->navigationService->getDomainWithControls($frameworkKey, $releaseCode, $domainCode);

        return [
            'framework' => ComplianceFrameworkResource::make($release->framework)->resolve(),
            'release' => ComplianceFrameworkReleaseResource::make($release)->resolve(),
            'active_revision' => ComplianceCorpusRevisionResource::make($revision)->resolve(),
            'domain' => ComplianceDomainResource::make($payload['domain'])->resolve(),
            'controls' => ComplianceControlResource::collection($payload['controls'])->resolve(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildControlData(string $frameworkKey, string $releaseCode, string $controlCode): array
    {
        $profile = $this->queryService->getControlProfile($frameworkKey, $releaseCode, $controlCode);

        return [
            'framework' => ComplianceFrameworkResource::make($profile['framework'])->resolve(),
            'release' => ComplianceFrameworkReleaseResource::make($profile['release'])->resolve(),
            'revision' => ComplianceCorpusRevisionResource::make($profile['revision'])->resolve(),
            'domain' => ComplianceDomainResource::make($profile['domain'])->resolve(),
            'control' => ComplianceControlResource::make($profile['control'])->resolve(),
            'requirements' => ComplianceRequirementResource::collection($profile['requirements'])->resolve(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSearchData(string $frameworkKey, string $releaseCode, string $query, ?int $limit): array
    {
        $results = $this->searchService->search($frameworkKey, $releaseCode, $query, $limit);
        $release = $this->queryService->resolveRelease($frameworkKey, $releaseCode);
        $revision = $this->queryService->getActiveRevision($release);

        return [
            'framework' => ComplianceFrameworkResource::make($release->framework)->resolve(),
            'release' => ComplianceFrameworkReleaseResource::make($release)->resolve(),
            'active_revision' => ComplianceCorpusRevisionResource::make($revision)->resolve(),
            'query' => $results['query'],
            'limit' => $results['limit'],
            'results' => [
                'domains' => ComplianceDomainResource::collection($results['domains'])->resolve(),
                'controls' => ComplianceControlResource::collection($results['controls'])->resolve(),
                'requirements' => ComplianceRequirementResource::collection($results['requirements'])->resolve(),
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function withWorkspaceAccess(
        Project $project,
        string $endpoint,
        callable $handler,
        ?string $framework = null,
        ?string $release = null,
    ): JsonResponse {
        $this->authorize('view', $project);

        $user = request()->user();
        if ($user !== null) {
            $this->auditLogger->log($user, $project, $endpoint, $framework, $release);
        }

        return $handler();
    }

    /**
     * @param array<string, mixed>|list<mixed> $data
     */
    private function success(array $data): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    private function notFound(string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'code' => 'corpus_not_found',
        ], 404);
    }
}
