# QCIF Sprint 14 — Compliance Copilot v0

> **Module:** QynShield · **Layer:** User-facing AI orchestration (backend/API only)
> **Status:** Complete · **Mode:** Mock by default, AI-capable via the AI Provider Registry

The Compliance Copilot is the first **user-facing** capability of QynShield. It answers a
**closed, deterministic set** of compliance questions by orchestrating the existing AI Skills and —
only when AI is explicitly enabled — calling a model **through the provider-agnostic AI
Orchestration Platform**. It is grounded in deterministic skill output and **enforces citations**:
no citation (or grounding), no answer.

This is **not** a chatbot. There is no open-ended chat, no RAG, no vector search, no OCR, no file
upload, and no direct provider SDK calls.

---

## 1. Architecture

```
                ┌─────────────────────────────────────────────────────────────┐
                │                 ComplianceCopilotController                   │
                │  auth:sanctum · project membership · QynShield entitlement    │
                │  audit (metadata only) · rate limit · validation              │
                └───────────────┬─────────────────────────────┬───────────────┘
                                │                             │
                  ComplianceCopilotSessionService     ComplianceCopilotService
                  (persistence boundary,              (orchestrator — DB-free)
                   via AiConversationRepository)               │
                                                               │
   ┌───────────────┬───────────────┬───────────────┬──────────┴───────────────┐
   │               │               │               │                          │
 Planner        SkillSelector    AiSkillRouter   CompliancePrompt        AiProviderRegistry
 (intent)       (intent→skills)  (executeMany)   Orchestrator            (mock | configured)
                                       │          (composeFromSkills)            │
                            ┌──────────┴──────────┐                             │
                            │  Existing AI Skills │                      (only when AI_ENABLED)
                            │  corpus_search,     │
                            │  knowledge_graph,   │           ComplianceCopilotCitationVerifier
                            │  framework_mapping, │           (fail closed: no citation→no answer)
                            │  evidence,          │           ComplianceCopilotResponseValidator
                            │  gap_assessment,    │           (guardrail warnings)
                            │  recommendation     │
                            └─────────────────────┘
```

**Core principle — the Copilot never touches the database or a provider directly.** It only uses:

- **Skill Router** (`AiSkillRouter`) → existing **AI Skills**
- **Prompt Orchestrator** (`CompliancePromptOrchestrator`)
- **AI Provider Registry** (`AiProviderRegistry`)

It does **not** bypass workspace security, QynShield entitlement, audit logging, guardrails, or
citations.

### Services added

| Service | Responsibility | DB? |
|---|---|---|
| `ComplianceCopilotService` | End-to-end orchestration (plan → select → execute → compose → answer → verify) | No |
| `ComplianceCopilotPlanner` | Deterministic intent classification + parameter extraction | No |
| `ComplianceCopilotSkillSelector` | Maps an intent to the exact set of skill requests | No |
| `ComplianceCopilotResponseValidator` | Emits guardrail warnings (bilingual, disclaimer, empty) | No |
| `ComplianceCopilotCitationVerifier` | Enforces "no citation, no answer"; flags AI drift | No |
| `ComplianceCopilotSessionService` | Conversation/session state via `AiConversationRepository` | Only via repository boundary |
| `ComplianceCopilotScopeResolver` *(Sprint 14.1)* | Resolves framework/release/revision scope | **Yes — the ONLY Copilot DB boundary** |

---

## 1a. Scope resolver & default scope (Sprint 14.1)

Corpus/graph/mapping skills require a `framework` + `release`. To make the Copilot demo-ready,
`ComplianceCopilotScopeResolver` resolves scope automatically. It is the **only** Copilot service
permitted to touch the database; the core stays DB-free and receives the resolved scope via the
planner's `plan()` method.

**Resolution order:**

1. **Explicit** `framework` + `release` on the request → validated (`source = explicit`). An
   explicit but **invalid** scope fails clearly with HTTP 422 + `scope_unresolved`.
2. **Configured default** — NCA **ECC-2:2024** (`config('ai.copilot.default_scope')`, keys
   `nca-ecc` / `2:2024`) if it exists (`source = defaulted`, warning `default_scope_used`).
3. **Primary** — the single framework release with an active corpus revision (`source = defaulted`).
4. **Unresolved** — engine intents still work; corpus/graph/mapping intents fail closed honestly
   (no fabricated answer).

The resolved scope is attached to the plan and passed to **every** selected skill, and is surfaced
in the response `scope` block. Config:

```php
'copilot' => [
    'default_scope' => [
        'framework' => env('COPILOT_DEFAULT_FRAMEWORK', 'nca-ecc'),
        'release' => env('COPILOT_DEFAULT_RELEASE', '2:2024'),
    ],
],
```

The `scope` block (added to the response contract):

