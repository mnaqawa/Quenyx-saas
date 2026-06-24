# QCIF Sprint 9 — AI Orchestration Platform

**Phase:** AI infrastructure (no business AI)
**Scope:** Provider abstraction, OpenAI (Responses API) provider, provider registry, prompt orchestrator, session model, workspace API, configuration, security.
**Out of scope (explicitly NOT in this sprint):** Gap Assessment, Evidence Intelligence, Compliance Copilot, or any other business intelligence. This sprint builds *only* the orchestration platform.

---

## Why orchestration exists

Through Sprint 8, QCIF deliberately had **no AI execution**. It built the trustworthy
foundation first: corpus, revisioning, provenance, citations, knowledge graph, AI Contract
Layer, and the cross-framework mapping foundation.

Sprint 9 adds the **plumbing** to *eventually* run AI — without yet running any business AI.
The orchestration platform answers one question: "when we do call a model, how do we do it
safely, swappably, and auditably?" It provides:

- a **provider-agnostic** execution contract (so we are never locked to one vendor),
- a single **registry** that turns config into a provider (so swapping is a config change),
- a **prompt orchestrator** that turns an already-grounded AI Context payload into a prompt
  (so corpus access stays out of the AI layer),
- a **session model** that records usage/tokens but not tenant knowledge,
- **feature flags** so AI stays OFF until explicitly enabled (today everything is mocked).

This separation means the future business features (Copilot, Gap Assessment, Evidence
Intelligence) are built *on top of* a stable, secure, vendor-neutral substrate.

---

## Provider model

```
Controller ─► AiProviderRegistry.get(key)  ─►  AiProviderInterface
                     │                              ├── MockAiProvider     (default; no network)
              config/ai.php                         ├── OpenAiProvider     (Responses API)
           (key → class + config)                   └── (future) Azure / Claude / Gemini / Ollama / Local
```

- **`AiProviderInterface`** is the only thing the platform depends on. It declares `key()`,
  `chat()`, `stream()`, `embeddings()`, `responses()`, `health()`, `supportedCapabilities()`.
- **All provider-specific code** (HTTP, wire formats, model names) lives inside provider
  classes. No other layer references a provider class directly.
- **`AiProviderRegistry`** is the single place that maps a config key to a concrete instance.
  `default()`, `available()`, `has()`, `get(key)` give default provider, discovery, and
  switching. Unknown keys and not-yet-implemented providers throw a clear `AiProviderException`.
- Everything flows through provider-agnostic **DTOs** (`AiCompletionRequest/Response`,
  `AiMessage`, `AiUsage`, `AiStreamChunk`, `AiEmbeddings*`, `AiProviderHealth`,
  `AiCapability`).

### OpenAI provider

`OpenAiProvider` is built on the **Responses API** — **not** the Assistants API and **not**
legacy Chat Completions. It supports:

- **Structured JSON** (`text.format` = `json_schema` or `json_object`),
- **Streaming** (SSE parsing of `response.output_text.delta` / `response.completed`),
- **Citations** (carried from output-text `annotations`),
- **Response metadata** (response id, status, usage),
- **Health check** (`GET /models`, never throws).

Configuration (API key, base URL, organization, model, embeddings model) comes **only** from
`config/ai.php`/env. **No model is hardcoded** — if no model is configured, the provider
throws a misconfiguration error rather than guessing.

---

## Future providers

Adding a provider is a contained change:

1. Implement `AiProviderInterface` in a new class under `app/Services/Ai/Providers/`.
2. Add a `providers.<key>` entry to `config/ai.php` with its `class` + config.
3. Optionally set `AI_PROVIDER=<key>` to make it the default.

No controller, orchestrator, DTO, route, or API change is required. Declared-but-unimplemented
keys (Azure, Claude, Gemini, Ollama, Local) are documented in config and throw
`ai_provider_not_implemented` until a class is supplied.

---

## AI lifecycle

```
POST /api/workspaces/{project}/ai/chat
  → auth:sanctum → project.qynshield → throttle:ai-orchestration → ProjectPolicy::view
  → validate input (prompt/message [, ai_context, provider, response_format, conversation])
  → resolve provider:
        AI disabled?  → MockAiProvider   (always, regardless of requested key)
        AI enabled?   → registry.get(requested ?? default)
  → build prompt:
        ai_context present → CompliancePromptOrchestrator.buildPrompt(payload, question)
        else               → minimal system + user messages
  → build AiCompletionRequest (model=null → provider resolves from config)
  → audit log (provider + endpoint + context_type; NO content)
  → provider.responses(request)            // mock when disabled
  → persist (only if persist_conversations): conversation + messages
        message CONTENT stored ONLY if prompt_logging is enabled
  → JSON envelope { conversation_uuid, ai_enabled, provider, model, content, structured,
                    citations, usage, mocked, generated_at }
```

`POST …/ai/stream` is the same, but returns `text/event-stream`; it uses the mock provider
unless **both** `enabled` and `streaming_enabled` are true.

### The orchestrator boundary

