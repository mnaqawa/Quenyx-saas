<?php

use App\Services\Ai\Providers\MockAiProvider;
use App\Services\Ai\Providers\OpenAiProvider;
use App\Services\Ai\Skills\CorpusSearchSkill;
use App\Services\Ai\Skills\FrameworkMappingSkill;
use App\Services\Ai\Skills\KnowledgeGraphSkill;

/*
|--------------------------------------------------------------------------
| QCIF Sprint 9 — AI Orchestration Platform
|--------------------------------------------------------------------------
| Infrastructure-only configuration for the AI orchestration platform. No
| business AI logic is configured here. AI execution is OFF by default and is
| gated behind feature flags. Models are NEVER hardcoded — they come from env.
*/

return [

    /*
    | Default provider key. Resolved by AiProviderRegistry. Defaults to the mock
    | provider so nothing reaches a real model unless explicitly configured.
    */
    'default' => env('AI_PROVIDER', 'mock'),

    /*
    | Feature flags. Until 'enabled' is true, the orchestration API returns mocked
    | responses and no real provider is invoked.
    */
    'feature_flags' => [
        'enabled' => (bool) env('AI_ENABLED', false),
        'streaming_enabled' => (bool) env('AI_STREAMING_ENABLED', false),
        'persist_conversations' => (bool) env('AI_PERSIST_CONVERSATIONS', false),
        // When false, user/assistant prompt CONTENT is never written to storage.
        'prompt_logging' => (bool) env('AI_PROMPT_LOGGING', false),
    ],

    /*
    | Generation defaults applied when a request omits them. No model here.
    */
    'defaults' => [
        'temperature' => (float) env('AI_TEMPERATURE', 0.0),
        'max_tokens' => (int) env('AI_MAX_TOKENS', 1024),
        'timeout' => (int) env('AI_TIMEOUT', 30),
    ],

    /*
    | Provider registry. Each entry maps a provider key to its implementing class
    | plus provider-specific config. Implemented today: mock, openai. The remaining
    | keys are FUTURE providers — declared here for discovery but intentionally not
    | implemented; selecting one throws until a class is provided.
    */
    'providers' => [

        'mock' => [
            'class' => MockAiProvider::class,
        ],

        'openai' => [
            'class' => OpenAiProvider::class,
            'api_key' => env('OPENAI_API_KEY'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'organization' => env('OPENAI_ORGANIZATION'),
            // Models come from env only — never hardcoded.
            'model' => env('OPENAI_MODEL'),
            'embeddings_model' => env('OPENAI_EMBEDDINGS_MODEL'),
        ],

        // Future providers (declared, not implemented in Sprint 9):
        // 'azure'  => ['class' => null],
        // 'claude' => ['class' => null],
        // 'gemini' => ['class' => null],
        // 'ollama' => ['class' => null],
        // 'local'  => ['class' => null],
    ],

    /*
    | QCIF Sprint 10 — AI Skills Framework. The execution layer between the orchestrator and the
    | Compliance Intelligence services. Skills reuse existing services and return AI Context
    | payloads — they make NO AI/provider calls. Each skill is feature-flagged and prioritized
    | (higher priority is preferred during auto-routing).
    */
    'skills' => [
        'enabled' => (bool) env('AI_SKILLS_ENABLED', true),

        'registered' => [
            'corpus_search' => [
                'class' => CorpusSearchSkill::class,
                'priority' => 100,
                'enabled' => (bool) env('AI_SKILL_CORPUS_SEARCH_ENABLED', true),
            ],
            'knowledge_graph' => [
                'class' => KnowledgeGraphSkill::class,
                'priority' => 90,
                'enabled' => (bool) env('AI_SKILL_KNOWLEDGE_GRAPH_ENABLED', true),
            ],
            'framework_mapping' => [
                'class' => FrameworkMappingSkill::class,
                'priority' => 80,
                'enabled' => (bool) env('AI_SKILL_FRAMEWORK_MAPPING_ENABLED', true),
            ],

            // Future skills (later sprints — NOT implemented here):
            // 'evidence'        => EvidenceSkill::class,
            // 'gap_assessment'  => GapAssessmentSkill::class,
            // 'recommendation'  => RecommendationSkill::class,
        ],
    ],

    'rate_limits' => [
        'chat' => [
            'max_attempts' => (int) env('AI_CHAT_RATE_LIMIT', 30),
            'decay_minutes' => 1,
        ],
        'skills' => [
            'max_attempts' => (int) env('AI_SKILLS_RATE_LIMIT', 60),
            'decay_minutes' => 1,
        ],
    ],

];
