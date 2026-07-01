<?php

return [

    /*
    |--------------------------------------------------------------------------
    | OpenAI API Key & Organization
    |--------------------------------------------------------------------------
    |
    | Consumed when resolving the shared OpenAI PHP client. Keep these in the
    | environment, never in version control.
    |
    */

    'api_key' => env('OPENAI_API_KEY'),

    'organization' => env('OPENAI_ORGANIZATION'),

    /*
    |--------------------------------------------------------------------------
    | Request Timeout
    |--------------------------------------------------------------------------
    |
    | Seconds before an OpenAI HTTP request is aborted. File Search calls can
    | take longer than plain chat completions, so allow generous headroom.
    |
    */

    'request_timeout' => (int) env('OPENAI_REQUEST_TIMEOUT', 180),

    /*
    |--------------------------------------------------------------------------
    | Quenyx Knowledge Base (Responses API + File Search)
    |--------------------------------------------------------------------------
    |
    | The Responses API model and the existing Vector Store that backs the
    | File Search tool. The Vector Store must already exist in your OpenAI
    | account; this integration does not create or modify it.
    |
    */

    'model' => env('OPENAI_MODEL', 'gpt-5-mini'),

    /*
    |--------------------------------------------------------------------------
    | Per-agent Model Overrides
    |--------------------------------------------------------------------------
    |
    | Optionally pin a different model per agent type. When an override is
    | empty/unset, the agent falls back to the default `model` above. This lets
    | you tune cost/latency per persona without code changes.
    |
    */

    'models' => [
        'performance_analyst' => env('OPENAI_MODEL_PERFORMANCE_ANALYST'),
        'anomaly_detector' => env('OPENAI_MODEL_ANOMALY_DETECTOR'),
        'compliance' => env('OPENAI_MODEL_COMPLIANCE'),
        'capacity_planner' => env('OPENAI_MODEL_CAPACITY_PLANNER'),
    ],

    'vector_store_id' => env('OPENAI_VECTOR_STORE_ID'),

];
