<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\User;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use OpenAI\Contracts\ClientContract;
use OpenAI\Exceptions\ErrorException;
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

    private function user(string $email = 'agent-test@example.com'): User
    {
        return User::create([
            'name' => 'Test User',
            'email' => $email,
            'password' => Hash::make('password'),
        ]);
    }

    private function fakeAnswerClient(): ClientFake
    {
        return new ClientFake([
            CreateResponse::fake([
                'model' => 'gpt-5-mini',
                'output' => [[
                    'type' => 'message',
                    'id' => 'msg_test',
                    'status' => 'completed',
                    'role' => 'assistant',
                    'content' => [[
                        'type' => 'output_text',
                        'text' => 'Quenyx vOPS HUB is a modular operations platform.',
                        'annotations' => [],
                    ]],
                ]],
            ]),
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

    public function test_query_allows_workspace_member(): void
    {
        $this->app->instance(ClientContract::class, $this->fakeAnswerClient());

        $owner = $this->user('ws-owner@example.com');
        $project = Project::create([
            'owner_id' => $owner->id,
            'name' => 'Prod Workspace',
            'status' => 'active',
        ]);

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/ai-agent/query', [
                'workspace_id' => $project->id,
                'agent' => 'anomaly_detector',
                'question' => 'Detect anomalies across this workspace.',
                'context' => [
                    'source' => 'qynsight_realtime',
                    'host' => 'db-1',
                    'metrics' => ['cpu_pct' => 91],
                    'services' => [['status' => 'critical', 'count' => 2]],
                ],
            ])
            ->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    public function test_query_denies_non_member_workspace(): void
    {
        $this->app->instance(ClientContract::class, $this->fakeAnswerClient());

        $owner = $this->user('real-owner@example.com');
        $project = Project::create([
            'owner_id' => $owner->id,
            'name' => 'Private Workspace',
            'status' => 'active',
        ]);

        $outsider = $this->user('outsider@example.com');

        $this->actingAs($outsider, 'sanctum')
            ->postJson('/api/ai-agent/query', [
                'workspace_id' => $project->id,
                'agent' => 'anomaly_detector',
                'question' => 'Show me this workspace.',
            ])
            ->assertStatus(403)
            ->assertJson(['success' => false, 'code' => 'workspace_forbidden']);
    }

    public function test_query_allows_invited_member_workspace(): void
    {
        $this->app->instance(ClientContract::class, $this->fakeAnswerClient());

        $owner = $this->user('owner2@example.com');
        $project = Project::create([
            'owner_id' => $owner->id,
            'name' => 'Shared Workspace',
            'status' => 'active',
        ]);

        $member = $this->user('member2@example.com');
        ProjectMembership::create([
            'project_id' => $project->id,
            'user_id' => $member->id,
            'role' => 'member',
        ]);

        $this->actingAs($member, 'sanctum')
            ->postJson('/api/ai-agent/query', [
                'workspace_id' => $project->id,
                'agent' => 'performance_analyst',
                'question' => 'Summarize performance.',
            ])
            ->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    public function test_query_maps_quota_exceeded_to_429(): void
    {
        $this->app->instance(ClientContract::class, new ClientFake([
            new ErrorException([
                'message' => 'You exceeded your current quota, please check your plan and billing details.',
                'type' => 'insufficient_quota',
                'code' => 'insufficient_quota',
            ], new Psr7Response(429)),
        ]));

        $this->actingAs($this->user(), 'sanctum')
            ->postJson('/api/ai-agent/query', [
                'agent' => 'anomaly_detector',
                'question' => 'Detect anomalies.',
            ])
            ->assertStatus(429)
            ->assertJson(['success' => false, 'code' => 'quota_exceeded']);
    }

    public function test_query_reports_missing_vector_store(): void
    {
        config(['openai.vector_store_id' => '']);
        $this->app->instance(ClientContract::class, new ClientFake());

        $this->actingAs($this->user(), 'sanctum')
            ->postJson('/api/ai-agent/query', [
                'agent' => 'anomaly_detector',
                'question' => 'Detect anomalies.',
            ])
            ->assertStatus(500)
            ->assertJson(['success' => false, 'code' => 'vector_store_missing']);
    }
}
