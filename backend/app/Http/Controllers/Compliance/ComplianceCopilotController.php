<?php

namespace App\Http\Controllers\Compliance;

use App\DataTransferObjects\Ai\AiUsage;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\Compliance\ComplianceCorpusAccessAuditLogger;
use App\Services\Compliance\Copilot\ComplianceCopilotService;
use App\Services\Compliance\Copilot\ComplianceCopilotSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Workspace-scoped Compliance Copilot v0 API (QCIF Sprint 14).
 *
 * The Copilot answers a closed set of compliance questions by orchestrating existing AI Skills and
 * (only when AI is enabled) calling a provider through the AI Provider Registry. It NEVER queries
 * the corpus/evidence database directly. Every response is UUID-only and citation-enforced; answers
 * with no grounding fail closed. Access requires sanctum auth, project membership, and the
 * QynShield entitlement; every turn is audit-logged (metadata only — never message content) and
 * rate limited. Prompt logging and conversation persistence are OFF by default.
 */
class ComplianceCopilotController extends Controller
{
    public function __construct(
        private readonly ComplianceCopilotService $copilot,
        private readonly ComplianceCopilotSessionService $sessions,
        private readonly ComplianceCorpusAccessAuditLogger $auditLogger,
    ) {}

    public function message(Request $request, Project $project): JsonResponse
    {
        return $this->handleTurn($request, $project, null);
    }

    public function conversationMessage(Request $request, Project $project, string $conversationUuid): JsonResponse
    {
        return $this->handleTurn($request, $project, $conversationUuid);
    }

    public function conversations(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json([
            'success' => true,
            'data' => [
                'persistence_enabled' => $this->sessions->persistenceEnabled(),
                'conversations' => $this->sessions->listConversations($project),
            ],
        ]);
    }

    public function conversation(Request $request, Project $project, string $conversationUuid): JsonResponse
    {
        $this->authorize('view', $project);

        $conversation = $this->sessions->showConversation($project, $conversationUuid);

        if ($conversation === null) {
            return response()->json([
                'success' => false,
                'message' => $this->sessions->persistenceEnabled()
                    ? 'Conversation not found.'
                    : 'Conversation persistence is disabled.',
                'code' => 'conversation_not_found',
            ], 404);
        }

        return response()->json(['success' => true, 'data' => $conversation]);
    }

    // -------------------------------------------------------------------------
    // Core turn handling
    // -------------------------------------------------------------------------

    private function handleTurn(Request $request, Project $project, ?string $conversationUuid): JsonResponse
    {
        $this->authorize('view', $project);

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:8000'],
            'framework' => ['sometimes', 'nullable', 'string', 'max:64'],
            'release' => ['sometimes', 'nullable', 'string', 'max:64'],
            'provider' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        $result = $this->copilot->handle($project->id, $validated['message'], [
            'framework' => $validated['framework'] ?? null,
            'release' => $validated['release'] ?? null,
            'provider' => $validated['provider'] ?? null,
        ]);

        $provider = is_string($result['provider'] ?? null) ? $result['provider'] : 'mock';

        $conversation = $this->sessions->resolveConversation(
            $project,
            $request->user(),
            $conversationUuid,
            $provider,
        );

        $session = $this->sessions->recordTurn(
            $conversation,
            $validated['message'],
            (string) $result['intent'],
            (string) $result['mode'],
            $provider,
            (string) ($result['plain_answer'] ?? ''),
            $this->usageFrom($result),
            (bool) ($result['mocked'] ?? true),
        );

        $user = $request->user();
        if ($user !== null) {
            $this->auditLogger->logCopilot(
                $user,
                $project,
                'copilot.message',
                $session['conversation_uuid'],
                (string) $result['intent'],
                (string) $result['mode'],
                $result['provider'] ?? null,
            );
        }

        $errorCode = $result['error_code'] ?? null;
        $status = $errorCode === 'scope_unresolved' ? 422 : 200;

        $data = [
            'conversation_uuid' => $session['conversation_uuid'],
            'message_uuid' => $session['message_uuid'],
            'intent' => $result['intent'],
            'mode' => $result['mode'],
            'answer_en' => $result['answer_en'],
            'answer_ar' => $result['answer_ar'],
            'citations' => $result['citations'],
            'skill_results' => $result['skill_results'],
            'guardrails' => $result['guardrails'],
            'warnings' => $result['warnings'],
            'scope' => $result['scope'] ?? null,
            'generated_at' => now()->toIso8601String(),
        ];

        if (($result['reasoning'] ?? null) !== null) {
            $data['reasoning'] = $result['reasoning'];
        }

        if (($result['retrieval_context'] ?? null) !== null) {
            $data['retrieval_context'] = $result['retrieval_context'];
        }

        return response()->json([
            'success' => $errorCode === null,
            'data' => $data,
        ], $status);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function usageFrom(array $result): AiUsage
    {
        $usage = $result['usage'] ?? [];

        return new AiUsage(
            promptTokens: (int) ($usage['prompt_tokens'] ?? 0),
            completionTokens: (int) ($usage['completion_tokens'] ?? 0),
            totalTokens: (int) ($usage['total_tokens'] ?? 0),
        );
    }
}
