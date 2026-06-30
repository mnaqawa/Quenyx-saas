<?php

namespace App\Http\Controllers\Ai\Workspace;

use App\Contracts\Ai\AiProviderInterface;
use App\DataTransferObjects\Ai\AiCompletionRequest;
use App\DataTransferObjects\Ai\AiMessage;
use App\DataTransferObjects\Ai\AiUsage;
use App\Exceptions\Ai\AiProviderException;
use App\Http\Resources\Ai\AiConversationResource;
use App\Models\Ai\AiConversation;
use App\Models\Project;
use App\Repositories\Ai\AiConversationRepository;
use App\Services\AI\AiAccessAuditLogger;
use App\Services\AI\AiProviderRegistry;
use App\Services\AI\CompliancePromptOrchestrator;
use App\Services\AI\Workspace\AiWorkspaceContextResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sprint 20 — platform-level AI conversation API (UUID-only, workspace-scoped).
 *
 * Reuses the existing AI runtime (provider registry + prompt orchestrator + conversation
 * repository) — no QynShield business logic is duplicated. Conversation METADATA + token counts are
 * persisted (the conversation surface is the whole point); message CONTENT is stored ONLY when the
 * prompt_logging flag is enabled (privacy-preserving, unchanged from the platform default).
 * When AI is disabled (default) the mock provider answers, so the surface is always production-safe.
 */
class AiConversationController extends AiWorkspaceBaseController
{
    public function __construct(
        AiWorkspaceContextResolver $context,
        private readonly AiProviderRegistry $registry,
        private readonly CompliancePromptOrchestrator $orchestrator,
        private readonly AiConversationRepository $conversations,
        private readonly AiAccessAuditLogger $auditLogger,
    ) {
        parent::__construct($context);
    }

    public function index(Request $request): JsonResponse
    {
        $project = $this->workspace($request);
        $limit = (int) config('ai.workspace.max_conversations', 50);

        return $this->ok(
            AiConversationResource::collection($this->conversations->listForProject($project, $limit))->resolve()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $project = $this->workspace($request);
        $this->requireCapability($project, $request, 'can_use_ai');

        $validated = $request->validate([
            'workspace' => ['required', 'string'],
            'provider' => ['sometimes', 'nullable', 'string', 'max:64'],
            'title' => ['sometimes', 'nullable', 'string', 'max:160'],
        ]);

        $provider = $this->resolveProvider($request);
        $metadata = ! empty($validated['title']) ? ['title' => $validated['title']] : [];

        $conversation = $this->conversations->start($project, $request->user(), $provider->key(), null, $metadata);

        $this->auditLogger->log($request->user(), $project, 'ai_conversation_created', 'ai.conversations.store', $provider->key(), [
            'conversation_uuid' => $conversation->uuid,
        ]);

        return $this->ok((new AiConversationResource($conversation))->resolve(), 201);
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        $project = $this->workspace($request);

        $conversation = $this->conversations->findForProjectWithMessages($project, $uuid);
        abort_if($conversation === null, 404, 'Conversation not found.');

        return $this->ok((new AiConversationResource($conversation))->resolve());
    }

    public function storeMessage(Request $request, string $uuid): JsonResponse
    {
        $project = $this->workspace($request);
        $this->requireCapability($project, $request, 'can_use_ai');

        $validated = $request->validate([
            'workspace' => ['required', 'string'],
            'message' => ['required', 'string', 'max:8000'],
            'provider' => ['sometimes', 'nullable', 'string', 'max:64'],
            'response_format' => ['sometimes', 'nullable', 'in:text,json'],
            'ai_context' => ['sometimes', 'nullable', 'array'],
        ]);

        $conversation = $this->conversations->findForProject($project, $uuid);
        abort_if($conversation === null, 404, 'Conversation not found.');

        try {
            $provider = $this->resolveProvider($request);
            $completionRequest = $this->buildRequest($validated, $provider);
            $this->auditLogger->log($request->user(), $project, 'ai_conversation_message', 'ai.conversations.messages', $provider->key(), [
                'conversation_uuid' => $conversation->uuid,
            ]);
            $response = $provider->responses($completionRequest);
        } catch (AiProviderException $e) {
            return $this->fail($e->getMessage(), $e->errorCode(), $e->httpStatus());
        }

        $promptLogging = (bool) config('ai.feature_flags.prompt_logging', false);

        $this->conversations->recordMessage(
            $conversation,
            'user',
            $promptLogging ? (string) $validated['message'] : null,
            new AiUsage(),
            $response->mocked,
        );
        $assistantMessage = $this->conversations->recordMessage(
            $conversation,
            'assistant',
            $promptLogging ? $response->content : null,
            $response->usage,
            $response->mocked,
        );

        return $this->ok([
            'conversation_uuid' => $conversation->uuid,
            'message_uuid' => $assistantMessage->uuid,
            'ai_enabled' => (bool) config('ai.feature_flags.enabled', false),
            'content' => $response->content,
            'mocked' => $response->mocked,
            'usage' => $response->usage->toArray(),
            'provider' => $provider->key(),
            'model' => $response->model,
            'generated_at' => now()->toIso8601String(),
        ], 201);
    }

    private function resolveProvider(Request $request): AiProviderInterface
    {
        if (! (bool) config('ai.feature_flags.enabled', false)) {
            return $this->registry->get('mock');
        }

        $requested = $request->input('provider');

        return $this->registry->get(is_string($requested) && $requested !== '' ? $requested : null);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function buildRequest(array $validated, AiProviderInterface $provider): AiCompletionRequest
    {
        $userPrompt = (string) $validated['message'];
        $format = $validated['response_format'] ?? 'text';

        if (! empty($validated['ai_context']) && is_array($validated['ai_context'])) {
            $prompt = $this->orchestrator->buildPrompt($validated['ai_context'], $userPrompt);
            $messages = $prompt->toMessages();
        } else {
            $messages = [
                AiMessage::system('You are the Quenyx AI assistant. Answer only from provided context and never invent facts.'),
                AiMessage::user($userPrompt),
            ];
        }

        return new AiCompletionRequest(
            messages: $messages,
            model: null,
            temperature: (float) config('ai.defaults.temperature', 0.0),
            maxTokens: (int) config('ai.defaults.max_tokens', 1024),
            responseFormat: $format,
            stream: false,
        );
    }
}
