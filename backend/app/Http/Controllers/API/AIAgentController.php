<?php

namespace App\Http\Controllers\API;

use App\Exceptions\OpenAIServiceException;
use App\Http\Controllers\Controller;
use App\Http\Requests\AIAgentQueryRequest;
use App\Http\Resources\AIAgentAnswerResource;
use App\Services\OpenAI\OpenAIService;
use Illuminate\Http\JsonResponse;

class AIAgentController extends Controller
{
    public function __construct(private readonly OpenAIService $service)
    {
    }

    /**
     * POST /api/ai-agent/query
     *
     * Answers a question against the knowledge base (OpenAI Responses API +
     * File Search over the configured Vector Store).
     */
    public function query(AIAgentQueryRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            $answer = $this->service->askKnowledgeBase(
                (string) $validated['question'],
                (string) $validated['agent'],
            );
        } catch (OpenAIServiceException $e) {
            return response()->json([
                'success' => false,
                'code' => $e->errorCode,
                'message' => $e->getMessage(),
            ], $e->status);
        }

        return AIAgentAnswerResource::make($answer)
            ->response()
            ->setStatusCode(200);
    }
}
