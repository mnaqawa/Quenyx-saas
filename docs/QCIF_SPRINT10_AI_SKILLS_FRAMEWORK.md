# QCIF Sprint 10 — AI Skills Framework

**Phase:** AI execution layer (no business AI)
**Scope:** Skill interface, registry, router, base DTOs, three reuse-only skills (Corpus Search, Knowledge Graph, Framework Mapping), orchestrator update, workspace API.
**Explicitly NOT in this sprint:** EvidenceSkill, GapAssessmentSkill, RecommendationSkill, Compliance Copilot, or any other business intelligence. Those belong to later sprints.

---

## Why the Skills Framework exists

The AI Orchestration Platform (Sprint 9) can talk to a model; the Compliance Intelligence
services (Sprints 1–8) hold the trustworthy, cited corpus knowledge. The Skills Framework is the
**execution layer between them**: a set of discrete, reusable units that turn a request into a
grounded **AI Context payload** by reusing existing services — without calling a model and
without inventing data.

This keeps responsibilities clean:

- **Skills** know *what compliance data to fetch* (reusing corpus/graph/mapping services).
- **The orchestrator** knows *how to turn data into a prompt*.
- **The provider** knows *how to call a model*.

No skill builds a prompt; no skill calls a provider; no skill writes its own database queries.

---

## Architecture

```
AI Provider          (Sprint 9 — how to call a model)
   ▲
AI Orchestrator      (CompliancePromptOrchestrator — composes ONE prompt from skill outputs)
   ▲
Skill Router         (AiSkillRouter — choose + execute + aggregate)
   ▲
Skill Registry       (AiSkillRegistry — registration, discovery, flags, priority)
   ▲
Individual Skills    (CorpusSearchSkill, KnowledgeGraphSkill, FrameworkMappingSkill)
   ▲
Compliance Services  (Sprints 1–8 — the only DB/corpus access)
```

---

## Skill contract

`AiSkillInterface`:

| Method | Purpose |
| --- | --- |
| `key()` | Stable identifier (e.g. `corpus_search`) |
| `displayName()` / `description()` | Human-facing metadata |
| `supportedContextTypes()` | Context types the skill can produce |
| `supports(AiSkillRequest)` | Whether the skill can handle a request (used for auto-routing) |
| `execute(AiSkillRequest)` | Run, returning an `AiSkillResult` |
| `metadata()` | `AiSkillMetadata` for discovery |
| `health()` | Lightweight readiness (never throws) |

`AbstractAiSkill` provides default `supports()`/`metadata()`/`health()`, the standard guardrail
set, and `collectCitations()` — which surfaces provenance already present in the reused data and
**never fabricates citations**. Concrete skills implement only key/name/description/context
types/execute. **No provider logic, no HTTP.**

---

## DTOs

| DTO | Carries |
| --- | --- |
| `AiSkillRequest` | UUID, timestamp, optional skill key, context type, framework/release, parameters |
| `AiSkillResult` | skill key, context type, payload, citations, guardrails, warnings |
| `AiSkillExecution` | execution UUID, status, duration (ms), started/finished timestamps |
| `AiSkillResponse` | skill key, success, execution trace, result (or error + code) |
| `AiSkillMetadata` | key, display name, description, supported context types, version, tags |

---

## Registry

`AiSkillRegistry` manages the catalog (no business logic):

- **Registration** — config-driven (`config('ai.skills.registered')`) plus manual `register()`.
- **Discovery** — `all()`, `enabled()`, `keys()`, `describe()`.
- **Lookup** — `has()`, `get()`.
- **Enable/disable** — `enable()` / `disable()` (runtime) on top of config flags.
- **Priority** — higher priority is preferred during auto-routing.
- **Feature flags** — global `ai.skills.enabled` + per-skill `enabled` (env-overridable:
  `AI_SKILL_CORPUS_SEARCH_ENABLED`, `AI_SKILL_KNOWLEDGE_GRAPH_ENABLED`,
  `AI_SKILL_FRAMEWORK_MAPPING_ENABLED`).

---

## Router

`AiSkillRouter`:

- `route(request)` — picks the skill: explicit `skill` key if given (must be enabled), else the
  highest-priority enabled skill whose `supports()` returns true. Throws when none matches.
- `execute(request)` — route + run, timing the execution and returning an `AiSkillResponse`.
  Skill failures are captured as `success=false` responses (not thrown).
- `executeMany(requests)` — aggregates multiple responses (order preserved).

**No OpenAI / provider calls anywhere in the router.**

---

## Skills

### CorpusSearchSkill (`corpus_search`)
Reuses `ComplianceAiContextService` (which reuses `ComplianceCorpusSearchService`) to return a
cited AI Context payload. Supports `search_context`, `corpus_summary`, `control_profile`,
`domain_profile`, `requirement_profile`. Requires framework + release.

