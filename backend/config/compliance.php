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

];
