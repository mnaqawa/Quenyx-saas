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
    ) {}

    public function frameworks(): JsonResponse
    {
        $frameworks = $this->navigationService->listFrameworks();

        return response()->json([
            'success' => true,
            'data' => ComplianceFrameworkResource::collection($frameworks),
        ]);
    }

    public function releases(string $frameworkKey): JsonResponse
    {
        try {
            $this->queryService->resolveReleaseFramework($frameworkKey);
            $releases = $this->navigationService->listReleases($frameworkKey);
        } catch (ComplianceCorpusNotFoundException $e) {
            return $this->notFound($e->getMessage());
        }

        return response()->json([
            'success' => true,
            'data' => ComplianceFrameworkReleaseResource::collection($releases),
        ]);
    }

    public function summary(string $frameworkKey, string $releaseCode): JsonResponse
    {
        try {
            $summary = $this->queryService->getSummary($frameworkKey, $releaseCode);
        } catch (ComplianceCorpusNotFoundException $e) {
            return $this->notFound($e->getMessage());
        }

        return response()->json([
            'success' => true,
            'data' => [
                'framework' => ComplianceFrameworkResource::make($summary['framework']),
                'release' => ComplianceFrameworkReleaseResource::make($summary['release']),
                'active_revision' => ComplianceCorpusRevisionResource::make($summary['active_revision']),
                'counts' => $summary['counts'],
                'source_documents' => ComplianceSourceDocumentResource::collection($summary['source_documents']),
                'pending_manual_review' => $summary['pending_manual_review'],
            ],
        ]);
    }

    public function domains(string $frameworkKey, string $releaseCode): JsonResponse
    {
        try {
            $release = $this->queryService->resolveRelease($frameworkKey, $releaseCode);
            $revision = $this->queryService->getActiveRevision($release);
            $domains = $this->navigationService->listDomains($frameworkKey, $releaseCode);
        } catch (ComplianceCorpusNotFoundException $e) {
            return $this->notFound($e->getMessage());
        }

        return response()->json([
            'success' => true,
            'data' => [
                'framework' => ComplianceFrameworkResource::make($release->framework),
                'release' => ComplianceFrameworkReleaseResource::make($release),
                'active_revision' => ComplianceCorpusRevisionResource::make($revision),
                'domains' => ComplianceDomainResource::collection($domains),
            ],
        ]);
    }

    public function domain(string $frameworkKey, string $releaseCode, string $domainCode): JsonResponse
    {
        try {
            $release = $this->queryService->resolveRelease($frameworkKey, $releaseCode);
            $revision = $this->queryService->getActiveRevision($release);
            $payload = $this->navigationService->getDomainWithControls($frameworkKey, $releaseCode, $domainCode);
        } catch (ComplianceCorpusNotFoundException $e) {
            return $this->notFound($e->getMessage());
        }

        return response()->json([
            'success' => true,
            'data' => [
                'framework' => ComplianceFrameworkResource::make($release->framework),
                'release' => ComplianceFrameworkReleaseResource::make($release),
                'active_revision' => ComplianceCorpusRevisionResource::make($revision),
                'domain' => ComplianceDomainResource::make($payload['domain']),
                'controls' => ComplianceControlResource::collection($payload['controls']),
            ],
        ]);
    }

    public function control(string $frameworkKey, string $releaseCode, string $controlCode): JsonResponse
    {
        try {
            $profile = $this->queryService->getControlProfile($frameworkKey, $releaseCode, $controlCode);
        } catch (ComplianceCorpusNotFoundException $e) {
            return $this->notFound($e->getMessage());
        }

        return response()->json([
            'success' => true,
            'data' => [
                'framework' => ComplianceFrameworkResource::make($profile['framework']),
                'release' => ComplianceFrameworkReleaseResource::make($profile['release']),
                'revision' => ComplianceCorpusRevisionResource::make($profile['revision']),
                'domain' => ComplianceDomainResource::make($profile['domain']),
                'control' => ComplianceControlResource::make($profile['control']),
                'requirements' => ComplianceRequirementResource::collection($profile['requirements']),
            ],
        ]);
    }

    public function search(Request $request, string $frameworkKey, string $releaseCode): JsonResponse
    {
        $query = (string) $request->query('q', '');
        $limit = $request->query('limit');

        try {
            $results = $this->searchService->search(
                $frameworkKey,
                $releaseCode,
                $query,
                $limit !== null ? (int) $limit : null,
            );
            $release = $this->queryService->resolveRelease($frameworkKey, $releaseCode);
            $revision = $this->queryService->getActiveRevision($release);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'code' => 'invalid_search_query',
            ], 422);
        } catch (ComplianceCorpusNotFoundException $e) {
            return $this->notFound($e->getMessage());
        }

        return response()->json([
            'success' => true,
            'data' => [
                'framework' => ComplianceFrameworkResource::make($release->framework),
                'release' => ComplianceFrameworkReleaseResource::make($release),
                'active_revision' => ComplianceCorpusRevisionResource::make($revision),
                'query' => $results['query'],
                'limit' => $results['limit'],
                'results' => [
                    'domains' => ComplianceDomainResource::collection($results['domains']),
                    'controls' => ComplianceControlResource::collection($results['controls']),
                    'requirements' => ComplianceRequirementResource::collection($results['requirements']),
                ],
            ],
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
