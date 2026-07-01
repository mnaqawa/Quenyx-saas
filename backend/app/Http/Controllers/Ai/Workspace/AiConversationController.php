<?php

namespace App\Http\Controllers\Ai\Workspace;

use App\Contracts\Ai\AiProviderInterface;
use App\DataTransferObjects\Ai\AiCompletionRequest;
use App\DataTransferObjects\Ai\AiUsage;
use App\Exceptions\Ai\AiProviderException;
use App\Http\Resources\Ai\AiConversationResource;
use App\Models\Ai\AiConversation;
use App\Models\Project;
use App\Repositories\Ai\AiConversationRepository;
use App\Services\AI\AiAccessAuditLogger;
use App\Services\AI\AiExecutionResolver;
use App\Services\AI\AiProviderRegistry;
use App\Services\AI\CompliancePromptOrchestrator;
use App\Services\AI\Workspace\AiWorkspaceChatComposer;
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
        private readonly AiExecutionResolver $execution,
        private readonly CompliancePromptOrchestrator $orchestrator,
        private readonly AiConversationRepository $conversations,
        private readonly AiAccessAuditLogger $auditLogger,
        private readonly AiWorkspaceChatComposer $chatComposer,
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

        $metadata = ! empty($validated['title']) ? ['title' => $validated['title']] : [];
        $providerKey = $this->execution->resolveProviderKey($project, $validated['provider'] ?? null)
            ?? ($this->registry->defaultKey() !== '' ? $this->registry->defaultKey() : 'unconfigured');

        $conversation = $this->conversations->start($project, $request->user(), $providerKey, null, $metadata);

        $this->auditLogger->log($request->user(), $project, 'ai_conversation_created', 'ai.conversations.store', $providerKey, [
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
            'history' => ['sometimes', 'nullable', 'array', 'max:40'],
            'history.*.role' => ['required_with:history', 'in:user,assistant'],
            'history.*.content' => ['required_with:history', 'string', 'max:8000'],
        ]);

        $conversation = $this->conversations->findForProjectWithMessages($project, $uuid);
        abort_if($conversation === null, 404, 'Conversation not found.');

        try {
            $provider = $this->execution->resolveProvider($project, $validated['provider'] ?? null);
            $priorTurns = $this->resolvePriorTurns($conversation, $validated);
            $completionRequest = $this->buildRequest($validated, $provider, $project, $priorTurns);
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
            'ai_enabled' => $this->execution->isLiveExecution($project),
            'runtime_mode' => $this->execution->runtimeMode($project),
            'knowledge_base' => $this->chatComposer->knowledgeBaseEnabled(),
            'content' => $response->content,
            'mocked' => $response->mocked,
            'usage' => $response->usage->toArray(),
            'provider' => $provider->key(),
            'model' => $response->model,
            'generated_at' => now()->toIso8601String(),
        ], 201);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  list<array{role: string, content: string}>  $priorTurns
     */
    private function buildRequest(array $validated, AiProviderInterface $provider, Project $project, array $priorTurns = []): AiCompletionRequest
    {
        $userPrompt = (string) $validated['message'];
        $format = $validated['response_format'] ?? 'text';

        if (! empty($validated['ai_context']) && is_array($validated['ai_context'])) {
            $prompt = $this->orchestrator->buildPrompt($validated['ai_context'], $userPrompt);
            $messages = $prompt->toMessages();
        } else {
            return $this->chatComposer->compose($project, $validated, $priorTurns);
        }

        return new AiCompletionRequest(
            messages: $messages,
            model: null,
            temperature: (float) config('ai.defaults.temperature', 0.0),
            maxTokens: (int) config('ai.defaults.max_tokens_reasoning', 4096),
            responseFormat: $format,
            stream: false,
            useFileSearch: $this->chatComposer->knowledgeBaseEnabled(),
        );
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return list<array{role: string, content: string}>
     */
    private function resolvePriorTurns(AiConversation $conversation, array $validated): array
    {
        $fromDb = $this->loadConversationHistory($conversation);
        if ($fromDb !== []) {
            return $fromDb;
        }

        $history = $validated['history'] ?? [];
        if (! is_array($history)) {
            return [];
        }

        $turns = [];
        foreach ($history as $item) {
            if (! is_array($item)) {
                continue;
            }
            $role = (string) ($item['role'] ?? '');
            $content = trim((string) ($item['content'] ?? ''));
            if ($content === '' || ! in_array($role, ['user', 'assistant'], true)) {
                continue;
            }
            $turns[] = ['role' => $role, 'content' => $content];
        }

        return $turns;
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    private function loadConversationHistory(AiConversation $conversation): array
    {
        if (! (bool) config('ai.feature_flags.prompt_logging', false)) {
            return [];
        }

        $turns = [];
        foreach ($conversation->messages as $message) {
            $role = (string) $message->role;
            if (! in_array($role, ['user', 'assistant'], true)) {
                continue;
            }
            $content = trim((string) ($message->content ?? ''));
            if ($content === '') {
                continue;
            }
            $turns[] = ['role' => $role, 'content' => $content];
        }

        return $turns;
    }
}
