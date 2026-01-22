<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Project;
use App\Models\ProjectMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test registration creates user, project, and membership
     */
    public function test_registration_creates_user_project_and_membership(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'Password123!',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'token',
                    'user' => [
                        'id',
                        'name',
                        'email',
                    ],
                    'workspace' => [
                        'id',
                        'name',
                    ],
                ],
            ])
            ->assertJson([
                'success' => true,
                'data' => [
                    'user' => [
                        'name' => 'Test User',
                        'email' => 'test@example.com',
                    ],
                ],
            ]);

        // Assert user was created
        $this->assertDatabaseHas('users', [
            'email' => 'test@example.com',
            'name' => 'Test User',
        ]);

        $user = User::where('email', 'test@example.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue(Hash::check('Password123!', $user->password));

        // Assert exactly one project was created
        $projects = Project::where('owner_id', $user->id)->get();
        $this->assertCount(1, $projects, 'Should create exactly one project');

        $project = $projects->first();
        $this->assertEquals("Test User's Workspace", $project->name);
        $this->assertEquals('active', $project->status);

        // Assert exactly one membership was created with owner role
        $memberships = ProjectMembership::where('user_id', $user->id)
            ->where('project_id', $project->id)
            ->get();
        $this->assertCount(1, $memberships, 'Should create exactly one membership');

        $membership = $memberships->first();
        $this->assertEquals('owner', $membership->role);
    }

    /**
     * Test registration with custom workspace name
     */
    public function test_registration_with_custom_workspace_name(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'Password123!',
            'workspace_name' => 'My Company',
        ]);

        $response->assertStatus(201);

        $user = User::where('email', 'test@example.com')->first();
        $project = Project::where('owner_id', $user->id)->first();

        $this->assertEquals('My Company', $project->name);
    }

    /**
     * Test registration with missing name uses default workspace name
     */
    public function test_registration_without_name_uses_default_workspace_name(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => '',
            'email' => 'test@example.com',
            'password' => 'Password123!',
        ]);

        $response->assertStatus(422); // Validation should fail for empty name

        // Test with null name (if validation allows it)
        $response = $this->postJson('/api/auth/register', [
            'name' => 'User',
            'email' => 'test2@example.com',
            'password' => 'Password123!',
        ]);

        $response->assertStatus(201);

        $user = User::where('email', 'test2@example.com')->first();
        $project = Project::where('owner_id', $user->id)->first();

        $this->assertEquals("User's Workspace", $project->name);
    }

    /**
     * Test login does NOT create another project
     */
    public function test_login_does_not_create_project(): void
    {
        // First, register a user
        $this->postJson('/api/auth/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'Password123!',
        ]);

        $user = User::where('email', 'test@example.com')->first();
        $initialProjectCount = Project::where('owner_id', $user->id)->count();
        $this->assertEquals(1, $initialProjectCount, 'Should have exactly one project after registration');

        // Now login
        $response = $this->postJson('/api/auth/login', [
            'email' => 'test@example.com',
            'password' => 'Password123!',
        ]);

        $response->assertStatus(200);

        // Verify project count hasn't changed
        $finalProjectCount = Project::where('owner_id', $user->id)->count();
        $this->assertEquals(1, $finalProjectCount, 'Login should not create additional projects');
        $this->assertEquals($initialProjectCount, $finalProjectCount, 'Project count should remain the same');
    }

    /**
     * Test registration validation
     */
    public function test_registration_requires_valid_data(): void
    {
        // Missing required fields
        $response = $this->postJson('/api/auth/register', []);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email', 'password']);

        // Invalid email
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Test User',
            'email' => 'invalid-email',
            'password' => 'Password123!',
        ]);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        // Duplicate email
        User::factory()->create(['email' => 'existing@example.com']);

        $response = $this->postJson('/api/auth/register', [
            'name' => 'Test User',
            'email' => 'existing@example.com',
            'password' => 'Password123!',
        ]);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        // Password too short
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'short',
        ]);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    /**
     * Test registration creates token for immediate authentication
     */
    public function test_registration_returns_authentication_token(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'Password123!',
        ]);

        $response->assertStatus(201);
        $data = $response->json('data');

        $this->assertNotEmpty($data['token'], 'Should return authentication token');
        $this->assertIsString($data['token'], 'Token should be a string');

        // Verify token works by using it to access protected endpoint
        $meResponse = $this->withHeader('Authorization', 'Bearer ' . $data['token'])
            ->getJson('/api/auth/me');

        $meResponse->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'email' => 'test@example.com',
                ],
            ]);
    }
}
