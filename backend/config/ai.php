<?php

return [
    /*
    |--------------------------------------------------------------------------
    | AI Agent master switch
    |--------------------------------------------------------------------------
    | When false, all AI endpoints return 503 (service disabled). This lets you
    | ship the feature dark and turn it on once a provider key is configured.
    */
    'enabled' => (bool) env('AI_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | LLM provider (OpenAI-compatible Chat Completions API)
    |--------------------------------------------------------------------------
    | base_url must point at an OpenAI-compatible endpoint root (no trailing
    | /chat/completions). Works with OpenAI, OpenRouter, Azure-compatible
    | gateways, Together, Groq, or a self-hosted vLLM/Ollama proxy.
    */
    'provider' => env('AI_PROVIDER', 'openai'),
    'base_url' => rtrim(env('AI_BASE_URL', 'https://api.openai.com/v1'), '/'),
    'api_key' => env('AI_API_KEY'),
    'model' => env('AI_MODEL', 'gpt-4o-mini'),

    /*
    |--------------------------------------------------------------------------
    | Generation parameters
    |--------------------------------------------------------------------------
    */
    'temperature' => (float) env('AI_TEMPERATURE', 0.3),
    'max_tokens' => (int) env('AI_MAX_TOKENS', 900),
    'timeout' => (int) env('AI_TIMEOUT', 60),

    /*
    |--------------------------------------------------------------------------
    | Conversation / context limits (guardrails)
    |--------------------------------------------------------------------------
    | history_limit: max prior turns accepted from the client per request.
    | message_max_chars: max length of a single user message.
    | context_* : how much real telemetry we summarize into the system prompt.
    */
    'history_limit' => (int) env('AI_HISTORY_LIMIT', 12),
    'message_max_chars' => (int) env('AI_MESSAGE_MAX_CHARS', 4000),
    'context_max_hosts' => (int) env('AI_CONTEXT_MAX_HOSTS', 25),
    'context_metric_chars' => (int) env('AI_CONTEXT_METRIC_CHARS', 1500),

    /*
    |--------------------------------------------------------------------------
    | Module gating
    |--------------------------------------------------------------------------
    | The workspace must have access to this module for the AI agent to run.
    | QynSight is the observability module the AI agent belongs to.
    */
    'required_module' => env('AI_REQUIRED_MODULE', 'qynsight'),
];
