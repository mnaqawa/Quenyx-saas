<?php

namespace App\Http\Controllers\Compliance;

use App\DataTransferObjects\Ai\AiSkillRequest;
use App\Exceptions\Ai\AiSkillException;
use App\Exceptions\ComplianceCorpusNotFoundException;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\AI\Skills\RecommendationSkill;
use App\Services\Compliance\ComplianceCorpusAccessAuditLogger;
use App\Services\Compliance\ComplianceCorpusCacheService;
use App\Services\Compliance\Gap\GapAssessmentService;
use App\Services\Compliance\Recommendation\RecommendationGenerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Workspace-scoped API for the Recommendation Engine (QCIF Sprint 13).
 *
 * Converts deterministic gap findings into explainable, rule-based remediation recommendations.
 * All output is UUID-only; every recommendation references its requirement, control, gap status,
 * evidence considered, rule, rationale, priority basis, corpus revision, and framework release.
 *
 * NO LLM, NO RAG, NO provider calls, NO probabilistic scoring, NO automatic remediation. The GET
 * endpoints are read-only and cached (revision + evidence fingerprint). `generate` is the only
 * write path: it persists immutable, append-only recommendations and is idempotent (deterministic
 * UUIDs mean re-running the same state never duplicates). Access requires sanctum auth, project
 * membership, and the QynShield entitlement; every request is audit-logged and rate limited.
 */
class ComplianceRecommendationController extends Controller
{
    public function __construct(
        private readonly RecommendationGenerationService $generation,
        private readonly RecommendationSkill $skill,
        private readonly GapAssessmentService $gap,
        private readonly ComplianceCorpusCacheService $cacheService,
        private readonly ComplianceCorpusAccessAuditLogger $auditLogger,
    ) {}

    public function summary(Request $request, Project $project): JsonResponse
    {
        [$frameworkKey, $releaseCode] = $this->contextCodes($request);
        $includeCompliant = $this->boolQuery($request, 'include_compliant');

        return $this->respond(
            $request,
            $project,
            'recommendation_summary',
            'recommendations.summary',
            $this->segment($project, 'summary:'.($includeCompliant ? '1' : '0'), $frameworkKey, $releaseCode),
            $frameworkKey,
            $releaseCode,
            function () use ($frameworkKey, $releaseCode, $project, $includeCompliant) {
                $result = $this->generation->toPublic(
                    $this->generation->generate($frameworkKey, $releaseCode, $project->id, ['include_compliant' => $includeCompliant])
                );

                return array_merge($this->head($result), [
                    'summary' => $result['summary'],
                    'generated_at' => $result['generated_at'],
                ]);
            },
        );
    }

    public function control(Request $request, Project $project, string $controlCode): JsonResponse
    {
        [$frameworkKey, $releaseCode] = $this->contextCodes($request);
        $includeCompliant = $this->boolQuery($request, 'include_compliant');

        return $this->respond(
            $request,
            $project,
            'recommendation_control',
            'recommendations.control',
            $this->segment($project, 'control:'.md5($controlCode).':'.($includeCompliant ? '1' : '0'), $frameworkKey, $releaseCode),
            $frameworkKey,
            $releaseCode,
            fn () => $this->generation->generateForControl($frameworkKey, $releaseCode, $project->id, $controlCode, ['include_compliant' => $includeCompliant]),
        );
    }

    public function requirement(Request $request, Project $project, string $requirementCode): JsonResponse
    {
        [$frameworkKey, $releaseCode] = $this->contextCodes($request);
        $includeCompliant = $this->boolQuery($request, 'include_compliant');

        return $this->respond(
            $request,
            $project,
            'recommendation_requirement',
            'recommendations.requirement',
            $this->segment($project, 'requirement:'.md5($requirementCode).':'.($includeCompliant ? '1' : '0'), $frameworkKey, $releaseCode),
            $frameworkKey,
            $releaseCode,
            fn () => $this->generation->generateForRequirement($frameworkKey, $releaseCode, $project->id, $requirementCode, ['include_compliant' => $includeCompliant]),
        );
    }