`CompliancePromptOrchestrator` accepts an **AI Context payload** (the Sprint 6 AI Contract
envelope) and a user question, and produces an `AiPrompt` (system prompt + user prompt +
citations + guardrails). It performs **no corpus querying and no database access** — it only
transforms the array it is given. Citations and guardrails are embedded into the system prompt
so any provider honors them.

---

## Session model

| Table | Purpose |
| --- | --- |
| `ai_conversations` | One row per conversation: uuid, project, user, provider, model, status, message_count, token totals, usage/metadata, timestamps. |
| `ai_conversation_messages` | One row per message: uuid, role, **content (nullable)**, token counts, mocked flag, metadata, timestamps. |

Access is **repository-only** (`AiConversationRepository`) — no other AI service touches the
database. **No tenant knowledge is stored by default:** message `content` is written *only*
when `prompt_logging` is enabled, and persistence happens *only* when `persist_conversations`
is enabled. Token counts and provider metadata are always safe to store.

---

## Configuration (`config/ai.php`)

| Key | Meaning |
| --- | --- |
| `default` | Default provider key (`AI_PROVIDER`, default `mock`) |
| `feature_flags.enabled` | Master AI switch (`AI_ENABLED`, default **false**) |
| `feature_flags.streaming_enabled` | Allow real streaming (`AI_STREAMING_ENABLED`, default false) |
| `feature_flags.persist_conversations` | Persist sessions (`AI_PERSIST_CONVERSATIONS`, default false) |
| `feature_flags.prompt_logging` | Store prompt/response content (`AI_PROMPT_LOGGING`, default false) |
| `defaults.temperature` / `max_tokens` / `timeout` | Generation + HTTP defaults |
| `providers.*` | Key → class + provider config (API key, base URL, **model from env**) |
| `rate_limits.chat` | Throttle config for the AI endpoints |

No models are hardcoded anywhere; they are read from env via config.

---

## Security

| Control | Mechanism |
| --- | --- |
| Workspace isolation | Routes are workspace-scoped; `ProjectPolicy::view` enforced in the controller |
| Module entitlement | `project.qynshield` middleware (membership + QynShield) |
| Audit logging | `AiAccessAuditLogger` (`ai_orchestration_chat` / `ai_orchestration_stream`) — provider + endpoint only, **never content** |
| Prompt logging disabled | `prompt_logging` defaults **false** → message content is never persisted |
| No prompts stored unless enabled | Persistence requires `persist_conversations`; content requires `prompt_logging` |
| AI off by default | `enabled` defaults **false** → mock provider, no external calls |
| Abuse protection | `throttle:ai-orchestration` (default 30/min, `AI_CHAT_RATE_LIMIT`) |

---

## QA results

- **No provider hardcoding** — providers resolved via `config('ai.providers')` + registry;
  models from env; no vendor/model literals in services/controller.
- **No direct DB access** in providers/orchestrator/registry — only `AiConversationRepository`
  (and the controller via it) touches the database.
- **No direct corpus access** — the orchestrator transforms a passed-in payload; it never
  queries the corpus, and neither do the providers.
- **Provider swap possible** — change `AI_PROVIDER` or pass `provider` in the request (when
  enabled); `registry.get(key)` resolves it.
- **Feature flag disables AI** — with `enabled=false` (default), every request is served by
  `MockAiProvider` and no external call is made.
- `php -l` clean; `route:list` shows the workspace chat/stream routes.

---

## Files changed

**New**
- `backend/config/ai.php`
- `backend/app/Enums/Ai/AiCapability.php`, `AiMessageRole.php`
- `backend/app/DataTransferObjects/Ai/` — `AiMessage`, `AiCompletionRequest`,
  `AiCompletionResponse`, `AiUsage`, `AiStreamChunk`, `AiEmbeddingsRequest`,
  `AiEmbeddingsResponse`, `AiProviderHealth`, `AiPrompt`
- `backend/app/Contracts/Ai/AiProviderInterface.php`
- `backend/app/Exceptions/Ai/AiProviderException.php`
- `backend/app/Services/Ai/Providers/OpenAiProvider.php`, `MockAiProvider.php`
- `backend/app/Services/Ai/AiProviderRegistry.php`
- `backend/app/Services/Ai/CompliancePromptOrchestrator.php`
- `backend/app/Services/Ai/AiAccessAuditLogger.php`
- `backend/app/Models/Ai/AiConversation.php`, `AiConversationMessage.php`
- `backend/app/Repositories/Ai/AiConversationRepository.php`
- `backend/app/Http/Controllers/Ai/AiOrchestrationController.php`
- `backend/routes/ai-orchestration.php`
- `backend/database/migrations/2026_06_25_010000_create_ai_orchestration_tables.php`
- `backend/tests/Unit/AiOrchestrationPlatformTest.php`
- `docs/QCIF_SPRINT9_AI_ORCHESTRATION.md`

**Modified**
- `backend/routes/api.php` (wire AI routes)
- `backend/app/Providers/RouteServiceProvider.php` (`ai-orchestration` limiter)