```json
"scope": {
  "framework_key": "nca-ecc",
  "release_code": "2:2024",
  "revision_uuid": "…",
  "source": "explicit | defaulted | invalid | unresolved",
  "warnings": ["default_scope_used"]
}
```

When scope is defaulted, `default_scope_used` appears in both `scope.warnings` and the top-level
`warnings`.

## 2. Intent model (deterministic)

Intent classification is **rule-based** (keywords + a code regex). The same message always
produces the same intent — there is no LLM-based routing and no probability.

| Intent | Example | Skills | Requires corpus citations |
|---|---|---|---|
| `control_explanation` | "Explain control 1-1-1" | `corpus_search`, `knowledge_graph` | **Yes** |
| `gap_summary` | "Summarize our compliance gaps" | `gap_assessment`, `recommendation` | No (engine-grounded) |
| `evidence_status` | "What evidence do we have for control 2-8-4?" | `evidence`, `gap_assessment` | No (engine-grounded) |
| `recommendation_summary` | "What should we fix first?" | `gap_assessment`, `recommendation` | No (engine-grounded) |
| `search_corpus` | "Find controls related to access management" | `corpus_search`, `knowledge_graph`, `framework_mapping` | **Yes** |
| `unsupported_intent` | "What's the weather?" | — | — (structured rejection) |

Classification order (first match wins): evidence → gap → remediation → control explanation
(code present) → corpus search → bare-code fallback → unsupported.

**Code-dependent skills** (`knowledge_graph`, `framework_mapping`) are only requested when a control
code is extracted, so they degrade gracefully instead of failing.

---

## 3. Skill orchestration flow

1. **Plan** — `ComplianceCopilotPlanner::classify()` returns `{intent, code, query}`.
2. **Reject early** — unsupported intents return a structured `unsupported_intent` response.
3. **Select** — `ComplianceCopilotSkillSelector::select()` builds `AiSkillRequest`s. Workspace
   skills (gap/evidence/recommendation) carry `project_id` and resolve their own scope; corpus/
   graph/mapping skills carry `framework` + `release`.
4. **Execute** — `AiSkillRouter::executeMany()` runs each skill. Failures are captured as failed
   responses (never thrown), surfaced as warnings.
5. **Compose** — `CompliancePromptOrchestrator::composeFromSkills()` merges skill payloads,
   de-duplicates **citations**, and unions **guardrails** into one grounded prompt.
6. **Answer** — mock preview or provider call (see §5).
7. **Verify** — citation enforcement (§6) + guardrail validation.
8. **Persist + audit** — session turn (if enabled) + metadata-only audit.

### Grounding

- **Corpus-cited intents** (`control_explanation`, `search_corpus`) require ≥1 **corpus citation**
  (`source_document_key` / `official_reference`) from the corpus/graph/mapping skills.
- **Engine-grounded intents** (gap/evidence/recommendation) are grounded by **deterministic skill
  results** + a corpus-revision reference (these engines return no corpus-text citations by design).
  The verifier accepts a **grounding reference** for these.

---

## 4. Mock vs AI mode

| | `AI_ENABLED=false` (default) | `AI_ENABLED=true` |
|---|---|---|
| `mode` field | `mock` | `ai` |
| Answer source | Deterministic preview composed from skill data | Provider response via `AiProviderRegistry` |
| Provider | none (`mock`) | configured default or requested key |
| External calls | none | only through the registry (no direct SDK) |
| Tokens | 0 | provider usage |

The provider always receives the **composed prompt** (system + user), with **skill context**,
**citations**, and **guardrails** embedded in the system prompt by the orchestrator — so any
provider honors them without provider-specific wiring.

Deterministic preview example (`gap_summary`, mock mode):

> "Compliance gap summary: 124 requirement(s) assessed — 80 satisfied, 44 with gaps. 44
> remediation recommendation(s) available. (Preview mode — AI generation disabled.) This is not
> legal advice."

---

## 5. Security

- **No prompt/answer logging** unless `AI_PROMPT_LOGGING_ENABLED=true`.
- **No user-message persistence** unless `AI_CONVERSATION_PERSISTENCE_ENABLED=true`.
- **Audit captures metadata only** — `user_id`, `project_id`, `conversation_uuid`, `intent`,
  `mode`, `provider`, `timestamp`. **Never** the message, prompt, or answer content
  (`ComplianceCorpusAccessAuditLogger::logCopilot`).
- All endpoints: `auth:sanctum` (outer group) + `project.qynshield` (membership + entitlement) +
  `throttle:compliance-copilot` + audit.
- **UUID-only** — no numeric IDs anywhere in responses (conversation, message, executions, payloads).

Config flags (`config/ai.php` → `copilot`):

