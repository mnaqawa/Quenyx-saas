<?php

return [

    /*
    |--------------------------------------------------------------------------
    | OpenAI API Key & Organization
    |--------------------------------------------------------------------------
    |
    | Consumed by the openai-php/laravel package when resolving the shared
    | OpenAI client. Keep these in the environment, never in version control.
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

    'request_timeout' => (int) env('OPENAI_REQUEST_TIMEOUT', 60),

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

    'vector_store_id' => env('OPENAI_VECTOR_STORE_ID'),

];
