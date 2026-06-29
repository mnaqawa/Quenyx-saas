# 21 — Engineering Principles & Standards

> **Quenyx vOPS HUB — Document Metadata**
>
> | Field | Value |
> |---|---|
> | Document Version | 2.0 |
> | Software Version | v1.0.0 RC1 |
> | Applies To | Quenyx vOPS HUB v1.0.0 RC1 |
> | Classification | Internal |
> | Owner | Engineering |
> | Status | Released |
> | Last Updated | 2026-06-29 |
> | Document Type | Engineering standards |
>
> **Revision History**
>
> | Version | Date | Notes |
> |---|---|---|
> | 1.0 | 2026 | Initial v1 pack (through Sprint 19). |
> | 2.0 | 2026-06-29 | Aligned to v1.0.0 RC1 (Documentation Pack v2.0). |

**Audience:** The engineering org.
**Purpose:** The non‑negotiables that define how Quenyx is built. These are observed in the current
codebase and are the bar for all future work.

---

## 1. Platform principles

- **One source of truth.** Operational + compliance data live in the platform; modules share
  identity, entitlements, audit, and the AI layer.
- **Thin edges, rich services.** Controllers validate and delegate; logic lives in `app/Services/**`.
- **Composable modules.** New modules plug in without re‑plumbing identity/billing/AI.

## 2. No fake data

No `lorem`, `sample/demo/fake` controls, or placeholder business data in production paths. The corpus
validator **rejects** fake‑data markers. Executive metrics are **real counts**, never fabricated.

## 3. Source‑of‑truth database

The database is authoritative for business data. The AI core does **not** read business data directly
— it consumes deterministic service outputs. Compliance content is imported from **official sources
only**.

## 4. UUID‑first

Domain entities (corpus, AI, RAG) use **UUIDs**. Public payloads expose UUIDs/codes — **never** raw
auto‑increment IDs.

## 5. Provenance‑first

Every compliance element traces to a **source document** and an **import run**. Revisions are
immutable snapshots. Nothing enters the corpus without provenance.

## 6. Fail‑closed

Missing auth, missing entitlement, invalid input, or an uncitable AI answer → **reject**, don't
degrade silently. Security and correctness defaults are restrictive.

## 7. Deterministic before probabilistic

A deterministic engine decides **what** is true and **what** to answer **before** any model is
involved. Same inputs ⇒ same outputs. This is the core governance guarantee.

## 8. AI as renderer, platform as expert

The model **phrases**; it does not **decide**. Reasoning, gaps, recommendations, and citations come
from deterministic engines. AI is off by default and provider‑agnostic.

## 9. Module adapter pattern

Modules consume AI through `AiModuleAdapterInterface` (`buildContext` → `buildReasoning` →
`buildPrompt`). Adapters **reuse** module services; they **never duplicate or move** business logic
into the AI core.

## 10. Service boundaries

- Provider access only through `AiProviderRegistry` / provider classes. **No hardcoded models.**
- No direct OpenAI/HTTP calls outside provider classes (verified by static scan).
- DB access stays in the services/persistence layer, not the AI orchestration core.

## 11. Testing standards

- New services get deterministic unit tests; new routes get authorization/entitlement tests.
- Run `php artisan test` (+ `--filter=Compliance`, `--filter=Ai`) before merge.
- Prefer deterministic fixtures over randomized data.

## 12. Documentation standards

- Docs live in `docs/quenyx-v1/`; update them in the **same PR** as the code.
- Use the status legend (🟢/🟡/🔵/⚪). **No fabrication.**
- Re‑derive mechanical refs (Doc 08 routes, Doc 09 migrations) when they change.

## 13. Release standards

- New work is **feature‑flagged off by default** when it touches AI or external calls.
- **Never edit applied migrations** — fix forward.
- Run the **Track‑B audit** at phase boundaries; close high‑risk findings before advancing.
- Keep `route:list` collision‑free; keep `APP_DEBUG=false` in production.

## 14. Security‑by‑design

- Sanctum auth + project policy + module/QynShield entitlement on every tenant route.
- Audit sensitive actions.
- Prompt logging, conversation persistence, RAG, and tenant‑evidence embeddings **off by default**.
- Secrets in env/secrets manager; `.env` never committed.

---

> **The standard, in one line:** *Deterministic, provenance‑backed, fail‑closed, UUID‑first,
> AI‑as‑renderer — with no fake data and no fabricated claims, ever.*
