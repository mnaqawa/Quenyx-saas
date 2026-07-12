<?php

namespace App\Services\Auth;

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

class AuthSessionService
{
    public function singleSessionEnabled(): bool
    {
        return (bool) config('auth.session.single_session', true);
    }

    public function idleTimeoutMinutes(): int
    {
        return max(0, (int) config('auth.session.idle_timeout_minutes', 30));
    }

    /**
     * Revoke every existing Sanctum token for the user (single active session).
     */
    public function revokeAllSessions(User $user): int
    {
        return (int) $user->tokens()->delete();
    }

    /**
     * Issue a new portal API token, optionally revoking prior sessions first.
     */
    public function issuePortalToken(User $user, string $name = 'api'): string
    {
        if ($this->singleSessionEnabled()) {
            $this->revokeAllSessions($user);
        }

        return $user->createToken($name)->plainTextToken;
    }

    /**
     * Whether the token has exceeded the idle timeout.
     * Uses last_used_at when present; otherwise created_at.
     */
    public function isIdleExpired(PersonalAccessToken $token): bool
    {
        $minutes = $this->idleTimeoutMinutes();
        if ($minutes <= 0) {
            return false;
        }

        $lastActivity = $token->last_used_at ?? $token->created_at;
        if ($lastActivity === null) {
            return false;
        }

        return $lastActivity->lt(now()->subMinutes($minutes));
    }
}
