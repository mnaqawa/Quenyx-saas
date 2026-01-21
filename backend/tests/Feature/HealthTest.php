<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_health_endpoint_returns_ok(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'status',
                ],
            ])
            ->assertJson([
                'success' => true,
                'data' => [
                    'status' => 'ok',
                ],
            ]);
    }
}
