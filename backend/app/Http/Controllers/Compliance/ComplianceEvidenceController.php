<?php

namespace App\Http\Controllers\Compliance;

use App\DataTransferObjects\Ai\AiSkillRequest;
use App\Exceptions\Ai\AiSkillException;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\AI\Skills\EvidenceSkill;
use App\Services\Compliance\ComplianceCorpusAccessAuditLogger;
use App\Services\Compliance\Evidence\EvidenceLifecycleService;
use App\Services\Compliance\Evidence\EvidenceNormalizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Workspace-scoped, READ-ONLY API for the Evidence Intelligence Foundation (QCIF Sprint 11).
 *
 * Exposes evidence context, the evidence type catalog, and the lifecycle status catalog. All
 * output is UUID-only. NO AI execution, no uploads, no file/blob/OCR. Access requires sanctum
 * auth, project membership, and the QynShield entitlement; every request is audit-logged.
 */
class ComplianceEvidenceController extends Controller
{
    public function __construct(
        private readonly EvidenceSkill $evidenceSkill,
        private readonly EvidenceNormalizationService $normalization,
        private readonly EvidenceLifecycleService $lifecycle,
        private readonly ComplianceCorpusAccessAuditLogger $auditLogger,
    ) {}

    public function context(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $validated = $request->validate([
            'framework' => ['sometimes', 'nullable', 'string', 'max:64'],
            'release' => ['sometimes', 'nullable', 'string', 'max:64'],
            'parameters' => ['sometimes', 'nullable', 'array'],
        ]);

        $parameters = array_merge(
            is_array($validated['parameters'] ?? null) ? $validated['parameters'] : [],
            ['project_id' => $project->id],
        );

        $this->audit($request, $project, 'evidence_context', 'evidence.context', $validated);

        $skillRequest = new AiSkillRequest(
            skill: 'evidence',
            contextType: 'evidence_context',
            frameworkKey: $validated['framework'] ?? null,
            releaseCode: $validated['release'] ?? null,
            parameters: $parameters,
        );

        try {
            $result = $this->evidenceSkill->execute($skillRequest);
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

    public function types(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);
        $this->audit($request, $project, 'evidence_types', 'evidence.types');

        return response()->json([
            'success' => true,
            'data' => [
                'types' => $this->normalization->typeCatalog(),
            ],
        ]);
    }

    public function statuses(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);
        $this->audit($request, $project, 'evidence_statuses', 'evidence.statuses');

        return response()->json([
            'success' => true,
            'data' => [
                'statuses' => $this->lifecycle->statusCatalog(),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function audit(Request $request, Project $project, string $contextType, string $endpoint, array $validated = []): void
    {
        $user = $request->user();
        if ($user !== null) {
            $this->auditLogger->logEvidence(
                $user,
                $project,
                $contextType,
                $endpoint,
                $validated['framework'] ?? null,
                $validated['release'] ?? null,
            );
        }
    }

    private function error(string $message, string $code, int $status): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message, 'code' => $code], $status);
    }
}
