<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AgentDownloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_download_is_public_when_gateway_required(): void
    {
        config(['agent.require_gateway' => true]);

        $platform = 'linux-amd64';
        $path = storage_path('app/agents/'.$platform);
        File::ensureDirectoryExists(dirname($path));
        File::put($path, "#!/bin/sh\necho agent\n");

        $response = $this->get('/api/agents/download/'.$platform);

        $response->assertOk();
        $response->assertHeader('content-type', 'application/octet-stream');
    }

    public function test_register_still_blocked_without_gateway_header_when_required(): void
    {
        config(['agent.require_gateway' => true]);

        $response = $this->postJson('/api/agents/register', [
            'token' => 'invalid',
            'hostname' => 'test-host',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('message', 'Agent API must be accessed via Quenyx Agent Gateway');
    }
}
