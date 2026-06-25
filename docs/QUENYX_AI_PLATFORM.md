# Quenyx AI Platform

> Sprint 19 — Quenyx AI Platform Foundation · Nature: **architecture extraction only**

This sprint extracts the AI runtime that QynShield built into a **shared Quenyx AI Platform** that can
power every Quenyx module. It moves **no business logic**, duplicates **no service**, and changes
**no existing QynShield API**. It establishes the seam (a platform + module adapters) so the same
provider/skill/retrieval/reasoning runtime is reused everywhere.

---

## 1. Why AI is platform-level

AI orchestration is **horizontal**, not module-specific:

- Picking and calling a model (provider registry, provider interfaces).
- Running discrete skills (skill registry + routing).
- Turning structured context into a prompt (prompt orchestration).
- Deterministic reasoning and retrieval/RAG contracts.
- Auditing AI access.

The **intelligence** (what a recommendation means, how a gap is scored, what an incident is) is
**vertical** and stays inside each module. Extracting the horizontal runtime once means every future
module (QynSight, …) gets a production-grade AI runtime for free, and providers/skills improve for
all modules at once. This is the difference between "a compliance product with AI" and "an AI
platform with a compliance module" — the latter is what investors are buying.

---

## 2. Platform architecture

```
            ┌──────────────────────────────────────────────┐
            │              QuenyxAiPlatform                 │  (singleton registry/facade)
            │  registerAdapter · adapter · resolveProvider  │
            │  resolveSkills · resolveRetrieval · reasoning │
            │  capabilities()                               │
            └───────────────┬───────────────┬──────────────┘
                            │               │
              owns generic runtime          registers module adapters
                            │               │
   ┌────────────────────────┼──────────┐    ├── QynShieldAiAdapter   (implemented — first adapter)
   ▼            ▼            ▼          ▼    └── QynSightAiAdapter…    (contract only — future)
ProviderReg  SkillReg/   Retrieval/   Reasoning
(+ provider  Router      VectorProv.  Engine
 interfaces)             registry
```

Code map:

| Concern | Class |
|---|---|
| Platform registry/facade | `App\Services\QuenyxAI\QuenyxAiPlatform` |
| Capability catalog (dynamic) | `App\Services\QuenyxAI\QuenyxAiCapabilityCatalog` |
| Module adapter contract | `App\Contracts\QuenyxAI\AiModuleAdapterInterface` |
| Generic request DTO | `App\DataTransferObjects\QuenyxAI\AiModuleRequest` |
| QynShield adapter | `App\Services\QuenyxAI\Adapters\QynShieldAiAdapter` |
| QynSight prep (contract only) | `App\Contracts\QuenyxAI\QynSight\QynSightAiAdapterInterface` |
| API | `App\Http\Controllers\QuenyxAI\QuenyxAiPlatformController` · `routes/quenyx-ai.php` |

The platform **owns** these existing generic services by being the single resolution point for them:
`AiProviderRegistry`, `AiSkillRegistry` + `AiSkillRouter`, `CompliancePromptOrchestrator`,
`ComplianceRetrievalService` + `VectorRetrievalProviderRegistry`, `ComplianceReasoningEngine`, and the
audit loggers. They were **not relocated** (that would break QynShield); the platform references them
so providers/skills/retrieval remain swappable and shared.

---

## 3. Module adapters

Every module implements `AiModuleAdapterInterface`:

```
moduleKey()           // stable id, e.g. "qynshield"
supportedSkills()     // skill keys the module exposes
supportedContexts()   // context types (skill context types + retrieval modes)
buildContext(request)                 // Stage 1 — deterministic context (no provider call)
buildReasoning(request, context)      // Stage 2 — deterministic reasoning (no provider call)
buildPrompt(request, reasoning)       // Stage 3 — provider-ready prompt (platform calls the model)
```

The adapter is the **only** seam between the shared platform and a module's domain services. It
**reuses** services; it never re-implements them.

---

## 4. Shared runtime (the three stages)

`buildContext → buildReasoning → buildPrompt` is the generic AI pipeline. The provider call itself is
owned by the platform's Provider Registry, never by an adapter — so swapping OpenAI → Azure/Claude/
Gemini/Local is a platform-level change that every module inherits.

`ReasoningOutput` and `AiPrompt` are the platform's shared, **provider-agnostic, pure-data**
reasoning/prompt contracts (no natural-language answer, no provider response).

---

## 5. QynShield integration (first adapter)

`QynShieldAiAdapter` wraps QynShield's existing services with **zero duplicated logic**:

| Stage | Delegates to |
|---|---|
| `buildContext` | `ComplianceCopilotPlanner` (intent/code) + `ComplianceRetrievalService::queryDetailed()` (scope + skills + retrieval) + `CompliancePromptOrchestrator::composeFromSkills()` (citations/guardrails) |
| `buildReasoning` | `ComplianceReasoningEngine::reason()` |
| `buildPrompt` | `CompliancePromptOrchestrator::composeFromReasoning()` |
| `supportedSkills` | `AiSkillRegistry::keys()` |

Through those services QynShield's full intelligence — Gap, Recommendation, Evidence, Knowledge
Graph, Mappings, Copilot, Reasoning, Retrieval, RAG — remains reachable, unchanged, and behind the
same QynShield APIs (`/compliance/*`). The existing Copilot endpoint is untouched.

---

## 6. Capability catalog

`GET /api/ai/platform/capabilities` (auth required, read-only, platform-level — not tenant data)
returns a **fully dynamic** catalog:

```json
{
  "platform": "quenyx-ai",
  "modules":  [{ "key": "qynshield", "supported_skills": [...], "supported_contexts": [...] }],
  "skills":   [ ...AiSkillRegistry::describe()... ],
  "providers":{ "default": "mock", "available": [...], "implemented": [...] },
  "reasoning":{ "rules": [...7 rule catalog...], "decision_types": [...] },
  "retrieval":{ "modes": ["corpus_only", "...", "copilot_context"] },
  "rag":      { "enabled": false, "vector_provider": null, "provider_resolved": false },
  "supported_contexts": [ ... ]
}
```

Nothing is hard-coded: registering a module/skill/provider changes this output automatically.

---

## 7. HUB-wide module awareness (UI-independent)

Quenyx AI is a **shared platform across the entire Quenyx vOPS HUB**, not a QynShield feature. The
platform is therefore aware of **every** module, independent of frontend sidebar visibility.

- Backend catalog: `config/quenyx_ai.php` (`modules`) + `App\Services\QuenyxAI\QuenyxModuleCatalog`.
- Exposed via `QuenyxAiPlatform::moduleCatalog()` and in the capability catalog as `module_catalog`.
- Each module reports a live AI readiness:
  - **production** — an adapter is registered (currently: QynShield).
  - **reserved** — an adapter contract exists, not implemented (currently: QynSight).
  - **planned** — on the roadmap, no contract yet (QynAsset, QynRun, QynReact, QynNotify, QynKnow,
    QynVA, QynSupport, QynBalance, QynCore, QynIntegrations).

Known modules (UI-independent): QynShield, QynSight, QynAsset, QynRun, QynReact, QynNotify, QynKnow,
QynVA, QynSupport, QynBalance (+ QynCore, QynIntegrations).

### UI visibility is separate

Frontend `platformRegistry.ts` keeps **all** module definitions registered internally. Sidebar
visibility is controlled **separately** by frontend flags (`HIDE_NON_QYNSIGHT_MODULES` /
`isModuleTemporarilyVisible`) — QynSight stays visible; other modules stay registered but hidden.
**No module definitions, billing, or subscription data were removed.** A module hidden from the
sidebar is still fully known to the AI platform: UI visibility and AI module-awareness are
independent concerns.

### Future modules (QynSight and beyond)

QynSight has a reserved contract (`QynSightAiAdapterInterface`) but **no implementation** — this
sprint contains **no monitoring AI, no RCA, no incident AI, no log analysis, and no metrics AI**,
even though QynSight already has monitoring, capacity planning, alert management, service checks, an
infrastructure map, and performance analytics that make it the natural next first-class AI consumer.
When QynSight AI is built, its adapter implements the interface and calls:

```php
$platform->registerAdapter($qynSightAdapter);
```

…with **no change** to the platform — its `module_catalog` status flips from `reserved` to
`production` automatically. Every module inherits providers, skill routing, prompt orchestration,
retrieval/RAG, reasoning, and the capability catalog the same way.

---

## 8. How investors should understand it

- Quenyx is an **AI platform**, not a single AI feature. QynShield is its first, production-proven
  module on a runtime designed to power many.
- The platform is **provider-agnostic** (no lock-in) and **governed** (deterministic reasoning,
  citation enforcement, audit, feature flags) — enterprise and regulator friendly.
- New modules ship AI **faster and safer** because the hard runtime is already built and shared.
- The capability catalog is a live, machine-readable proof of platform breadth.

---

## 9. Guarantees

- No business logic moved; no service duplicated.
- Existing QynShield APIs unchanged (only an additive route + an additive DI binding).
- Platform resolves adapters, providers, and skills.
- Capability catalog is dynamic and deterministic.
- No new business AI and no QynSight intelligence introduced.