```php
'copilot' => [
    'enabled' => env('COPILOT_ENABLED', true),
    'persist_conversations' => env('AI_CONVERSATION_PERSISTENCE_ENABLED', env('AI_PERSIST_CONVERSATIONS', false)),
    'prompt_logging' => env('AI_PROMPT_LOGGING_ENABLED', env('AI_PROMPT_LOGGING', false)),
],
```

Master AI switch stays `config('ai.feature_flags.enabled')` (`AI_ENABLED`).

---

## 6. Citation enforcement (fail closed)

`ComplianceCopilotCitationVerifier`:

- A **non-empty** answer with **no** required grounding → **rejected** with
  `citation_validation_failed`; answers are blanked and `needs_review` is set. **No citation, no
  answer.**
- An **empty** answer (e.g. "no gaps found") is allowed.
- In **AI mode**, if the generated answer mentions **none** of the provided citation/grounding
  tokens (source key, official reference, code, revision/uuid), the answer is kept but flagged
  `needs_review` with `answer_may_reference_uncited_facts` (drift detection).

---

## 7. API

All routes are workspace-scoped and available under both `projects/{project}` and
`workspaces/{project}` prefixes.

| Method | Path | Action |
|---|---|---|
| `POST` | `/api/workspaces/{project}/compliance/copilot/message` | New turn (starts a conversation if persistence on) |
| `GET` | `/api/workspaces/{project}/compliance/copilot/conversations` | List conversations (metadata only) |
| `GET` | `/api/workspaces/{project}/compliance/copilot/conversations/{conversationUuid}` | Show conversation + messages |
| `POST` | `/api/workspaces/{project}/compliance/copilot/conversations/{conversationUuid}/messages` | Continue a conversation |

### Request

```json
POST /api/workspaces/42/compliance/copilot/message
{
  "message": "Explain control 1-1-1",
  "framework": "NCA-ECC",   // optional; required for corpus/graph/mapping intents
  "release": "2024",         // optional
  "provider": null            // optional; only honored when AI_ENABLED=true
}
```

### Response contract

```json
{
  "success": true,
  "data": {
    "conversation_uuid": "0c2f...",
    "message_uuid": "9b1a...",
    "intent": "control_explanation",
    "mode": "mock",
    "answer_en": "Control 1-1-1: refer to the cited official source(s) ... This is not legal advice.",
    "answer_ar": "الضابط 1-1-1: ... هذه ليست استشارة قانونية.",
    "citations": [ { "source_document_key": "NCA-ECC-2024", "official_reference": "1-1-1" } ],
    "skill_results": [
      { "skill": "corpus_search", "success": true, "context_type": "control_profile",
        "execution_uuid": "…", "status": "completed", "duration_ms": 4.1, "citation_count": 1,
        "warnings": [], "error": null, "error_code": null }
    ],
    "guardrails": { "use_only_provided_context": true, "cite_every_claim": true },
    "warnings": [],
    "generated_at": "2026-06-25T00:00:00+00:00"
  }
}
```

Unsupported intent returns the same envelope with `intent: "unsupported_intent"`,
empty `citations`/`skill_results`, and `warnings: ["unsupported_intent"]`.

A citation failure returns blanked answers with
`warnings: ["citation_validation_failed", "needs_review", ...]`.

---

## 8. What this sprint does NOT do

Frontend UI · RAG · vector search · OCR · file upload · automatic remediation · unrestricted
chatbot · direct OpenAI/provider SDK calls · any open-ended/general-purpose questions · any direct
database query inside the Copilot services.

---

## 8a. Demo readiness & current limitations (Sprint 14.1)

- **Demo-ready:** the five demo prompts work **without** passing framework/release — scope
  auto-resolves to NCA ECC-2:2024. See `docs/QCIF_COPILOT_DEMO_SCRIPT_V1.md`.
- **Honest degradation:** corpus/graph/mapping answers depend on what is imported into the corpus;
  if a referenced control is not seeded, the Copilot fails closed instead of inventing an answer.
- **Clear failures:** an explicit, invalid `framework`/`release` returns HTTP 422 with
  `scope_unresolved` (not a silent default).
- **Limitations:** backend/API only (no UI); closed 5-intent set; AI-mode Arabic only when the model
  returns it; single-release scope per turn; no RAG/vector/OCR/upload/auto-remediation.

## 9. Future UI integration

The backend contract is UI-ready:

- **Chat panel** binds to `POST .../message` and renders `answer_en`/`answer_ar`, with `citations`
  shown as expandable source chips and `skill_results` as a "how this was derived" trace.
- **Conversation history** uses the `GET .../conversations` endpoints (only when persistence is
  enabled).
- **Trust signals** — `mode` (mock/ai), `warnings` (e.g. `needs_review`), and `guardrails` drive
  badges so users always know whether an answer is a deterministic preview or a model answer, and
  whether it needs review.
- **Streaming** can later reuse the orchestration platform's existing SSE stream path; v0 is
  request/response only.
```
