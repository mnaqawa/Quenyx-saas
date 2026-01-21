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

        // Create a user and authenticate
        $user = \App\Models\User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/dashboard');

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'success',
                     'data' => [
                         'platform_health',
                         'modules',
                         'performance_series',
                         'weekly_uptime',
                         'alerts_by_module',
                     ],
                 ])
                 ->assertJson([
                     'success' => true,
                 ]);
    }
}
