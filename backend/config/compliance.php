<?php

return [

    'corpus' => [

        'cache_enabled' => env('COMPLIANCE_CORPUS_CACHE_ENABLED', true),

        /** Seconds. Revision UUID is part of cache keys — new revision auto-misses stale entries. */
        'cache_ttl' => (int) env('COMPLIANCE_CORPUS_CACHE_TTL', 3600),

        'rate_limits' => [
            'read' => [
                'max_attempts' => (int) env('COMPLIANCE_CORPUS_READ_RATE_LIMIT', 120),
                'decay_minutes' => 1,
            ],
            'search' => [
                'max_attempts' => (int) env('COMPLIANCE_CORPUS_SEARCH_RATE_LIMIT', 30),
                'decay_minutes' => 1,
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | AI Consumption Contract Layer (QCIF Sprint 6)
    |--------------------------------------------------------------------------
    | Deterministic, read-only AI-ready payloads. NO AI execution. Caching reuses
    | the revision-keyed corpus cache; only the rate limits are configured here.
    */
    'ai_context' => [

        'rate_limits' => [
            'read' => [
                'max_attempts' => (int) env('COMPLIANCE_AI_CONTEXT_READ_RATE_LIMIT', 120),
                'decay_minutes' => 1,
            ],
            'search' => [
                'max_attempts' => (int) env('COMPLIANCE_AI_CONTEXT_SEARCH_RATE_LIMIT', 30),
                'decay_minutes' => 1,
            ],
        ],

    ],

];
