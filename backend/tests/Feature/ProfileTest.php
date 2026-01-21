<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Helper to authenticate a user with Sanctum
     */
    protected function actingAsUser(User $user): self
    {
        Sanctum::actingAs($user, ['*']);
        return $this;
    }

    public function test_authenticated_user_can_get_profile(): void
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
        ]);

        $response = $this->actingAsUser($user)
            ->getJson('/api/auth/me');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'id',
                    'name',
                    'email',
                ],
            ])
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $user->id,
                    'name' => 'Test User',
                    'email' => 'test@example.com',
                ],
            ]);
    }

    public function test_unauthenticated_user_cannot_get_profile(): void
    {
        $response = $this->getJson('/api/auth/me');

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
            ]);
    }

    public function test_user_can_update_name(): void
    {
        $user = User::create([
            'name' => 'Original Name',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
        ]);

        $response = $this->actingAsUser($user)
            ->putJson('/api/auth/me', [
                'name' => 'Updated Name',
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'id',
                    'name',
                    'email',
                ],
            ])
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $user->id,
                    'name' => 'Updated Name',
                    'email' => 'test@example.com', // Email unchanged
                ],
            ]);

        // Verify in database
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Updated Name',
            'email' => 'test@example.com',
        ]);
    }

    public function test_user_cannot_update_email(): void
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'original@example.com',
            'password' => Hash::make('password'),
        ]);

        $originalEmail = $user->email;

        // Attempt to update email (should be ignored or fail validation)
        $response = $this->actingAsUser($user)
            ->putJson('/api/auth/me', [
                'name' => 'Updated Name',
                'email' => 'newemail@example.com',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'email' => $originalEmail, // Email should remain unchanged
                ],
            ]);

        // Verify email unchanged in database
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'email' => $originalEmail,
        ]);

        $this->assertDatabaseMissing('users', [
            'id' => $user->id,
            'email' => 'newemail@example.com',
        ]);
    }

    public function test_update_name_requires_validation(): void
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
        ]);

        // Missing name
        $response = $this->actingAsUser($user)
            ->putJson('/api/auth/me', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);

        // Empty name
        $response = $this->actingAsUser($user)
            ->putJson('/api/auth/me', [
                'name' => '',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_unauthenticated_user_cannot_update_profile(): void
    {
        $response = $this->putJson('/api/auth/me', [
            'name' => 'New Name',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
            ]);
    }
}
