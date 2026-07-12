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

    public function test_availability_wraps_payload_in_data(): void
    {
        $platform = 'linux-amd64';
        $path = storage_path('app/agents/'.$platform);
        File::ensureDirectoryExists(dirname($path));
        File::put($path, "#!/bin/sh\necho agent\n");

        $this->getJson('/api/agents/availability/'.$platform)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.platform', $platform);
    }
}