### KnowledgeGraphSkill (`knowledge_graph`)
Reuses `ComplianceKnowledgeGraphService` to return graph expansion: node, **ancestors**,
**descendants**, **siblings (related controls)**, and cross-references. Entity type (`framework`,
`domain`, `control`, `requirement`) + `code` via parameters.

### FrameworkMappingSkill (`framework_mapping`)
Reuses `ComplianceMappingService` + `ComplianceFrameworkComparisonService` for objective
mappings, related controls, coverage, and comparison. **No fake mappings** — empty where data
does not exist. Operations: `control_objectives`, `objective_mapping`, `control_mapping`,
`framework_coverage`, `framework_comparison`.

---

## Orchestrator update

`CompliancePromptOrchestrator::composeFromSkills(array $skillResponses, string $userPrompt)` now
composes **one** prompt from **multiple** skill responses: it concatenates each successful
result's payload into labeled context blocks, merges/de-duplicates citations, and unions
guardrails. The original single-payload `buildPrompt()` is retained for backward compatibility.
The orchestrator **still performs no database or corpus access** — it only transforms the data
the skills already produced.

---

## Execution flow

```
POST /api/workspaces/{project}/ai/skills/execute
  body: { skill?, context?, framework?, release?, parameters? }
  → auth:sanctum → project.qynshield → throttle:ai-skills → ProjectPolicy::view
  → if ai.skills.enabled is false → 422
  → build AiSkillRequest (UUID + timestamp)
  → audit log (ai_skill_execute — skill + context only, no content)
  → AiSkillRouter.execute:
        route → highest-priority enabled skill that supports the request
        run   → skill.execute reuses compliance services → AiSkillResult
        wrap  → AiSkillExecution (duration) + AiSkillResponse
  → return { success, data: SkillResponse }      // SkillResponse ONLY — no AI provider
```

`GET /api/workspaces/{project}/ai/skills` returns the discovery catalog (metadata + flags).

---

## Future skills

Adding a skill is contained: implement `AiSkillInterface` (or extend `AbstractAiSkill`) and add a
`config('ai.skills.registered')` entry with class, priority, and `enabled`. No router, DTO, API,
or orchestrator change required. Reserved for later sprints: `EvidenceSkill`,
`GapAssessmentSkill`, `RecommendationSkill` (and the Compliance Copilot that composes them).

---

## Security

| Control | Mechanism |
| --- | --- |
| Workspace isolation | Workspace-scoped routes + `ProjectPolicy::view` |
| Module entitlement | `project.qynshield` middleware |
| Audit logging | `AiAccessAuditLogger` (`ai_skill_execute`) — skill + context only, never content |
| Feature flags | Global + per-skill enable/disable |
| Abuse protection | `throttle:ai-skills` (default 60/min, `AI_SKILLS_RATE_LIMIT`) |
| No AI execution | Skills/router/registry never contact a provider |

---

## QA results

- **Registry** — config-driven discovery (`corpus_search`, `knowledge_graph`,
  `framework_mapping`), priority ordering, enable/disable, feature flags.
- **Router** — explicit-key and auto-selection routing; failures captured as failed responses;
  no provider calls.
- **CorpusSearchSkill / KnowledgeGraphSkill / FrameworkMappingSkill** — reuse existing services
  only; return AI Context payloads with provenance-derived citations; no fabricated mappings.
- **Feature flags** — `ai.skills.enabled=false` disables the framework; per-skill flags disable
  individual skills.
- **Provider independence** — no `AiProvider*`, `OpenAi`, or `Http` reference in the skills
  layer; the skills API returns only an `AiSkillResponse`.
- **No AI calls** — verified by static scan of `app/Services/Ai/Skills`.
- `php -l` clean; `route:list` shows the skills routes.

---

## Files changed

**New**
- `backend/app/Contracts/Ai/AiSkillInterface.php`
- `backend/app/Exceptions/Ai/AiSkillException.php`
- `backend/app/DataTransferObjects/Ai/` — `AiSkillRequest`, `AiSkillResult`, `AiSkillExecution`,
  `AiSkillResponse`, `AiSkillMetadata`
- `backend/app/Services/Ai/Skills/` — `AbstractAiSkill`, `AiSkillRegistry`, `AiSkillRouter`,
  `CorpusSearchSkill`, `KnowledgeGraphSkill`, `FrameworkMappingSkill`
- `backend/app/Http/Controllers/Ai/AiSkillController.php`
- `backend/tests/Unit/AiSkillsFrameworkTest.php`
- `docs/QCIF_SPRINT10_AI_SKILLS_FRAMEWORK.md`

**Modified**
- `backend/config/ai.php` (skills section + skills rate limit)
- `backend/app/Services/Ai/CompliancePromptOrchestrator.php` (`composeFromSkills`)
- `backend/routes/ai-orchestration.php` (skills routes)
- `backend/app/Providers/RouteServiceProvider.php` (`ai-skills` limiter)
