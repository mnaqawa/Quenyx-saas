<?php

use App\Services\AI\Providers\MockAiProvider;
use App\Services\AI\Providers\OpenAiProvider;
use App\Services\Compliance\Rag\Providers\OpenAiVectorRetrievalProvider;
use App\Services\AI\Skills\CorpusSearchSkill;
use App\Services\AI\Skills\EvidenceSkill;
use App\Services\AI\Skills\FrameworkMappingSkill;
use App\Services\AI\Skills\GapAssessmentSkill;
use App\Services\AI\Skills\KnowledgeGraphSkill;
use App\Services\AI\Skills\RecommendationSkill;

/*
|--------------------------------------------------------------------------
| QCIF Sprint 9 — AI Orchestration Platform
|--------------------------------------------------------------------------
| Infrastructure-only configuration for the AI orchestration platform. No
| business AI logic is configured here. AI execution is OFF by default and is
| gated behind feature flags. Models are NEVER hardcoded — they come from env.
*/

$aiEnabledRaw = env('AI_ENABLED');
$aiEnabledTriState = ($aiEnabledRaw === null || $aiEnabledRaw === '')
    ? null
    : filter_var($aiEnabledRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

return [

    /*
    | Default provider key. Resolved by AiProviderRegistry::defaultKey(). When AI_PROVIDER is unset
    | the registry derives the default safely: it prefers a real configured provider (OpenAI when its
    | key is present) and only falls back to the dev-only `mock` provider in local/testing. In
    | production with nothing configured the default resolves to '' (an honest "no provider
    | configured" state) — the mock provider is NEVER the production default. Setting AI_PROVIDER
    | explicitly always overrides this resolution.
    */
    'default' => env('AI_PROVIDER'),

    /*
    | Feature flags. AI_ENABLED=false explicitly disables live execution. When AI_ENABLED is unset,
    | live execution auto-enables when a real provider (e.g. OpenAI with OPENAI_API_KEY) is configured.
    | Mock is never the silent production default — see AiExecutionResolver.
    */
    'feature_flags' => [
        // null = unset (auto-enable when OpenAI / another provider is configured). false = admin disabled.
        'enabled' => $aiEnabledTriState,
        'mock_allowed' => (bool) env('AI_MOCK_ALLOWED', false),
        'streaming_enabled' => (bool) env('AI_STREAMING_ENABLED', false),
        'persist_conversations' => (bool) env('AI_PERSIST_CONVERSATIONS', false),
        // When false, user/assistant prompt CONTENT is never written to storage.
        'prompt_logging' => (bool) env('AI_PROMPT_LOGGING', false),

        // Sprint 20 — Unified AI Workspace. Master switch for the platform-level AI Workspace
        // surface (read APIs + admin). ON by default: the surface is safe with no real provider
        // configured (chat falls back to the mock provider; usage/costs are derived from real data
        // and simply read 0 when nothing has been recorded).
        'workspace_enabled' => (bool) env('AI_WORKSPACE_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sprint 20 — Unified AI Workspace
    |--------------------------------------------------------------------------
    | Platform-level workspace AI surface. Cost tracking is DERIVED from the real
    | token counts already stored on ai_conversations — never fabricated. A price
    | per 1K tokens may be configured per provider/model; when no price is
    | configured the cost APIs return token totals with pricing_configured=false
    | and NO currency amounts (so the UI shows an honest "pricing not configured"
    | state rather than a fake number).
    */
    'workspace' => [
        'currency' => env('AI_COST_CURRENCY', 'USD'),

        // Conversation list / activity page size caps.
        'max_conversations' => (int) env('AI_WORKSPACE_MAX_CONVERSATIONS', 50),
        'max_activity' => (int) env('AI_WORKSPACE_MAX_ACTIVITY', 50),

        // When OPENAI_VECTOR_STORE_ID is set, workspace chat uses File Search (same KB as Ask Quenyx AI).
        'knowledge_enabled' => (bool) env('AI_WORKSPACE_KNOWLEDGE_ENABLED', true),
        'file_search_max_results' => (int) env('AI_WORKSPACE_FILE_SEARCH_MAX_RESULTS', 3),
        'max_history_messages' => (int) env('AI_WORKSPACE_MAX_HISTORY_MESSAGES', 20),

        // Optional pricing per 1,000 tokens.
        // [prompt, completion] price pair (floats). Leave empty for token-only mode.
        // Example: 'openai' => ['prompt' => 0.005, 'completion' => 0.015].
        'pricing' => [
            // 'openai' => ['prompt' => (float) env('AI_PRICE_OPENAI_PROMPT', 0), 'completion' => (float) env('AI_PRICE_OPENAI_COMPLETION', 0)],
        ],
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

        // QCIF Sprint 18: demo mode surfaces the deterministic BUSINESS reasoning behind an answer —
        // reasoning trace, citations, retrieved chunks, recommendation sources, and evidence chain —
        // in a single `demo` block. It exposes existing intelligence only; it is NOT chain-of-thought
        // and adds NO new reasoning. OFF by default.
        'demo_mode' => (bool) env('AI_COPILOT_DEMO_MODE', false),
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
        'max_tokens' => (int) env('AI_MAX_TOKENS', 2048),
        // gpt-5 / o-series reasoning models need a higher ceiling so visible text is not truncated.
        'max_tokens_reasoning' => (int) env('AI_MAX_TOKENS_REASONING', 4096),
        'timeout' => (int) env('AI_TIMEOUT', 180),
        'knowledge_timeout' => (int) env('AI_KNOWLEDGE_TIMEOUT', 180),
    ],

    /*
    | EXECUTABLE provider registry. Each entry maps a provider key to its implementing adapter class
    | plus provider-specific config. A class here means the provider can make LIVE calls. Implemented
    | today: openai (production execution adapter) and mock (dev-only fallback used when AI execution
    | is disabled). The broader, customer-visible provider CATALOG (OpenAI, Anthropic, Gemini, Azure
    | OpenAI, OpenRouter, Mistral, Cohere, xAI, Ollama, LM Studio, vLLM, LiteLLM, Hugging Face, Custom
    | OpenAI-compatible) lives in App\Services\AI\AiProviderCatalog and is "configurable but not
    | executable" until an adapter is added here — nothing fabricates connectivity.
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
        // Sprint 20 — Unified AI Workspace read/admin endpoints.
        'workspace' => [
            'max_attempts' => (int) env('AI_WORKSPACE_RATE_LIMIT', 120),
            'decay_minutes' => 1,
        ],
    ],

];
