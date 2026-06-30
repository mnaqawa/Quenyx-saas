# 16 — AI User Guide

> **Quenyx vOPS HUB — Document Metadata**
>
> | Field | Value |
> |---|---|
> | Document Version | 2.0 |
> | Software Version | v1.0.0 |
> | Applies To | Quenyx vOPS HUB v1.0.0 |
> | Classification | Public / External |
> | Owner | Product |
> | Status | Released |
> | Last Updated | 2026-06-29 |
> | Document Type | User guide |
>
> **Revision History**
>
> | Version | Date | Notes |
> |---|---|---|
> | 1.0 | 2026 | Initial v1 pack (through Sprint 19). |
> | 2.0 | 2026-06-29 | Aligned to v1.0.0; Quenyx AI as a shared platform; Unified AI Workspace. |

**Audience:** All users.
**Principle:** Quenyx AI is **governable**, **deterministic‑first**, **cited**, and **off by
default**.

---

## 1. What Quenyx AI is

A **shared AI platform** behind the HUB that explains compliance and operational data — not a
free‑form chatbot. Business logic (deterministic engines) decides *what* is true; AI only helps
*phrase* it, always with citations.

> **v1.0.0:** the workspace AI control center (Sprint 20's *Unified AI Workspace*) is now branded
> **Quenyx AI** and opens from the top‑level sidebar (beside Integrations). It is distinct from
> **Workspaces** (tenant/project management). Tabs are grouped into Workspace, Intelligence,
> Operations, and Administration. Under **Operations → Providers** you can browse the provider catalog
> (OpenAI, Anthropic, Gemini, Azure OpenAI, OpenRouter, Mistral, Cohere, xAI Grok, Ollama, LM Studio,
> vLLM, LiteLLM, Hugging Face, Custom), configure credentials (write‑only/encrypted), enable/disable,
> and run a real **Test connection**. Providers without a live adapter are clearly marked "catalog
> only"; only **OpenAI** executes today.

## 2. What it can do today 🟢/🟡

- **Compliance Copilot** — answers questions about the loaded framework (NCA ECC‑2:2024) with
  **citations**, backed by deterministic reasoning. (Mock mode by default; real‑model when enabled.)
- **Skills** — corpus search, knowledge graph, framework mapping, evidence, gap assessment,
  recommendations — all reusing deterministic services.
- **Capability catalog** — `GET /api/ai/platform/capabilities` shows exactly what the platform can
  do right now.

## 3. What is feature‑flagged 🟡

- **Real‑model AI** (`AI_ENABLED` / `AI_PROVIDER=openai`) — off by default.
- **RAG** (`RAG_ENABLED`, `AI_COPILOT_RAG_ENABLED`, `EMBEDDINGS_ENABLED`) — off; metadata‑only with
  deterministic fallback when on.
- **Demo mode** (`AI_COPILOT_DEMO_MODE`) — surfaces reasoning trace + citations + sources.

## 4. How citations work

Every compliance answer references the **official source** (control/requirement + source document).
If the platform cannot cite a source, it **does not answer**. This is the anti‑hallucination
guarantee.

## 5. How reasoning works

A deterministic **Reasoning Engine** applies explicit rules to the corpus + evidence and produces a
`ReasoningOutput` (findings, fired rule IDs, explanation). The same inputs always yield the same
result. AI renders this output; it does not invent it.

## 6. What AI cannot do

- It **cannot** answer without a citable source.
- It **cannot** access tenant evidence embeddings (not indexed by default).
- It **cannot** call a model unless an operator enabled it (`AI_ENABLED`).
- It is **not** an autonomous agent and does **not** take actions on your infrastructure.

## 7. Mock mode vs AI mode

| | Mock mode (default) | AI mode (`AI_ENABLED=true`) |
|---|---|---|
| Model calls | none | OpenAI provider |
| Output | deterministic mock phrasing | model‑phrased, still cited + reasoning‑gated |
| Safety | maximal (no external calls) | governed by the same guardrails |

Mock mode is safe for demos and produces real, cited structure without contacting any model.

## 8. Prompt logging policy

**Off by default.** Prompt/response **content is not stored** unless an operator sets
`AI_PROMPT_LOGGING_ENABLED=true`.

## 9. Privacy / security

- Conversation persistence **off by default**.
- Tenant **evidence is never embedded** by default.
- All AI routes are authenticated, entitlement‑gated, and rate‑limited.
- Models/keys come from server env, never hardcoded.

## 10. Safe usage guidance

- Scope questions to a loaded framework (NCA ECC‑2:2024) for best results.
- Treat answers as **explanations of platform data**, verifiable via their citations.
- If an answer lacks a citation, it won't be given — refine the question or check entitlement/scope.

## 11. Demo prompts

- "Summarize domain 1 of NCA ECC‑2:2024."
- "Why is requirement 1‑1‑1 non‑compliant?"
- "What evidence is missing for control 2‑1‑1?"
- "What do you recommend for the open gaps in domain 2?"
- "Show the capability catalog for the AI platform." *(→ `GET /api/ai/platform/capabilities`)*

## 12. Asset Copilot (QynAsset — Sprint 22)

When a workspace has **QynAsset** enabled, the **Asset Intelligence** dashboard adds an **Asset
Copilot** and contextual **✨** actions (Explain / Analyze / Forecast / Impact / Review). Answers are
grounded in the **real discovered inventory** only; the Copilot reuses Quenyx AI conversations (your
threads appear in AI Activity/History). It will tell you plainly when something isn't collected (e.g.
license or warranty data) instead of guessing.

Demo prompts:

- "Which assets are inactive or haven't reported recently?"
- "Are there duplicate assets in this workspace?"
- "Which monitored hosts have no enrolled agent?"
- "Explain the dependencies and blast radius of asset *app‑01*."
- "What asset risks should I act on, and what's the evidence?"

> The Asset Copilot is one of several module copilots discovered dynamically via the **AI Adapter
> Platform** (`GET /api/ai/adapters`) — no module is hard‑coded into Quenyx AI.
