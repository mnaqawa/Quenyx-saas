<?php

namespace App\Http\Controllers\API;

use App\Exceptions\OpenAIServiceException;
use App\Http\Controllers\Controller;
use App\Http\Requests\AIAgentQueryRequest;
use App\Http\Resources\AIAgentAnswerResource;
use App\Models\Project;
use App\Services\OpenAI\OpenAIService;
use App\Services\ProjectAccessService;
use App\Support\SafeLog;
use Illuminate\Http\JsonResponse;

class AIAgentController extends Controller
{
    public function __construct(
        private readonly OpenAIService $service,
        private readonly ProjectAccessService $access,
    ) {
    }

    /**
     * POST /api/ai-agent/query
     *
     * Answers a question against the knowledge base (OpenAI Responses API +
     * File Search over the configured Vector Store). Optionally accepts a
     * workspace_id (membership verified) and QynSight operational context,
     * which are injected into the model prompt.
     */
    public function query(AIAgentQueryRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $context = [];

        // Optional workspace context — verify membership before using it.
        $workspaceId = $validated['workspace_id'] ?? null;
        if ($workspaceId !== null) {
            $project = Project::find($workspaceId);
            if ($project === null || ! $this->access->canViewProject($request->user(), $project)) {
                return response()->json([
                    'success' => false,
                    'code' => 'workspace_forbidden',
                    'message' => 'You do not have access to this workspace.',
                ], 403);
            }

            $context['workspace'] = [
                'id' => $project->id,
                'name' => $project->name,
            ];
        }

        // Optional QynSight runtime context from the frontend.
        if (! empty($validated['context'])) {
            $context['qynsight'] = $validated['context'];
        }

        try {
            $answer = $this->service->askKnowledgeBase(
                (string) $validated['question'],
                (string) $validated['agent'],
                $context,
                (bool) ($validated['quick'] ?? false),
            );
        } catch (OpenAIServiceException $e) {
            return response()->json([
                'success' => false,
                'code' => $e->errorCode,
                'message' => $e->getMessage(),
            ], $e->status);
        } catch (\Throwable $e) {
            SafeLog::error('ai-agent.query.unhandled', [
                'message' => $e->getMessage(),
                'class' => get_class($e),
            ]);

            return response()->json([
                'success' => false,
                'code' => 'server_error',
                'message' => 'The AI agent is temporarily unavailable. Please try again.',
            ], 500);
        }

        return AIAgentAnswerResource::make($answer)
            ->response()
            ->setStatusCode(200);
    }
}
