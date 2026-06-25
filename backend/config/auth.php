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
