<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test the dashboard endpoint returns valid JSON.
     */
    public function test_dashboard_returns_valid_json(): void
    {
        // Run seeders to populate data
        $this->seed();

        $response = $this->get('/api/dashboard');

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'platform_health',
                     'modules' => [
                         '*' => [
                             'id',
                             'name',
                             'description',
                             'status',
                             'subscription_state',
                         ],
                     ],
                 ]);
    }
}
