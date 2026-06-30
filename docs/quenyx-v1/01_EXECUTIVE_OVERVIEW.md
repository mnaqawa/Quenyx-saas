# 01 — Executive Overview

> **Quenyx vOPS HUB — Document Metadata**
>
> | Field | Value |
> |---|---|
> | Document Version | 2.1 |
> | Software Version | v1.0.0 |
> | Applies To | Quenyx vOPS HUB v1.0.0 |
> | Classification | Public / External |
> | Owner | Executive Office |
> | Status | Released |
> | Last Updated | 2026-06-30 |
> | Document Type | Executive brief |
>
> **Revision History**
>
> | Version | Date | Notes |
> |---|---|---|
> | 1.0 | 2026 | Initial v1 pack (through Sprint 19). |
> | 2.0 | 2026-06-29 | Aligned to v1.0.0; roadmap Phases 1–4 / Sprints 20–25; native monitoring; shared platform AI. |
> | 2.1 | 2026-06-30 | Sprint 21 (Operations Intelligence) delivered: QynSight becomes a live AI consumer on the shared platform. |

**Audience:** Investors, board, executives, strategic partners.
**Tone:** Non‑technical, factual, investor‑ready.
**Status basis:** v1.0.0 (Phases 1–3 completed; Phase 4 in progress, Sprints 20–21 delivered).

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
  adapter; QynSight is now a live AI consumer via Operations Intelligence (Sprint 21).**
- **QynSight Operations Intelligence (Sprint 21)** — a Monitoring Copilot, alert explanation and
  investigation, deterministic root‑cause analysis, incident timelines, and capacity/performance/
  infrastructure/service‑health intelligence with evidence‑based recommendations. It **reuses** the
  shared AI platform (no duplicated AI) and grounds every answer in **real** monitoring data.

🟡 **Built but feature‑flagged (off by default)**
- **Compliance Copilot v0** runs in **mock mode** by default; real‑model mode is env‑gated.
- **Retrieval / RAG Foundation** — provider‑agnostic, **metadata‑only**, with deterministic
  fallback; off by default and never indexes tenant evidence by default.

🔵 **Architecture‑ready (contract only)**
- Other HUB modules are **registered internally** but **hidden from the sidebar** by a frontend flag,
  with **reserved** AI‑adapter contracts until each is built.

## 6. QynSight status

Production‑ready, with the **core monitoring engine feature‑frozen** at v1.0. With **Operations
Intelligence (Sprint 21)** it now adds an explainable AI layer on top of that frozen engine — the
platform's anchor module and the default‑visible product in the UI.

## 7. QynShield / QCIF status

Backend and APIs are **built and exercised** (corpus, graph, mapping, evidence, gap, recommendation,
copilot, executive). It is the **first production consumer of the Quenyx AI Platform**. UI surfacing
beyond the executive/demo layer is part of the forward roadmap.

## 8. Quenyx AI Platform status

The AI runtime has been **extracted into a shared platform** that any module can consume. QynShield is
live on it, and **QynSight is now live too** via Operations Intelligence (Sprint 21) — reusing the same
providers, prompt orchestration, conversations, and audit with **no duplicated AI**. AI is **off by
default**, **deterministic‑first**, and **citation‑enforced**.

## 9. What exists today vs roadmap

| Area | Today | Roadmap |
|---|---|---|
| Monitoring | QynSight v1.0 (frozen) + Operations Intelligence (Sprint 21) | Deeper integrations |
| Compliance | QCIF + NCA ECC‑2:2024 v1, full backend/API | More frameworks; richer UI |
| AI | Shared platform, mock default, deterministic + citations; live on QynShield **and** QynSight | Real‑model GA, RAG GA, more module intelligence |
| Modules | QynSight visible; QynShield backend; others hidden | Progressive module enablement |

## 10. Defensible architecture (the moat)

- **Data moat:** operational state **plus** an official, provenance‑tracked compliance corpus.
- **Governance moat:** deterministic engines + "no citation, no answer" + UUID/provenance‑first.
- **Platform moat:** a reusable AI layer with provider abstraction and module adapters, so new
  modules inherit safe AI without rebuilding it.

## 11. Investor narrative

Quenyx has completed a **disciplined foundation across Phases 1–3**: a production monitoring product
(native QynSight engines), a provenance‑grounded compliance engine, and a governable shared AI
platform — built with explicit safety rails rather than demo shortcuts. **Phase 4 (Enterprise AI
Platform)** is now underway, with the **Unified AI Workspace (Sprint 20, presented as "Quenyx AI")**
and **Operations Intelligence (Sprint 21)** already delivered in v1.0.0 — the latter turning QynSight from
a monitoring product into an operations *intelligence* product on the shared AI platform — converting
this foundation into broader module coverage and customer‑facing AI from a base that is already
architecturally sound.

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

## 14. Official roadmap

| Phase | Name | Status |
|---|---|---|
| Phase 1 | Platform Foundation | ✅ Completed |
| Phase 2 | Operations Platform (QynSight) | ✅ Completed |
| Phase 3 | Compliance & Enterprise AI Foundation (QCIF Sprints 1–19, AI Platform Foundation) | ✅ Completed |
| Phase 4 | **Enterprise AI Platform** | 🟡 In progress |

**Phase 4 — Enterprise AI Platform**

| Sprint | Title | Status |
|---|---|---|
| Sprint 20 | Unified AI Workspace ("Quenyx AI") | ✅ Delivered in v1.0.0 |
| Sprint 21 | Operations Intelligence | ✅ Delivered in v1.0.0 |
| Sprint 22 | Asset Intelligence + AI Adapter Platform | ✅ Delivered in v1.0.0 |
| Sprint 23 | Automation & Response Intelligence | Planned |
| Sprint 24 | Service, Notification & Cost Intelligence | Planned |
| Sprint 25 | Enterprise Intelligence Platform | Planned |

There is no roadmap content beyond Sprint 25 at this time. Hidden business modules (QynRun,
QynKnow, QynNotify, QynReact, QynVA, QynSupport, QynBalance) already exist as registered platform
modules and are **disabled in the navigation by a sidebar flag** until their production rollout.

**Sprint 22 — Asset Intelligence + AI Adapter Platform.** Quenyx AI became a true **platform**: module
AI is now a discoverable **adapter** (registry + shared narrator) with **no per‑module branching**, so
every future module plugs in the same way. **QynAsset** is the second production AI consumer (after
QynSight), turning the **real discovered inventory** into explainable Asset Intelligence — discovery,
CMDB questions, dependencies, hardware/capacity, lifecycle, and evidence‑based recommendations. Facts
with no data source (software licenses, warranty/EOL dates) are reported honestly as *not collected*,
never fabricated. See Docs 22 (QynAsset Guide) and 23 (AI Adapter Developer Guide).
