<?php

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing (CORS) Configuration
|--------------------------------------------------------------------------
|
| GA HARDENING: Origins are now driven by environment configuration instead
| of a hardcoded wildcard. In production, set CORS_ALLOWED_ORIGINS to a
| comma-separated allowlist (e.g. "https://app.example.com,https://admin.example.com").
| When the variable is empty the config falls back to "*" to preserve local
| development behavior — production deployments MUST set an explicit allowlist.
|
| When supports_credentials is true, browsers require a concrete origin (the
| "*" wildcard is rejected), so production MUST set an explicit allowlist when
| credentials are enabled.
|
*/

$origins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))
)));

$originPatterns = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('CORS_ALLOWED_ORIGIN_PATTERNS', ''))
)));

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'broadcasting/auth'],

    'allowed_methods' => ['*'],

    'allowed_origins' => ! empty($origins) ? $origins : ['*'],

    'allowed_origins_patterns' => $originPatterns,

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => (int) env('CORS_MAX_AGE', 0),

    'supports_credentials' => (bool) env('CORS_SUPPORTS_CREDENTIALS', false),

];