    public function context(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $validated = $request->validate([
            'framework' => ['sometimes', 'nullable', 'string', 'max:64'],
            'release' => ['sometimes', 'nullable', 'string', 'max:64'],
            'parameters' => ['sometimes', 'nullable', 'array'],
        ]);

        $this->audit($request, $project, 'recommendation_context', 'recommendations.context', $validated['framework'] ?? null, $validated['release'] ?? null);

        $parameters = array_merge(
            is_array($validated['parameters'] ?? null) ? $validated['parameters'] : [],
            ['project_id' => $project->id],
        );

        $skillRequest = new AiSkillRequest(
            skill: 'recommendation',
            contextType: 'recommendation_context',
            frameworkKey: $validated['framework'] ?? null,
            releaseCode: $validated['release'] ?? null,
            parameters: $parameters,
        );

        try {
            $result = $this->skill->execute($skillRequest);
        } catch (AiSkillException $e) {
            return $this->error($e->getMessage(), $e->errorCode(), $e->httpStatus());
        }

        return response()->json([
            'success' => true,
            'data' => array_merge($result->payload, [
                'guardrails' => $result->guardrails,
                'warnings' => $result->warnings,
                'request_uuid' => $skillRequest->uuid,
            ]),
        ]);
    }

    public function generate(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $validated = $request->validate([
            'framework' => ['sometimes', 'nullable', 'string', 'max:64'],
            'release' => ['sometimes', 'nullable', 'string', 'max:64'],
            'include_compliant' => ['sometimes', 'boolean'],
        ]);

        $this->audit($request, $project, 'recommendation_generate', 'recommendations.generate', $validated['framework'] ?? null, $validated['release'] ?? null);

        try {
            $data = $this->generation->persist(
                $validated['framework'] ?? null,
                $validated['release'] ?? null,
                $project->id,
                $request->user()?->id,
                ['include_compliant' => (bool) ($validated['include_compliant'] ?? false)],
            );
        } catch (ComplianceCorpusNotFoundException $e) {
            return $this->error($e->getMessage(), 'corpus_not_found', 404);
        }

        return response()->json(['success' => true, 'data' => $data]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function contextCodes(Request $request): array
    {
        return $this->gap->resolveContextCodes(
            $this->stringQuery($request, 'framework'),
            $this->stringQuery($request, 'release'),
        );
    }

    private function segment(Project $project, string $suffix, ?string $frameworkKey, ?string $releaseCode): string
    {
        $fingerprint = $this->gap->evidenceFingerprint($project->id);

        return 'reco:'.$suffix.':'.$project->id.':'.$fingerprint;
    }

    private function stringQuery(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function boolQuery(Request $request, string $key): bool
    {
        return filter_var($request->query($key), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function head(array $result): array
    {
        return [
            'context_type' => $result['context_type'],
            'framework' => $result['framework'],
            'release' => $result['release'],
            'revision' => $result['revision'],
        ];
    }

    /**
     * @param  callable(): array<string, mixed>  $builder
     */
    private function respond(
        Request $request,
        Project $project,
        string $contextType,
        string $endpoint,
        string $segment,
        ?string $frameworkKey,
        ?string $releaseCode,
        callable $builder,
    ): JsonResponse {
        $this->authorize('view', $project);
        $this->audit($request, $project, $contextType, $endpoint, $frameworkKey, $releaseCode);

        try {
            if ($frameworkKey !== null && $releaseCode !== null) {
                $data = $this->cacheService->remember($frameworkKey, $releaseCode, $segment, $builder);
            } else {
                $data = $this->cacheService->rememberStatic($segment, $builder);
            }
        } catch (ComplianceCorpusNotFoundException $e) {
            return $this->error($e->getMessage(), 'corpus_not_found', 404);
        }

        return response()->json(['success' => true, 'data' => $data]);
    }

    private function audit(Request $request, Project $project, string $contextType, string $endpoint, ?string $frameworkKey, ?string $releaseCode): void
    {
        $user = $request->user();
        if ($user !== null) {
            $this->auditLogger->logRecommendation($user, $project, $contextType, $endpoint, $frameworkKey, $releaseCode);
        }
    }

    private function error(string $message, string $code, int $status): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message, 'code' => $code], $status);
    }
}
