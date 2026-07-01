<?php

namespace Tests\Unit;

use App\Models\Project;
use App\Services\AI\Workspace\AiWorkspaceChatComposer;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class AiWorkspaceChatComposerTest extends TestCase
{
    public function test_compose_enables_file_search_when_vector_store_configured(): void
    {
        Config::set('ai.workspace.knowledge_enabled', true);
        Config::set('openai.vector_store_id', 'vs_test123');
        Config::set('ai.defaults.max_tokens_reasoning', 4096);

        $composer = new AiWorkspaceChatComposer;
        $project = new Project(['name' => 'Demo']);

        $request = $composer->compose($project, ['message' => 'What is NCA ECC?']);

        $this->assertTrue($composer->knowledgeBaseEnabled());
        $this->assertTrue($request->useFileSearch);
        $this->assertSame([], $request->metadata);
        $this->assertSame(4096, $request->maxTokens);
        $this->assertStringContainsString('File Search', $request->messages[0]->content);
    }

    public function test_compose_uses_generic_prompt_without_vector_store(): void
    {
        Config::set('ai.workspace.knowledge_enabled', true);
        Config::set('openai.vector_store_id', '');

        $composer = new AiWorkspaceChatComposer;
        $project = new Project(['name' => 'Demo']);

        $request = $composer->compose($project, ['message' => 'Hello']);

        $this->assertFalse($composer->knowledgeBaseEnabled());
        $this->assertSame([], $request->metadata);
        $this->assertStringNotContainsString('File Search', $request->messages[0]->content);
    }
}
