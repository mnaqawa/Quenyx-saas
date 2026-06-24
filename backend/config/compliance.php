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

    /*
    |--------------------------------------------------------------------------
    | Knowledge Graph Layer (QCIF Sprint 7)
    |--------------------------------------------------------------------------
    | Deterministic, read-only, UUID-only intra-framework graph navigation. NO AI
    | execution. Caching reuses the revision-keyed corpus cache; only the rate
    | limit is configured here.
    */
    'graph' => [

        'rate_limits' => [
            'read' => [
                'max_attempts' => (int) env('COMPLIANCE_GRAPH_READ_RATE_LIMIT', 120),
                'decay_minutes' => 1,
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Cross-Framework Mapping Foundation (QCIF Sprint 8)
    |--------------------------------------------------------------------------
    | Deterministic, read-only, UUID-only objective-based mappings. NO AI
    | execution. Caching reuses the revision-keyed corpus cache; only the rate
    | limit is configured here.
    */
    'mappings' => [

        'rate_limits' => [
            'read' => [
                'max_attempts' => (int) env('COMPLIANCE_MAPPING_READ_RATE_LIMIT', 120),
                'decay_minutes' => 1,
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Evidence Intelligence Foundation (QCIF Sprint 11)
    |--------------------------------------------------------------------------
    | Tenant evidence as a first-class object. Read-only, UUID-only, workspace
    | scoped. NO AI execution, no uploads/blob/OCR. Only the rate limit is
    | configured here.
    */
    'evidence' => [

        'rate_limits' => [
            'read' => [
                'max_attempts' => (int) env('COMPLIANCE_EVIDENCE_READ_RATE_LIMIT', 120),
                'decay_minutes' => 1,
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Gap Assessment & Evidence Correlation Engine (QCIF Sprint 12)
    |--------------------------------------------------------------------------
    | The first deterministic Compliance Intelligence Engine. Read-only,
    | UUID-only, workspace scoped, fully explainable. NO AI execution, NO
    | probabilistic scoring. Caching reuses the revision-keyed corpus cache plus
    | a workspace evidence fingerprint; only the rate limit is configured here.
    */
    'gap' => [

        'rate_limits' => [
            'read' => [
                'max_attempts' => (int) env('COMPLIANCE_GAP_READ_RATE_LIMIT', 120),
                'decay_minutes' => 1,
            ],
        ],

    ],

];
