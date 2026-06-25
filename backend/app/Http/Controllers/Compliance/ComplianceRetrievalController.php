<?php

namespace App\Http\Controllers\Compliance;

use App\DataTransferObjects\Compliance\Retrieval\RetrievalQuery;
use App\Enums\Compliance\Retrieval\ComplianceRetrievalMode;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\Compliance\ComplianceCorpusAccessAuditLogger;
use App\Services\Compliance\ComplianceCorpusCacheService;
use App\Services\Compliance\Retrieval\ComplianceRetrievalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Workspace-scoped deterministic retrieval API (QCIF Sprint 15).
 *
 * Turns a query + mode into ranked, cited retrieval chunks for future RAG. UUID-only, citation-
 * backed, fully explainable ranking. NO vector DB, NO embeddings, NO external retrieval provider,
 * NO AI provider calls. Revision-stable modes with an explicit framework/release are cached via the
 * corpus cache. Access: sanctum + project membership + QynShield entitlement + audit + rate limit.
 */
class ComplianceRetrievalController extends Controller
{
    /** Modes whose output depends only on the corpus revision (safe to cache). */
    private const CACHEABLE_MODES = ['corpus_only', 'graph_expanded'];

    public function __construct(
        private readonly ComplianceRetrievalService $retrieval,
        private readonly ComplianceCorpusCacheService $cacheService,
        private readonly ComplianceCorpusAccessAuditLogger $auditLogger,
    ) {}

    public function query(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $validated = $request->validate([
            'query' => ['required', 'string', 'max:2000'],
            'mode' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', ComplianceRetrievalMode::values())],
            'framework' => ['sometimes', 'nullable', 'string', 'max:64'],
            'release' => ['sometimes', 'nullable', 'string', 'max:64'],
            'limit' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $mode = ComplianceRetrievalMode::fromName($validated['mode'] ?? null);
        $framework = $this->str($validated['framework'] ?? null);
        $release = $this->str($validated['release'] ?? null);

        $query = new RetrievalQuery(
            query: (string) $validated['query'],
            mode: $mode,
            projectId: $project->id,
            framework: $framework,
            release: $release,
            limit: (int) ($validated['limit'] ?? 20),
        );

        $user = $request->user();
        if ($user !== null) {
            $this->auditLogger->logRetrieval($user, $project, 'retrieval.query', $mode->value, $framework, $release);
        }

        $builder = fn () => $this->retrieval->query($query)->toArray();

        if (in_array($mode->value, self::CACHEABLE_MODES, true) && $framework !== null && $release !== null) {
            $segment = 'retrieval:'.$mode->value.':'.md5($query->query).':'.$query->limit;
            $data = $this->cacheService->remember($framework, $release, $segment, $builder);
        } else {
            $data = $builder();
        }

        // An explicitly-provided framework/release that does not exist fails clearly (422).
        if (($data['scope']['source'] ?? null) === 'invalid') {
            return response()->json([
                'success' => false,
                'error_code' => 'scope_unresolved',
                'data' => $data,
            ], 422);
        }

        return response()->json(['success' => true, 'data' => $data]);
    }

    private function str(?string $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
