# 01 — Executive Overview

**Audience:** Investors, board, executives, strategic partners.
**Tone:** Non‑technical, factual, investor‑ready.
**Status basis:** Phase I, through Sprint 19.

> This document describes **what exists today** in the Quenyx codebase and what is **planned**. It
> contains **no fabricated customers, revenue, market sizing, benchmarks, or certifications**.

---

## 1. Vision

Quenyx is building the **vOPS HUB** — a *Virtual Operations Center* that unifies, in one platform,
the three functions that enterprises today run in disconnected silos:

1. **Operations & observability** — knowing the real state of infrastructure and services.
2. **Compliance & governance** — proving alignment to regulatory frameworks with evidence.
3. **Applied AI** — turning that operational and compliance data into explainable guidance.

The thesis: operations, compliance, and AI are converging. The winning platform is the one that
treats them as **one source of truth** with a **shared, governable AI layer** — rather than bolting
a chatbot onto fragmented tools.

## 2. vOPS HUB positioning

Quenyx vOPS HUB is a **modular** platform. Each module ("Qyn…") addresses one operational domain,
but all modules share the same workspace model, identity, entitlements, audit, and AI platform.

- Today the HUB ships **QynSight** (operations/monitoring) and **QynShield** (compliance / QCIF).
- The platform is architected so additional modules plug in without re‑plumbing identity, billing,
  or AI.

## 3. Problem statement

Enterprises — particularly in the Saudi/GCC market Quenyx targets — face three compounding problems:

- **Operational blind spots.** Monitoring is fragmented across tools; teams lack one trustworthy
  picture of infrastructure health and capacity.
- **Compliance is manual and unprovable.** Mapping controls (e.g., NCA ECC), collecting evidence,
  finding gaps, and producing auditable recommendations is spreadsheet‑driven and error‑prone.
- **AI is risky to adopt.** Generic LLM tools hallucinate, cannot cite sources, and are unsafe for
  regulated environments where every claim must be traceable.

## 4. Why unified operations + compliance + AI matters

A single platform that already holds operational state **and** an official compliance corpus can do
something point tools cannot: **answer governance questions with cited, deterministic evidence** and
**ground AI in real platform data** instead of open‑ended generation. The data moat (operational +
corpus) and the governance model (provenance + citations + determinism) reinforce each other.

## 5. Current platform status (what exists today)

🟢 **Built and production‑ready**
- **QynSight v1.0** — real‑time monitoring, infrastructure map, performance analytics, capacity
  planning, alert management, service checks, host/agent enrollment. **Feature‑frozen.**
- **QCIF Compliance Engine (QynShield backend/API):** official‑source corpus model, **NCA
  ECC‑2:2024 Corpus Revision v1**, Knowledge Graph, Cross‑Framework Mapping, Evidence Intelligence,
  Gap Assessment, Recommendation Engine — all UUID‑based and provenance‑tracked.
- **Deterministic Reasoning Engine** — rule‑based reasoning that decides *what* to answer before any
  AI is involved.
- **Executive Demonstration Platform** — read‑only dashboard, scorecard, timeline, explainability,
  and platform‑metrics endpoints driven by **real engine data** (no fabricated numbers).
- **Quenyx AI Platform Foundation** — provider registry (mock + OpenAI), skill registry/router,
  module‑adapter contract, and a dynamic capability catalog. **QynShield is the first live AI
  adapter.**

🟡 **Built but feature‑flagged (off by default)**
- **Compliance Copilot v0** runs in **mock mode** by default; real‑model mode is env‑gated.
- **Retrieval / RAG Foundation** — provider‑agnostic, **metadata‑only**, with deterministic
  fallback; off by default and never indexes tenant evidence by default.

🔵 **Architecture‑ready (contract only)**
- **QynSight AI adapter** — interface reserved; **no monitoring AI implemented**.
- Other HUB modules are **registered internally** but **hidden from the sidebar** by a frontend flag.

## 6. QynSight status

Production‑ready and **feature‑frozen** at v1.0. It is the platform's anchor module and the
default‑visible product in the UI.

## 7. QynShield / QCIF status

Backend and APIs are **built and exercised** (corpus, graph, mapping, evidence, gap, recommendation,
copilot, executive). It is the **first production consumer of the Quenyx AI Platform**. UI surfacing
beyond the executive/demo layer is part of the forward roadmap.

## 8. Quenyx AI Platform status

The AI runtime has been **extracted into a shared platform** that any module can consume through a
thin adapter. QynShield is live on it; QynSight has a **reserved** adapter contract. AI is **off by
default**, **deterministic‑first**, and **citation‑enforced**.

## 9. What exists today vs roadmap

| Area | Today | Roadmap |
|---|---|---|
| Monitoring | QynSight v1.0 (frozen) | Deeper integrations |
| Compliance | QCIF + NCA ECC‑2:2024 v1, full backend/API | More frameworks; richer UI |
| AI | Shared platform, mock default, deterministic + citations | Real‑model GA, RAG GA, QynSight AI |
| Modules | QynSight visible; QynShield backend; others hidden | Progressive module enablement |

## 10. Defensible architecture (the moat)

- **Data moat:** operational state **plus** an official, provenance‑tracked compliance corpus.
- **Governance moat:** deterministic engines + "no citation, no answer" + UUID/provenance‑first.
- **Platform moat:** a reusable AI layer with provider abstraction and module adapters, so new
  modules inherit safe AI without rebuilding it.

## 11. Investor narrative

Quenyx has completed a **disciplined Phase‑I foundation**: a production monitoring product, a
provenance‑grounded compliance engine, and a governable shared AI platform — built with explicit
safety rails rather than demo shortcuts. The next phase converts this foundation into broader
module coverage and customer‑facing AI, from a base that is already architecturally sound.

## 12. Customer value

- One platform for operations + compliance instead of a tool sprawl.
- **Auditable** compliance: every answer traces to an official source.
- **Safe AI**: explainable, cited, deterministic‑first, off unless deliberately enabled.

## 13. Risks and limitations (stated plainly)

- QynShield's customer‑facing **UI** is earlier‑stage than its backend; today's surface is the
  executive/demo layer + APIs.
- **Real‑model AI and RAG are feature‑flagged**, not yet GA.
- Only **one framework (NCA ECC‑2:2024)** is loaded today.
- **No production metrics, customers, or certifications are claimed** in this pack.
- Several modules exist as registry entries only and are **hidden in the UI**.

## 14. Next roadmap phase (Sprint 20+)

- Close the Track‑B verification gaps (DB/corpus/tests/frontend builds on CI/CloudQuenyx).
- Progress QynShield customer UI.
- Plan first‑class **QynSight AI** via the reserved adapter (post‑foundation).
- Expand the compliance corpus to additional frameworks.
