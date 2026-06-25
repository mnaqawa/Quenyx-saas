<?php

use App\Services\Ai\Providers\MockAiProvider;
use App\Services\Ai\Providers\OpenAiProvider;
use App\Services\Compliance\Rag\Providers\OpenAiVectorRetrievalProvider;
use App\Services\Ai\Skills\CorpusSearchSkill;
use App\Services\Ai\Skills\EvidenceSkill;
use App\Services\Ai\Skills\FrameworkMappingSkill;
use App\Services\Ai\Skills\GapAssessmentSkill;
use App\Services\Ai\Skills\KnowledgeGraphSkill;
use App\Services\Ai\Skills\RecommendationSkill;

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
    | Compliance Copilot v0 (QCIF Sprint 14). The Copilot reuses the master AI switch
    | (feature_flags.enabled) to decide mock vs AI mode. Conversation persistence and prompt
    | logging are OFF by default and gated by their OWN env vars so the Copilot can stay silent
    | (no message content stored) even if the orchestration platform enables them, and vice versa.
    */
    'copilot' => [
        'enabled' => (bool) env('COPILOT_ENABLED', true),
        'persist_conversations' => (bool) env('AI_CONVERSATION_PERSISTENCE_ENABLED', env('AI_PERSIST_CONVERSATIONS', false)),
        'prompt_logging' => (bool) env('AI_PROMPT_LOGGING_ENABLED', env('AI_PROMPT_LOGGING', false)),

        // Safe default scope (QCIF Sprint 14.1) used when a request omits framework/release so
        // corpus/graph/mapping intents work in demos without manual scoping. Resolved ONLY by
        // ComplianceCopilotScopeResolver (the sanctioned DB boundary) — never by Copilot core.
        'default_scope' => [
            'framework' => env('COPILOT_DEFAULT_FRAMEWORK', 'nca-ecc'),
            'release' => env('COPILOT_DEFAULT_RELEASE', '2:2024'),
        ],

        // QCIF Sprint 15: when true, the Copilot attaches an optional deterministic
        // `retrieval_context` block (built from the SAME skill responses — skills are not re-run).
        // OFF by default; the existing Copilot flow is unchanged when disabled.
        'retrieval_enabled' => (bool) env('AI_COPILOT_RETRIEVAL_ENABLED', false),

        // QCIF Sprint 16: when true (default), the deterministic Compliance Reasoning Engine decides
        // WHAT to answer and the Prompt Orchestrator composes the prompt from the ReasoningOutput
        // instead of raw skills. Reasoning is always deterministic (no AI/DB); disabling only falls
        // back to the legacy skill-composed prompt.
        'reasoning_enabled' => (bool) env('AI_COPILOT_REASONING_ENABLED', true),

        // QCIF Sprint 17: when true, the Copilot runs Hybrid Retrieval + builds a bounded, cited RAG
        // context package that is appended to the prompt. OFF by default; the Sprint 16 flow is
        // unchanged when disabled. Requires rag.enabled to actually consult a vector provider.
        'rag_enabled' => (bool) env('AI_COPILOT_RAG_ENABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | QCIF Sprint 17 — RAG Runtime & Vector Provider Foundation
    |--------------------------------------------------------------------------
    | Provider-agnostic, feature-flagged RAG runtime. Everything is OFF by
    | default. Without a real vector backend (e.g. pgvector) the OpenAI provider
    | runs in METADATA-ONLY mode: it may compute embeddings but stores metadata
    | only and NEVER fakes vector similarity — search falls back to deterministic
    | retrieval. Tenant evidence is NEVER indexed by default. No direct OpenAI
    | calls happen outside provider classes (embeddings go through the registry).
    */
    'rag' => [
        'enabled' => (bool) env('RAG_ENABLED', false),
        'embeddings_enabled' => (bool) env('EMBEDDINGS_ENABLED', false),

        // 'openai' or null. Null/unknown ⇒ no vector provider ⇒ deterministic only.
        'vector_provider' => env('VECTOR_PROVIDER'),

        'embeddings_model' => env('OPENAI_EMBEDDINGS_MODEL'),

        // Tenant evidence embeddings are OFF by default (privacy). Indexing only ever covers the
        // approved active corpus revision unless this is explicitly enabled.
        'index_tenant_evidence' => (bool) env('RAG_INDEX_TENANT_EVIDENCE', false),

        // Approximate token budget for the bounded RAG context package.
        'token_budget' => (int) env('RAG_TOKEN_BUDGET', 6000),

        // Vector provider registry (mirrors ai.providers). Only implemented: openai.
        'providers' => [
            'openai' => [
                'class' => OpenAiVectorRetrievalProvider::class,
                'ai_provider' => env('RAG_AI_PROVIDER', 'openai'),
            ],
        ],

        'rate_limits' => [
            'query' => [
                'max_attempts' => (int) env('COMPLIANCE_RAG_RATE_LIMIT', 30),
                'decay_minutes' => 1,
            ],
        ],
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
            'evidence' => [
                'class' => EvidenceSkill::class,
                'priority' => 70,
                'enabled' => (bool) env('AI_SKILL_EVIDENCE_ENABLED', true),
            ],
            'gap_assessment' => [
                'class' => GapAssessmentSkill::class,
                'priority' => 60,
                'enabled' => (bool) env('AI_SKILL_GAP_ASSESSMENT_ENABLED', true),
            ],
            'recommendation' => [
                'class' => RecommendationSkill::class,
                'priority' => 50,
                'enabled' => (bool) env('AI_SKILL_RECOMMENDATION_ENABLED', true),
            ],

            // Future skills (later sprints — NOT implemented here):
            // 'copilot'  => ComplianceCopilotSkill::class,
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
