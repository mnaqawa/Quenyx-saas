<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AuthSessionPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $email = 'admin@session.test'): User
    {
        return User::create([
            'name' => 'Session Admin',
            'email' => $email,
            'password' => Hash::make('SuperSecret123!'),
        ]);
    }

    public function test_new_login_revokes_previous_session_token(): void
    {
        config(['auth.session.single_session' => true]);

        $user = $this->makeUser();

        $first = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'SuperSecret123!',
        ]);
        $first->assertOk();
        $oldToken = $first->json('data.token');
        $this->assertNotEmpty($oldToken);

        $second = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'SuperSecret123!',
        ]);
        $second->assertOk();
        $newToken = $second->json('data.token');
        $this->assertNotEmpty($newToken);
        $this->assertNotSame($oldToken, $newToken);

        $this->assertEquals(1, $user->fresh()->tokens()->count());

        $this->withToken($oldToken)
            ->getJson('/api/auth/me')
            ->assertStatus(401);

        $this->withToken($newToken)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', $user->email);
    }

    public function test_idle_timeout_revokes_stale_token(): void
    {
        config([
            'auth.session.single_session' => true,
            'auth.session.idle_timeout_minutes' => 15,
        ]);

        $user = $this->makeUser('idle@session.test');
        $plain = $user->createToken('api')->plainTextToken;

        /** @var PersonalAccessToken $token */
        $token = $user->tokens()->firstOrFail();
        $token->forceFill([
            'last_used_at' => now()->subMinutes(20),
        ])->save();

        $this->withToken($plain)
            ->getJson('/api/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('code', 'session_idle_expired');

        $this->assertEquals(0, $user->fresh()->tokens()->count());
    }

    public function test_recent_activity_keeps_session_alive(): void
    {
        config(['auth.session.idle_timeout_minutes' => 15]);

        $user = $this->makeUser('active@session.test');
        $plain = $user->createToken('api')->plainTextToken;

        /** @var PersonalAccessToken $token */
        $token = $user->tokens()->firstOrFail();
        $token->forceFill([
            'last_used_at' => now()->subMinutes(5),
        ])->save();

        $this->withToken($plain)
            ->getJson('/api/auth/me')
            ->assertOk();
    }
}
