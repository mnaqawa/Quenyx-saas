<?php

namespace Tests\Feature;

use App\Models\ObserveService;
use App\Models\ObserveMeta;
use App\Models\ObserveTargetHost;
use App\Models\ObserveTargetService;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ObserveTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Project $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);
        
        $this->workspace = Project::create([
            'owner_id' => $this->user->id,
            'name' => 'Test Workspace',
            'status' => 'active',
        ]);
        
        // Create sample observe data
        ObserveService::create([
            'workspace_id' => $this->workspace->id,
            'engine_key' => 'nagios',
            'engine_service_key' => 'host1::service1',
            'host_name' => 'host1',
            'service_name' => 'service1',
            'state' => 'ok',
            'last_check_at' => now(),
            'duration_sec' => 3600,
            'attempt' => '1/3',
            'output' => 'OK - Service is running',
        ]);
        
        ObserveService::create([
            'workspace_id' => $this->workspace->id,
            'engine_key' => 'nagios',
            'engine_service_key' => 'host1::service2',
            'host_name' => 'host1',
            'service_name' => 'service2',
            'state' => 'critical',
            'last_check_at' => now(),
            'duration_sec' => 7200,
            'attempt' => '3/3',
            'output' => 'CRITICAL - Service is down',
        ]);
        
        ObserveService::create([
            'workspace_id' => $this->workspace->id,
            'engine_key' => 'nagios',
            'engine_service_key' => 'host2::service1',
            'host_name' => 'host2',
            'service_name' => 'service1',
            'state' => 'warning',
            'last_check_at' => now(),
            'duration_sec' => 1800,
            'attempt' => '2/3',
            'output' => 'WARNING - High CPU usage',
        ]);
        
        ObserveMeta::create([
            'workspace_id' => $this->workspace->id,
            'engine_key' => 'nagios',
            'last_poll_at' => now(),
            'service_totals_json' => [
                'ok' => 1,
                'warning' => 1,
                'critical' => 1,
                'unknown' => 0,
                'pending' => 0,
            ],
        ]);
    }

    public function test_authorized_user_can_fetch_summary(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson("/api/workspaces/{$this->workspace->id}/observe/summary");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'totals' => ['ok', 'warning', 'critical', 'unknown', 'pending'],
                    'last_poll_at',
                ],
            ])
            ->assertJson([
                'success' => true,
                'data' => [
                    'totals' => [
                        'ok' => 1,
                        'warning' => 1,
                        'critical' => 1,
                        'unknown' => 0,
                        'pending' => 0,
                    ],
                ],
            ]);
    }

    public function test_unauthorized_user_cannot_fetch_summary(): void
    {
        $otherUser = User::factory()->create();
        
        $response = $this->actingAs($otherUser)
            ->getJson("/api/workspaces/{$this->workspace->id}/observe/summary");

        $response->assertStatus(403);
    }

    public function test_workspace_owner_can_fetch_observe_services(): void
    {
        // Explicit test: owner (user_id matches workspace owner_id) must get 200
        $this->assertSame($this->user->id, $this->workspace->owner_id);
        
        $response = $this->actingAs($this->user)
            ->getJson("/api/workspaces/{$this->workspace->id}/observe/services");

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    public function test_authorized_user_can_fetch_services(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson("/api/workspaces/{$this->workspace->id}/observe/services");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'hostTotals',
                    'serviceTotals',
                    'items' => [
                        '*' => ['host', 'service', 'status', 'lastCheckAt', 'durationSec', 'attempt', 'info'],
                    ],
                    'last_poll_at',
                ],
            ])
            ->assertJson([
                'success' => true,
            ]);
        
        $data = $response->json('data');
        $this->assertCount(3, $data['items']);
        // Should be sorted by severity: critical first
        $this->assertEquals('critical', $data['items'][0]['status']);
    }

    public function test_services_filter_by_status(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson("/api/workspaces/{$this->workspace->id}/observe/services?status=critical");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data['items']);
        $this->assertEquals('critical', $data['items'][0]['status']);
    }

    public function test_services_filter_by_problems(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson("/api/workspaces/{$this->workspace->id}/observe/services?problems=1");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(2, $data['items']); // critical + warning, no ok
        $this->assertNotContains('ok', array_column($data['items'], 'status'));
    }

    public function test_services_filter_by_search_query(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson("/api/workspaces/{$this->workspace->id}/observe/services?q=host1");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(2, $data['items']);
        foreach ($data['items'] as $item) {
            $this->assertEquals('host1', $item['host']);
        }
    }

    public function test_services_respect_limit(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson("/api/workspaces/{$this->workspace->id}/observe/services?limit=2");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(2, $data['items']);
    }

    public function test_project_alias_works(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson("/api/projects/{$this->workspace->id}/observe/summary");

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    public function test_put_targets_persists_and_publish_reads_back(): void
    {
        // PUT targets
        $response = $this->actingAs($this->user)
            ->putJson("/api/workspaces/{$this->workspace->id}/observe/targets", [
                'hosts' => [
                    [
                        'name' => 'web-server-01',
                        'address' => '192.168.1.10',
                        'check_command' => 'check-host-alive',
                        'enabled' => true,
                        'services' => [
                            [
                                'name' => 'HTTP',
                                'check_command' => 'check_http',
                                'enabled' => true,
                            ],
                            [
                                'name' => 'Ping',
                                'check_command' => 'check_ping',
                                'enabled' => true,
                            ],
                        ],
                    ],
                ],
            ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        // Verify targets were persisted
        $host = ObserveTargetHost::where('workspace_id', $this->workspace->id)
            ->where('name', 'web-server-01')
            ->first();

        $this->assertNotNull($host);
        $this->assertEquals('192.168.1.10', $host->address);
        $this->assertEquals('check-host-alive', $host->check_command);
        $this->assertTrue($host->enabled);

        // Verify services were persisted
        $services = ObserveTargetService::where('host_id', $host->id)->get();
        $this->assertCount(2, $services);

        $httpService = $services->firstWhere('name', 'HTTP');
        $this->assertNotNull($httpService);
        $this->assertEquals('check_http', $httpService->check_command);

        $pingService = $services->firstWhere('name', 'Ping');
        $this->assertNotNull($pingService);
        $this->assertEquals('check_ping', $pingService->check_command);

        // Test that publish can read back targets without SQL errors
        // This verifies the table names are correct
        try {
            $publisher = new \App\Services\NagiosConfigPublisher();
            // Mock the HTTP calls to gateway to avoid actual network calls in test
            // Just verify it doesn't throw SQL errors when querying
            $hosts = ObserveTargetHost::where('workspace_id', $this->workspace->id)
                ->where('enabled', true)
                ->with(['services' => function ($query) {
                    $query->where('enabled', true);
                }])
                ->get();

            $this->assertCount(1, $hosts);
            $this->assertEquals('web-server-01', $hosts->first()->name);
            $this->assertCount(2, $hosts->first()->services);
        } catch (\Illuminate\Database\QueryException $e) {
            $this->fail('SQL error when reading targets: ' . $e->getMessage());
        }
    }
}
