<?php

/*
|--------------------------------------------------------------------------
| Security Response Headers
|--------------------------------------------------------------------------
|
| GA HARDENING: Centralized configuration for the production security headers
| applied by App\Http\Middleware\SecurityHeaders. Every value is env-driven so
| operators can tune per environment without code changes. Headers are emitted
| only when enabled; HSTS is additionally gated on HTTPS requests.
|
*/

return [

    // Master switch for the SecurityHeaders middleware.
    'headers_enabled' => (bool) env('SECURITY_HEADERS_ENABLED', true),

    // Strict-Transport-Security (only sent over HTTPS). max-age in seconds.
    'hsts' => [
        'enabled' => (bool) env('SECURITY_HSTS_ENABLED', true),
        'max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 31536000), // 1 year
        'include_subdomains' => (bool) env('SECURITY_HSTS_INCLUDE_SUBDOMAINS', true),
        'preload' => (bool) env('SECURITY_HSTS_PRELOAD', false),
    ],

    // Content-Security-Policy. This API returns JSON; a strict default policy
    // is safe here. The SPA is served by the web server (Nginx) and can carry
    // its own policy. Override via SECURITY_CSP if a custom policy is required.
    'csp' => env(
        'SECURITY_CSP',
        "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'"
    ),

    // Set to true to emit Content-Security-Policy-Report-Only instead of enforcing.
    'csp_report_only' => (bool) env('SECURITY_CSP_REPORT_ONLY', false),

    'x_frame_options' => env('SECURITY_X_FRAME_OPTIONS', 'DENY'),

    'x_content_type_options' => env('SECURITY_X_CONTENT_TYPE_OPTIONS', 'nosniff'),

    'referrer_policy' => env('SECURITY_REFERRER_POLICY', 'strict-origin-when-cross-origin'),

    'permissions_policy' => env(
        'SECURITY_PERMISSIONS_POLICY',
        'accelerometer=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()'
    ),

    // Cross-origin isolation hints (safe defaults for a JSON API).
    'cross_origin_opener_policy' => env('SECURITY_COOP', 'same-origin'),
    'cross_origin_resource_policy' => env('SECURITY_CORP', 'same-site'),

];
