<?php

namespace App\Http\Controllers\Ai;

use App\DataTransferObjects\Ai\AiSkillRequest;
use App\Exceptions\Ai\AiSkillException;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\AI\AiAccessAuditLogger;
use App\Services\AI\Skills\AiSkillRegistry;
use App\Services\AI\Skills\AiSkillRouter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Workspace-scoped AI Skills API (QCIF Sprint 10). Executes a single skill and returns ONLY an
 * AiSkillResponse — never an AI provider response. Skills produce corpus-derived AI Context
 * payloads; no model is ever contacted here.
 */
class AiSkillController extends Controller
{
    public function __construct(
        private readonly AiSkillRouter $router,
        private readonly AiSkillRegistry $registry,
        private readonly AiAccessAuditLogger $auditLogger,
    ) {}

    public function execute(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $validated = $request->validate([
            'skill' => ['sometimes', 'nullable', 'string', 'max:64'],
            'context' => ['sometimes', 'nullable', 'string', 'max:64'],
            'framework' => ['sometimes', 'nullable', 'string', 'max:64'],
            'release' => ['sometimes', 'nullable', 'string', 'max:64'],
            'parameters' => ['sometimes', 'nullable', 'array'],
        ]);

        if (! (bool) config('ai.skills.enabled', true)) {
            return $this->error('The AI Skills Framework is disabled.', 'ai_skills_disabled', 422);
        }

        $skillRequest = new AiSkillRequest(
            skill: $validated['skill'] ?? null,
            contextType: $validated['context'] ?? null,
            frameworkKey: $validated['framework'] ?? null,
            releaseCode: $validated['release'] ?? null,
            parameters: $validated['parameters'] ?? [],
        );

        $this->auditLogger->log(
            $request->user(),
            $project,
            'ai_skill_execute',
            'ai.skills.execute',
            $skillRequest->skill ?? 'auto',
            ['context_type' => $skillRequest->contextType, 'request_uuid' => $skillRequest->uuid],
        );

        try {
            $response = $this->router->execute($skillRequest);
        } catch (AiSkillException $e) {
            return $this->error($e->getMessage(), $e->errorCode(), $e->httpStatus());
        }

        return response()->json([
            'success' => true,
            'data' => array_merge($response->toArray(), [
                'request_uuid' => $skillRequest->uuid,
                'generated_at' => now()->toIso8601String(),
            ]),
        ]);
    }

    public function skills(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json([
            'success' => true,
            'data' => [
                'enabled' => (bool) config('ai.skills.enabled', true),
                'skills' => $this->registry->describe(),
            ],
        ]);
    }

    private function error(string $message, string $code, int $status): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message, 'code' => $code], $status);
    }
}
