<?php

namespace App\Http\Controllers\Compliance;

use App\DataTransferObjects\Ai\AiSkillRequest;
use App\Exceptions\Ai\AiSkillException;
use App\Exceptions\ComplianceCorpusNotFoundException;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\AI\Skills\GapAssessmentSkill;
use App\Services\Compliance\ComplianceCorpusAccessAuditLogger;
use App\Services\Compliance\ComplianceCorpusCacheService;
use App\Services\Compliance\Gap\GapAssessmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Workspace-scoped, READ-ONLY API for the Gap Assessment & Evidence Correlation Engine
 * (QCIF Sprint 12) — the first deterministic Compliance Intelligence Engine.
 *
 * All output is UUID-only and fully explainable; every finding references its requirement,
 * evidence, evaluation rule, corpus revision, and framework release. NO AI execution, no provider
 * calls, no probabilistic scoring. Access requires sanctum auth, project membership, and the
 * QynShield entitlement; every request is audit-logged and cached against the active corpus
 * revision PLUS a workspace evidence fingerprint (so cached results invalidate when evidence
 * changes). Reads never persist — assessment history is written only by scheduled/triggered runs.
 */
class ComplianceGapController extends Controller
{
    public function __construct(
        private readonly GapAssessmentService $gap,
        private readonly GapAssessmentSkill $gapSkill,
        private readonly ComplianceCorpusCacheService $cacheService,
        private readonly ComplianceCorpusAccessAuditLogger $auditLogger,
    ) {}

    public function summary(Request $request, Project $project): JsonResponse
    {
        [$frameworkKey, $releaseCode] = $this->contextCodes($request);

        return $this->respond(
            $request,
            $project,
            'gap_summary',
            'gap.summary',
            $this->segment($project, 'summary', $frameworkKey, $releaseCode),
            $frameworkKey,
            $releaseCode,
            function () use ($frameworkKey, $releaseCode, $project) {
                $result = $this->gap->assess($frameworkKey, $releaseCode, $project->id);

                return array_merge($this->head($result), [
                    'summary' => $result['summary'],
                    'correlation' => $result['correlation'],
                    'generated_at' => $result['generated_at'],
                ]);
            },
        );
    }

    public function domains(Request $request, Project $project): JsonResponse
    {
        [$frameworkKey, $releaseCode] = $this->contextCodes($request);

        return $this->respond(
            $request,
            $project,
            'gap_domains',
            'gap.domains',
            $this->segment($project, 'domains', $frameworkKey, $releaseCode),
            $frameworkKey,
            $releaseCode,
            function () use ($frameworkKey, $releaseCode, $project) {
                $result = $this->gap->assess($frameworkKey, $releaseCode, $project->id);

                return array_merge($this->head($result), [
                    'workspace' => $result['coverage']['workspace'],
                    'framework' => $result['coverage']['framework'],
                    'domains' => $result['coverage']['domains'],
                    'generated_at' => $result['generated_at'],
                ]);
            },
        );
    }

    public function control(Request $request, Project $project, string $controlCode): JsonResponse
    {
        [$frameworkKey, $releaseCode] = $this->contextCodes($request);

        return $this->respond(
            $request,
            $project,
            'gap_control',
            'gap.control',
            $this->segment($project, 'control:'.md5($controlCode), $frameworkKey, $releaseCode),
            $frameworkKey,
            $releaseCode,
            fn () => $this->gap->assessControl($frameworkKey, $releaseCode, $project->id, $controlCode),
        );
    }

    public function requirement(Request $request, Project $project, string $requirementCode): JsonResponse
    {
        [$frameworkKey, $releaseCode] = $this->contextCodes($request);

        return $this->respond(
            $request,
            $project,
            'gap_requirement',
            'gap.requirement',
            $this->segment($project, 'requirement:'.md5($requirementCode), $frameworkKey, $releaseCode),
            $frameworkKey,
            $releaseCode,
            fn () => $this->gap->assessRequirement($frameworkKey, $releaseCode, $project->id, $requirementCode),
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

        $this->audit($request, $project, 'gap_context', 'gap.context', $validated['framework'] ?? null, $validated['release'] ?? null);

        $parameters = array_merge(
            is_array($validated['parameters'] ?? null) ? $validated['parameters'] : [],
            ['project_id' => $project->id],
        );

        $skillRequest = new AiSkillRequest(
            skill: 'gap_assessment',
            contextType: 'gap_context',
            frameworkKey: $validated['framework'] ?? null,
            releaseCode: $validated['release'] ?? null,
            parameters: $parameters,
        );

        try {
            $result = $this->gapSkill->execute($skillRequest);
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
        // Revision-aware cache (frameworkKey/releaseCode + active revision UUID via the cache
        // service) PLUS an evidence fingerprint so the cache invalidates when tenant evidence
        // changes — a gap depends on both corpus and evidence.
        $fingerprint = $this->gap->evidenceFingerprint($project->id);

        return 'gap:'.$suffix.':'.$project->id.':'.$fingerprint;
    }

    private function stringQuery(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && $value !== '' ? $value : null;
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
            $this->auditLogger->logGap($user, $project, $contextType, $endpoint, $frameworkKey, $releaseCode);
        }
    }

    private function error(string $message, string $code, int $status): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message, 'code' => $code], $status);
    }
}
