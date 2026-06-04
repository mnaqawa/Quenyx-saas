<?php

namespace Tests\Unit;

use App\Exceptions\OpenAIServiceException;
use App\Services\OpenAI\OpenAIService;
use OpenAI\Contracts\ClientContract;
use OpenAI\Resources\Responses;
use OpenAI\Responses\Responses\CreateResponse;
use OpenAI\Testing\ClientFake;
use Tests\TestCase;

class OpenAIServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'openai.api_key' => 'sk-test',
            'openai.vector_store_id' => 'vs_test123',
            'openai.model' => 'gpt-5-mini',
        ]);
    }

    public function test_supported_agents_are_the_four_documented_types(): void
    {
        $this->assertEqualsCanonicalizing(
            ['performance_analyst', 'anomaly_detector', 'compliance', 'capacity_planner'],
            OpenAIService::supportedAgents(),
        );
    }

    public function test_unknown_agent_throws_invalid_agent(): void
    {
        $client = new ClientFake();
        $this->app->instance(ClientContract::class, $client);
        $service = app(OpenAIService::class);

        try {
            $service->askKnowledgeBase('hello', 'not_a_real_agent');
            $this->fail('Expected OpenAIServiceException was not thrown.');
        } catch (OpenAIServiceException $e) {
            $this->assertSame('invalid_agent', $e->errorCode);
            $this->assertSame(422, $e->status);
        }
    }

    public function test_missing_vector_store_throws_before_calling_openai(): void
    {
        config(['openai.vector_store_id' => '']);
        $client = new ClientFake();
        $this->app->instance(ClientContract::class, $client);
        $service = app(OpenAIService::class);

        try {
            $service->askKnowledgeBase('hello', 'anomaly_detector');
            $this->fail('Expected OpenAIServiceException was not thrown.');
        } catch (OpenAIServiceException $e) {
            $this->assertSame('vector_store_missing', $e->errorCode);
            $this->assertSame(500, $e->status);
        }

        $client->assertNothingSent();
    }

    public function test_ask_knowledge_base_uses_responses_api_with_file_search(): void
    {
        $client = new ClientFake([
            CreateResponse::fake([
                'model' => 'gpt-5-mini',
                'output' => [[
                    'type' => 'message',
                    'id' => 'msg_test',
                    'status' => 'completed',
                    'role' => 'assistant',
                    'content' => [[
                        'type' => 'output_text',
                        'text' => 'Detected anomalies across all servers.',
                        'annotations' => [],
                    ]],
                ]],
            ]),
        ]);
        $this->app->instance(ClientContract::class, $client);

        $service = app(OpenAIService::class);
        $answer = $service->askKnowledgeBase('Detect anomalies across all servers.', 'anomaly_detector');

        $this->assertNotSame('', $answer->answer);
        $this->assertSame('anomaly_detector', $answer->agentType);

        $client->assertSent(Responses::class, function (string $method, array $parameters): bool {
            $instructions = (string) ($parameters['instructions'] ?? '');

            return $method === 'create'
                && ($parameters['model'] ?? null) === 'gpt-5-mini'
                && ($parameters['input'] ?? null) === 'Detect anomalies across all servers.'
                && str_contains($instructions, 'Anomaly Detector')
                && str_contains($instructions, 'Quenyx vOPS HUB')
                && str_contains($instructions, 'Nagios')
                && ($parameters['tools'][0]['type'] ?? null) === 'file_search'
                && in_array('vs_test123', $parameters['tools'][0]['vector_store_ids'] ?? [], true);
        });
    }

    public function test_context_is_appended_to_input_as_json(): void
    {
        $client = new ClientFake([
            CreateResponse::fake(),
        ]);
        $this->app->instance(ClientContract::class, $client);

        $service = app(OpenAIService::class);
        $service->askKnowledgeBase('Analyze host db-1.', 'performance_analyst', [
            'workspace' => ['id' => 7, 'name' => 'Prod'],
            'qynsight' => ['source' => 'qynsight_realtime', 'host' => 'db-1'],
        ]);

        $client->assertSent(Responses::class, function (string $method, array $parameters): bool {
            $input = (string) ($parameters['input'] ?? '');

            return $method === 'create'
                && str_contains($input, 'Analyze host db-1.')
                && str_contains($input, 'Operational context (JSON):')
                && str_contains($input, 'qynsight_realtime')
                && str_contains($input, '"id": 7');
        });
    }
}
