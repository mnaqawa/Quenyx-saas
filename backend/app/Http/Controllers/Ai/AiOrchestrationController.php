<?php

namespace App\Http\Controllers\Ai;

use App\Contracts\Ai\AiProviderInterface;
use App\DataTransferObjects\Ai\AiCompletionRequest;
use App\DataTransferObjects\Ai\AiMessage;
use App\Exceptions\Ai\AiProviderException;
use App\Http\Controllers\Controller;
use App\Models\Ai\AiConversation;
use App\Models\Project;
use App\Repositories\Ai\AiConversationRepository;
use App\Services\Ai\AiAccessAuditLogger;
use App\Services\Ai\AiProviderRegistry;
use App\Services\Ai\CompliancePromptOrchestrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Workspace-scoped AI Orchestration API (QCIF Sprint 9). Infrastructure only — NO business
 * AI (no gap assessment, evidence intelligence, or copilot logic lives here).
 *
 * AI execution is OFF by default: until ai.feature_flags.enabled is true, every request is
 * served by the mock provider and no external model is contacted. Provider selection is fully
 * config/registry-driven (no provider hardcoding). All corpus/DB access is delegated (the
 * orchestrator never queries the corpus; only the repository touches the database).
 */
class AiOrchestrationController extends Controller
{
    public function __construct(
        private readonly AiProviderRegistry $registry,
        private readonly CompliancePromptOrchestrator $orchestrator,
        private readonly AiConversationRepository $conversations,
        private readonly AiAccessAuditLogger $auditLogger,
    ) {}

    public function chat(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $validated = $this->validateInput($request);

        try {
            $provider = $this->resolveProvider($request);
            $completionRequest = $this->buildRequest($validated, stream: false);

            $this->auditLogger->log(
                $request->user(),
                $project,
                'ai_orchestration_chat',
                'ai.chat',
                $provider->key(),
                ['context_type' => $validated['ai_context']['context_type'] ?? null],
            );

            $response = $provider->responses($completionRequest);
        } catch (AiProviderException $e) {
            return $this->error($e->getMessage(), $e->errorCode(), $e->httpStatus());
        }

        $conversation = $this->persist($request, $project, $provider->key(), $response->model, $validated, $response->content, $response->usage, $response->mocked);

        return response()->json([
            'success' => true,
            'data' => array_merge(
                ['conversation_uuid' => $conversation?->uuid],
                ['ai_enabled' => $this->aiEnabled()],
                $response->toArray(),
                ['generated_at' => now()->toIso8601String()],
            ),
        ]);
    }

    public function stream(Request $request, Project $project): StreamedResponse|JsonResponse
    {
        $this->authorize('view', $project);

        $validated = $this->validateInput($request);

        try {
            $provider = $this->resolveProvider($request, requireStreaming: true);
            $completionRequest = $this->buildRequest($validated, stream: true);
        } catch (AiProviderException $e) {
            return $this->error($e->getMessage(), $e->errorCode(), $e->httpStatus());
        }

        $this->auditLogger->log(
            $request->user(),
            $project,
            'ai_orchestration_stream',
            'ai.stream',
            $provider->key(),
            ['context_type' => $validated['ai_context']['context_type'] ?? null],
        );

        return response()->stream(function () use ($provider, $completionRequest): void {
            try {
                foreach ($provider->stream($completionRequest) as $chunk) {
                    echo 'data: '.json_encode($chunk->toArray())."\n\n";
                    $this->flush();
                }
            } catch (AiProviderException $e) {
                echo 'data: '.json_encode(['error' => $e->getMessage(), 'code' => $e->errorCode()])."\n\n";
            }

            echo "data: [DONE]\n\n";
            $this->flush();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function validateInput(Request $request): array
    {
        return $request->validate([
            'prompt' => ['required_without:message', 'nullable', 'string', 'max:8000'],
            'message' => ['required_without:prompt', 'nullable', 'string', 'max:8000'],
            'provider' => ['sometimes', 'nullable', 'string', 'max:64'],
            'response_format' => ['sometimes', 'nullable', 'in:text,json'],
            'conversation' => ['sometimes', 'nullable', 'string', 'max:64'],
            'ai_context' => ['sometimes', 'nullable', 'array'],
        ]);
    }

    private function resolveProvider(Request $request, bool $requireStreaming = false): AiProviderInterface
    {
        // Feature flag is the master switch: when AI is disabled (or, for streaming, when
        // streaming is disabled) the mock provider is used regardless of the requested key.
        if (! $this->aiEnabled() || ($requireStreaming && ! (bool) config('ai.feature_flags.streaming_enabled', false))) {
            return $this->registry->get('mock');
        }

        $requested = $request->input('provider');

        return $this->registry->get(is_string($requested) && $requested !== '' ? $requested : null);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function buildRequest(array $validated, bool $stream): AiCompletionRequest
    {
        $userPrompt = (string) ($validated['prompt'] ?? $validated['message'] ?? '');
        $format = $validated['response_format'] ?? 'text';

        if (! empty($validated['ai_context']) && is_array($validated['ai_context'])) {
            $prompt = $this->orchestrator->buildPrompt($validated['ai_context'], $userPrompt);
            $messages = $prompt->toMessages();
        } else {
            $messages = [
                AiMessage::system('You are a compliance assistant for the Quenyx QynShield platform. Answer only from provided context and never invent compliance facts.'),
                AiMessage::user($userPrompt),
            ];
        }

        return new AiCompletionRequest(
            messages: $messages,
            model: null,
            temperature: (float) config('ai.defaults.temperature', 0.0),
            maxTokens: (int) config('ai.defaults.max_tokens', 1024),
            responseFormat: $format,
            stream: $stream,
        );
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function persist(
        Request $request,
        Project $project,
        string $provider,
        ?string $model,
        array $validated,
        string $assistantContent,
        \App\DataTransferObjects\Ai\AiUsage $usage,
        bool $mocked,
    ): ?AiConversation {
        if (! (bool) config('ai.feature_flags.persist_conversations', false)) {
            return null;
        }

        $promptLogging = (bool) config('ai.feature_flags.prompt_logging', false);

        $conversation = null;
        if (! empty($validated['conversation'])) {
            $conversation = $this->conversations->findForProject($project, (string) $validated['conversation']);
        }
        $conversation ??= $this->conversations->start($project, $request->user(), $provider, $model);

        $userContent = $promptLogging ? (string) ($validated['prompt'] ?? $validated['message'] ?? '') : null;
        $this->conversations->recordMessage($conversation, 'user', $userContent, new \App\DataTransferObjects\Ai\AiUsage(), $mocked);
        $this->conversations->recordMessage(
            $conversation,
            'assistant',
            $promptLogging ? $assistantContent : null,
            $usage,
            $mocked,
        );

        return $conversation;
    }

    private function aiEnabled(): bool
    {
        return (bool) config('ai.feature_flags.enabled', false);
    }

    private function flush(): void
    {
        if (function_exists('ob_get_level') && ob_get_level() > 0) {
            @ob_flush();
        }
        @flush();
    }

    private function error(string $message, string $code, int $status): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message, 'code' => $code], $status);
    }
}
