<?php

namespace App\Services\AI\Workspace;

use App\DataTransferObjects\Ai\AiCompletionRequest;
use App\DataTransferObjects\Ai\AiMessage;
use App\Models\Project;

/**
 * Builds workspace chat completion requests with optional knowledge-base grounding
 * (OpenAI File Search over OPENAI_VECTOR_STORE_ID — same source as Ask Quenyx AI).
 */
class AiWorkspaceChatComposer
{
    public function knowledgeBaseEnabled(): bool
    {
        if (! (bool) config('ai.workspace.knowledge_enabled', true)) {
            return false;
        }

        return trim((string) config('openai.vector_store_id', '')) !== '';
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function compose(Project $project, array $validated): AiCompletionRequest
    {
        $userPrompt = (string) $validated['message'];
        $format = $validated['response_format'] ?? 'text';
        $useKnowledge = $this->knowledgeBaseEnabled();

        $messages = [
            AiMessage::system($useKnowledge ? $this->knowledgeSystemPrompt() : $this->genericSystemPrompt()),
            AiMessage::user($userPrompt),
        ];

        return new AiCompletionRequest(
            messages: $messages,
            model: null,
            temperature: (float) config('ai.defaults.temperature', 0.0),
            maxTokens: $this->resolveMaxOutputTokens(),
            responseFormat: $format,
            stream: false,
            metadata: $useKnowledge ? ['use_file_search' => true] : [],
        );
    }

    private function genericSystemPrompt(): string
    {
        return 'You are the Quenyx AI assistant for the vOPS HUB platform. '
            .'Answer clearly and helpfully. If you lack information to answer, say so instead of inventing facts.';
    }

    private function knowledgeSystemPrompt(): string
    {
        return <<<'TXT'
You are the Quenyx AI assistant for the vOPS HUB platform.

You have access to the Quenyx knowledge base via File Search (NCA ECC, SAMA, compliance, and platform documentation).

Rules:
- Ground answers in retrieved knowledge-base content. Cite framework names and control references when present in the sources.
- For NCA ECC / ECS / SAMA compliance questions, explain the framework scope and practical compliance steps from the retrieved material.
- When the user asks for a specific language (e.g. Arabic), respond entirely in that language with complete sentences and paragraphs — never stop mid-sentence.
- If the knowledge base does not contain enough information, say what is missing instead of inventing controls or requirements.
- Be thorough but structured: use short headings or bullet lists when helpful.
TXT;
    }

    private function resolveMaxOutputTokens(): int
    {
        $model = strtolower(trim((string) (config('ai.providers.openai.model') ?: config('openai.model', ''))));
        if (str_starts_with($model, 'gpt-5') || preg_match('/^o\d/', $model)) {
            return (int) config('ai.defaults.max_tokens_reasoning', 4096);
        }

        return (int) config('ai.defaults.max_tokens', 2048);
    }
}
