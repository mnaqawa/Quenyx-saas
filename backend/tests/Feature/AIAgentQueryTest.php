<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use OpenAI\Contracts\ClientContract;
use OpenAI\Responses\Responses\CreateResponse;
use OpenAI\Testing\ClientFake;
use Tests\TestCase;

class AIAgentQueryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'openai.api_key' => 'sk-test',
            'openai.vector_store_id' => 'vs_test123',
            'openai.model' => 'gpt-5-mini',
        ]);
    }

    private function user(): User
    {
        return User::create([
            'name' => 'Test User',
            'email' => 'agent-test@example.com',
            'password' => Hash::make('password'),
        ]);
    }

    public function test_query_requires_authentication(): void
    {
        $this->postJson('/api/ai-agent/query', [
            'agent' => 'anomaly_detector',
            'question' => 'Detect anomalies.',
        ])->assertStatus(401);
    }

    public function test_query_validates_agent_and_question(): void
    {
        $this->app->instance(ClientContract::class, new ClientFake());

        $this->actingAs($this->user(), 'sanctum')
            ->postJson('/api/ai-agent/query', [
                'agent' => 'totally_invalid',
                'question' => '',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['agent', 'question']);
    }

    public function test_query_returns_structured_answer(): void
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

        $response = $this->actingAs($this->user(), 'sanctum')
            ->postJson('/api/ai-agent/query', [
                'agent' => 'anomaly_detector',
                'question' => 'Detect anomalies across all servers.',
            ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonStructure(['success', 'answer', 'agent', 'meta' => ['model']]);
    }
}
