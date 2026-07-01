<?php

namespace Tests\Unit;

use App\DataTransferObjects\Ai\AiCompletionRequest;
use App\DataTransferObjects\Ai\AiMessage;
use App\Services\AI\Providers\OpenAiProvider;
use Illuminate\Support\Facades\Config;
use ReflectionMethod;
use Tests\TestCase;

class OpenAiProviderPayloadTest extends TestCase
{
    public function test_build_payload_strips_boolean_metadata_and_attaches_file_search(): void
    {
        Config::set('ai.providers.openai.model', 'gpt-5-mini');
        Config::set('openai.vector_store_id', 'vs_test123');
        Config::set('ai.defaults.max_tokens_reasoning', 4096);
        Config::set('ai.workspace.file_search_max_results', 5);

        $provider = new OpenAiProvider([
            'api_key' => 'test-key',
            'model' => 'gpt-5-mini',
        ]);

        $request = new AiCompletionRequest(
            messages: [AiMessage::system('sys'), AiMessage::user('hello')],
            useFileSearch: true,
            metadata: ['use_file_search' => true, 'workspace' => 'demo'],
        );

        $payload = $this->invokeBuildPayload($provider, $request, false);

        $this->assertArrayNotHasKey('use_file_search', $payload['metadata'] ?? []);
        $this->assertSame(['workspace' => 'demo'], $payload['metadata'] ?? []);
        $this->assertSame('file_search', $payload['tools'][0]['type'] ?? null);
        $this->assertSame(['vs_test123'], $payload['tools'][0]['vector_store_ids'] ?? null);
        $this->assertArrayNotHasKey('temperature', $payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function invokeBuildPayload(OpenAiProvider $provider, AiCompletionRequest $request, bool $stream): array
    {
        $method = new ReflectionMethod(OpenAiProvider::class, 'buildPayload');
        $method->setAccessible(true);

        /** @var array<string, mixed> $payload */
        $payload = $method->invoke($provider, $request, $stream);

        return $payload;
    }
}
