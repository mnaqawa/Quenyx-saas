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
