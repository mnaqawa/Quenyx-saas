<?php

namespace App\Services\AI;

use App\Models\Project;
use App\Services\EntitlementService;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates an AI agent turn: gating -> persona -> live context -> LLM call.
 *
 * Conversation history is supplied by the client per request (stateless),
 * keeping the backend idempotent and avoiding premature persistence. A stored
 * thread model can be layered on later without changing this contract.
 */
class AiAgentService
{
    public function __construct(
        private readonly LlmClient $llm,
        private readonly AgentContextBuilder $context,
        private readonly EntitlementService $entitlements,
    ) {
    }

    /**
     * Ensure the AI agent is enabled, configured, and the workspace is entitled.
     *
     * @throws AiException
     */
    public function assertAvailable(Project $project): void
    {
        if (! (bool) config('ai.enabled', false)) {
            throw AiException::disabled();
        }

        if (! $this->llm->isConfigured()) {
            throw AiException::notConfigured();
        }

        $module = (string) config('ai.required_module', 'qynsight');
        if ($module !== '' && ! $this->entitlements->hasEffectiveModuleAccess($project, $module)) {
            throw new AiException(
                "This workspace's plan does not include the QynSight AI agent.",
                403,
                'ai_not_entitled',
            );
        }
    }

    /**
     * Run a non-streaming agent turn.
     *
     * @param array<int, array{role: string, content: string}> $history
     * @return array{reply: string, persona: string, model: string, usage: array<string, int>}
     */
    public function reply(Project $project, string $personaKey, string $message, array $history = [], ?string $host = null): array
    {
        $this->assertAvailable($project);

        $persona = Personas::get($personaKey);
        $messages = $this->buildMessages($project, $persona, $message, $history, $host);

        $result = $this->llm->chat($messages, $persona['temperature'] ?? null);

        Log::info('AI agent reply', [
            'workspace_id' => $project->id,
            'persona' => $persona['key'],
            'host' => $host,
            'usage' => $result['usage'],
        ]);

        return [
            'reply' => $result['content'],
            'persona' => $persona['key'],
            'model' => $result['model'],
            'usage' => $result['usage'],
        ];
    }

    /**
     * Run a streaming agent turn, emitting deltas through $onDelta.
     *
     * @param array<int, array{role: string, content: string}> $history
     * @param callable(string): void $onDelta
     */
    public function streamReply(Project $project, string $personaKey, string $message, array $history, callable $onDelta, ?string $host = null): string
    {
        $this->assertAvailable($project);

        $persona = Personas::get($personaKey);
        $messages = $this->buildMessages($project, $persona, $message, $history, $host);

        return $this->llm->stream($messages, $onDelta, $persona['temperature'] ?? null);
    }

    /**
     * Build the message array: system (persona + live context) + history + user turn.
     *
     * @param array{key: string, system_prompt: string, temperature: float} $persona
     * @param array<int, array{role: string, content: string}> $history
     * @return array<int, array{role: string, content: string}>
     */
    private function buildMessages(Project $project, array $persona, string $message, array $history, ?string $host): array
    {
        $contextBlock = $this->context->build($project, $host);

        $messages = [[
            'role' => 'system',
            'content' => $persona['system_prompt']."\n\n".$contextBlock,
        ]];

        foreach ($this->sanitizeHistory($history) as $turn) {
            $messages[] = $turn;
        }

        $messages[] = ['role' => 'user', 'content' => $this->clampMessage($message)];

        return $messages;
    }

    /**
     * Keep only valid user/assistant turns, bounded by config, with clamped content.
     *
     * @param array<int, mixed> $history
     * @return array<int, array{role: string, content: string}>
     */
    private function sanitizeHistory(array $history): array
    {
        $limit = (int) config('ai.history_limit', 12);

        $clean = [];
        foreach ($history as $turn) {
            if (! is_array($turn)) {
                continue;
            }
            $role = $turn['role'] ?? null;
            $content = $turn['content'] ?? null;
            if (! in_array($role, ['user', 'assistant'], true) || ! is_string($content) || $content === '') {
                continue;
            }
            $clean[] = ['role' => $role, 'content' => $this->clampMessage($content)];
        }

        if (count($clean) > $limit) {
            $clean = array_slice($clean, -$limit);
        }

        return $clean;
    }

    private function clampMessage(string $text): string
    {
        $max = (int) config('ai.message_max_chars', 4000);

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max) : $text;
    }
}
