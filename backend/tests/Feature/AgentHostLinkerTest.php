<?php

namespace Tests\Feature;

use App\Constants\AgentConstants;
use App\Models\Agent;
use App\Models\ObserveTargetHost;
use App\Models\ObserveTargetService;
use App\Models\Project;
use App\Models\User;
use App\Services\PlatformAgent\AgentHostLinker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class AgentHostLinkerTest extends TestCase
{
    use RefreshDatabase;

    public function test_links_manual_host_to_agent_by_public_ip_and_heals_pull_services(): void
    {
        $user = User::create([
            'name' => 'Admin',
            'email' => 'linker@test.local',
            'password' => Hash::make('password'),
        ]);
        $project = Project::create([
            'owner_id' => $user->id,
            'name' => 'WS',
            'status' => 'active',
        ]);

        $agent = Agent::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $project->id,
            'hostname' => 'ip-172-31-27-23.ec2.internal',
            'public_ip' => '54.163.235.254',
            'private_ips' => ['172.31.27.23'],
            'status' => AgentConstants::STATUS_ONLINE,
            'agent_secret_hash' => Hash::make('secret'),
            'enrolled_at' => now(),
            'last_seen_at' => now(),
            'primary_protocol' => AgentConstants::PROTOCOL_QAG,
            'permissions' => AgentConstants::DEFAULT_PERMISSIONS,
        ]);

        $host = ObserveTargetHost::create([
            'workspace_id' => $project->id,
            'name' => 'Web-Server',
            'address' => '172.31.27.23',
            'public_ip' => '54.163.235.254',
            'source' => 'manual',
            'enabled' => true,
        ]);

        foreach (['cpu', 'memory', 'disk', 'load'] as $name) {
            ObserveTargetService::create([
                'workspace_id' => $project->id,
                'host_id' => $host->id,
                'name' => $name,
                'service_key' => $name,
                'check_command' => '',
                'check_source' => AgentConstants::CHECK_SOURCE_PULL,
                'enabled' => true,
            ]);
        }

        $result = app(AgentHostLinker::class)->linkAndHeal($host->fresh());

        $this->assertTrue($result['linked']);
        $this->assertSame((string) $agent->id, $result['agent_id']);
        $this->assertGreaterThanOrEqual(4, $result['healed_services']);

        $host->refresh();
        $this->assertSame($agent->id, $host->agent_id);
        $this->assertSame('agent', $host->source);

        foreach ($host->services as $service) {
            $this->assertSame(AgentConstants::CHECK_SOURCE_PLATFORM_AGENT, $service->check_source);
            $this->assertSame('platform_agent_telemetry', $service->check_command);
        }
    }
}
