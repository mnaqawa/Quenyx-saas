<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class AuthSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_logging_does_not_include_plaintext_password(): void
    {
        User::create([
            'name' => 'Security Test User',
            'email' => 'security@test.local',
            'password' => Hash::make('SuperSecret123!'),
        ]);

        Log::spy();

        $response = $this->postJson('/api/auth/login', [
            'email' => 'security@test.local',
            'password' => 'SuperSecret123!',
        ]);

        $response->assertStatus(200);

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context): bool {
            if ($message !== 'Login request received') {
                return true;
            }
            if (array_key_exists('all_input', $context)) {
                return false;
            }
            $contextJson = json_encode($context);
            return is_string($contextJson)
                && !str_contains($contextJson, 'SuperSecret123!')
                && !array_key_exists('password', $context)
                && array_key_exists('email_masked', $context);
        })->atLeast()->once();
    }
}

