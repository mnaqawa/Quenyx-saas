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

    /*
    |--------------------------------------------------------------------------
    | Recommendation Engine (QCIF Sprint 13)
    |--------------------------------------------------------------------------
    | Deterministic, rule-based remediation recommendations grounded in gap
    | findings. UUID-only, workspace scoped, fully explainable. NO LLM, NO RAG,
    | NO probabilistic scoring. GET endpoints reuse the revision-keyed corpus
    | cache plus a workspace evidence fingerprint; only the rate limit is here.
    */
    'recommendations' => [

        'rate_limits' => [
            'read' => [
                'max_attempts' => (int) env('COMPLIANCE_RECOMMENDATION_READ_RATE_LIMIT', 120),
                'decay_minutes' => 1,
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Compliance Copilot v0 (QCIF Sprint 14)
    |--------------------------------------------------------------------------
    | The first user-facing backend Copilot. Orchestrates existing AI Skills and
    | optionally calls a provider through the AI Provider Registry. UUID-only,
    | workspace scoped, citation-enforced. NO direct DB queries, NO RAG, NO
    | direct provider SDK calls. Only the rate limit is configured here; AI/
    | persistence/prompt-logging feature flags live in config/ai.php (copilot).
    */
    'copilot' => [

        'rate_limits' => [
            'message' => [
                'max_attempts' => (int) env('COMPLIANCE_COPILOT_RATE_LIMIT', 30),
                'decay_minutes' => 1,
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Retrieval & RAG Optimization Foundation (QCIF Sprint 15)
    |--------------------------------------------------------------------------
    | Deterministic retrieval planning, chunking, and explainable ranking that
    | reuse existing AI Skills. UUID-only, workspace scoped, citation-backed.
    | NO vector DB, NO embeddings, NO external retrieval provider, NO AI ranking.
    | Revision-stable modes are cached via the corpus cache; only the rate limit
    | is configured here.
    */
    'retrieval' => [

        'rate_limits' => [
            'query' => [
                'max_attempts' => (int) env('COMPLIANCE_RETRIEVAL_RATE_LIMIT', 60),
                'decay_minutes' => 1,
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Executive Demonstration Platform (QCIF Sprint 18)
    |--------------------------------------------------------------------------
    | Read-only executive/investor/customer demonstration surface. It aggregates
    | and exposes the intelligence already built by the QCIF engines — NO new
    | intelligence, NO fabricated data, NO AI required. UUID-only, deterministic,
    | workspace scoped. Only the rate limit is configured here.
    */
    'executive' => [

        'rate_limits' => [
            'read' => [
                'max_attempts' => (int) env('COMPLIANCE_EXECUTIVE_READ_RATE_LIMIT', 120),
                'decay_minutes' => 1,
            ],
        ],

        /** Default number of recent items returned in the timeline / recent-activity feeds. */
        'timeline_limit' => (int) env('COMPLIANCE_EXECUTIVE_TIMELINE_LIMIT', 50),

        /** Number of historical gap assessments used to build scorecard trends. */
        'trend_window' => (int) env('COMPLIANCE_EXECUTIVE_TREND_WINDOW', 12),

    ],

];
