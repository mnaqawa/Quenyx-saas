<?php

namespace App\Http\Controllers;

use App\Http\Requests\AiChatRequest;
use App\Models\Project;
use App\Services\AI\AiAgentService;
use App\Services\AI\AiException;
use App\Services\AI\LlmClient;
use App\Services\AI\Personas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * LEGACY AI agent for QynSight (streaming chat-completions + personas).
 *
 * @deprecated Superseded by /api/ai-agent/query (App\Http\Controllers\API\AIAgentController)
 *             and components/ai/AIAgentDrawer.tsx, which use the OpenAI Responses API +
 *             File Search over the Vector Store. Kept for backward compatibility.
 * TODO: Remove once all clients migrate to the knowledge-base agent.
 */
class AiAgentController extends Controller
{
    public function __construct(
        private readonly AiAgentService $agent,
        private readonly LlmClient $llm,
    ) {
    }

    /**
     * List AI personas (tabs) and whether the agent is usable for this workspace.
     * GET /api/workspaces/{project}/ai/personas
     */
    public function personas(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $available = true;
        $reason = null;
        try {
            $this->agent->assertAvailable($project);
        } catch (AiException $e) {
            $available = false;
            $reason = $e->getMessage();
        }

        return response()->json([
            'success' => true,
            'data' => [
                'available' => $available,
                'reason' => $reason,
                'model' => $available ? $this->llm->model() : null,
                'personas' => Personas::publicList(),
            ],
        ]);
    }

    /**
     * Non-streaming chat turn.
     * POST /api/workspaces/{project}/ai/chat
     * Body: { persona, message, host?, history?: [{role, content}] }
     */
    public function chat(AiChatRequest $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        try {
            $result = $this->agent->reply(
                project: $project,
                personaKey: (string) $request->input('persona'),
                message: (string) $request->input('message'),
                history: $request->input('history', []) ?? [],
                host: $request->input('host'),
            );
        } catch (AiException $e) {
            return response()->json([
                'success' => false,
                'code' => $e->errorCode,
                'message' => $e->getMessage(),
            ], $e->status);
        }

        return response()->json(['success' => true, 'data' => $result]);
    }

    /**
     * Convenience one-click analysis using a persona's quick action.
     * POST /api/workspaces/{project}/ai/analyze
     * Body: { persona?, host? }
     */
    public function analyze(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $validated = $request->validate([
            'persona' => ['nullable', 'string', Rule::in(array_keys(Personas::all()))],
            'host' => ['nullable', 'string', 'max:255'],
        ]);

        $personaKey = $validated['persona'] ?? Personas::PERFORMANCE_ANALYST;
        $host = $validated['host'] ?? null;
        $persona = Personas::get($personaKey);

        $message = $persona['quick_action'];
        if ($host) {
            $message = "Focus specifically on host \"{$host}\". ".$message;
        }

        try {
            $result = $this->agent->reply($project, $personaKey, $message, [], $host);
        } catch (AiException $e) {
            return response()->json([
                'success' => false,
                'code' => $e->errorCode,
                'message' => $e->getMessage(),
            ], $e->status);
        }

        return response()->json(['success' => true, 'data' => $result]);
    }

    /**
     * Streaming chat turn (Server-Sent Events) for a real-time, reacting feel.
     * POST /api/workspaces/{project}/ai/chat/stream
     * Emits: event:delta data:{"text":"..."}  then  event:done data:{"model":"..."}
     * On failure: event:error data:{"code":"...","message":"..."}
     */
    public function stream(AiChatRequest $request, Project $project): StreamedResponse
    {
        $this->authorize('view', $project);

        $persona = (string) $request->input('persona');
        $message = (string) $request->input('message');
        $history = $request->input('history', []) ?? [];
        $host = $request->input('host');
        $agent = $this->agent;
        $llm = $this->llm;

        $response = new StreamedResponse(function () use ($agent, $llm, $project, $persona, $message, $history, $host) {
            $emit = static function (string $event, array $payload): void {
                echo 'event: '.$event."\n";
                echo 'data: '.json_encode($payload)."\n\n";
                if (function_exists('ob_get_level') && ob_get_level() > 0) {
                    @ob_flush();
                }
                flush();
            };

            try {
                $agent->streamReply(
                    project: $project,
                    personaKey: $persona,
                    message: $message,
                    history: $history,
                    onDelta: static fn (string $delta) => $emit('delta', ['text' => $delta]),
                    host: $host,
                );
                $emit('done', ['model' => $llm->model()]);
            } catch (AiException $e) {
                $emit('error', ['code' => $e->errorCode, 'message' => $e->getMessage()]);
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }
}
