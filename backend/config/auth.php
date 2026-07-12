<?php

return [
    'defaults' => [
        'guard' => 'sanctum',
        'passwords' => 'users',
    ],

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
        'sanctum' => [
            'driver' => 'sanctum',
            'provider' => 'users',
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => App\Models\User::class,
        ],
    ],

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Credential endpoint rate limits (GA hardening)
    |--------------------------------------------------------------------------
    | Per-minute attempt ceilings for the named 'login' / 'register' rate
    | limiters (see App\Providers\RouteServiceProvider). The login limiter is
    | applied per-email AND per-IP for brute-force resistance.
    */
    'rate_limits' => [
        'login' => [
            'max_attempts' => (int) env('AUTH_LOGIN_MAX_ATTEMPTS', 5),
        ],
        'register' => [
            'max_attempts' => (int) env('AUTH_REGISTER_MAX_ATTEMPTS', 10),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Interactive login session policy (Sanctum PATs)
    |--------------------------------------------------------------------------
    | Quenyx portal auth uses Bearer personal access tokens, not cookie sessions.
    | These settings govern those tokens for human users.
    |
    | single_session: when true, a successful login/register revokes all existing
    | tokens for that user so only the newest session remains valid.
    |
    | idle_timeout_minutes: revoke a token when last_used_at (or created_at if
    | never used) is older than N minutes. Set 0 to disable idle expiry.
    | Absolute max lifetime remains SANCTUM_TOKEN_EXPIRATION_MINUTES.
    */
    'session' => [
        'single_session' => filter_var(env('AUTH_SINGLE_SESSION', true), FILTER_VALIDATE_BOOLEAN),
        'idle_timeout_minutes' => (int) env('AUTH_IDLE_TIMEOUT_MINUTES', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Database seeding
    |--------------------------------------------------------------------------
    | Read through the config layer (not env() directly) so the value survives
    | `php artisan config:cache` in production — env() returns null for keys
    | referenced outside of config files once the config is cached.
    */
    'seed' => [
        'admin_password' => env('SEED_ADMIN_PASSWORD'),
    ],
];
