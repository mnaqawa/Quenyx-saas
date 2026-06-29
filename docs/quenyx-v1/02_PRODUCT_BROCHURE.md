# 02 — Product Brochure

> **Quenyx vOPS HUB — Document Metadata**
>
> | Field | Value |
> |---|---|
> | Document Version | 2.1 |
> | Software Version | v1.0.0 RC1 |
> | Applies To | Quenyx vOPS HUB v1.0.0 RC1 |
> | Classification | Public / External |
> | Owner | Product Marketing |
> | Status | Released |
> | Last Updated | 2026-06-30 |
> | Document Type | Product brochure |
>
> **Revision History**
>
> | Version | Date | Notes |
> |---|---|---|
> | 1.0 | 2026 | Initial v1 pack (through Sprint 19). |
> | 2.0 | 2026-06-29 | Aligned to v1.0.0 RC1; native monitoring; QynCore vs Integrations clarified; shared platform AI. |
> | 2.1 | 2026-06-30 | Added QynSight Operations Intelligence (Sprint 21) — explainable, evidence‑grounded operations AI. |

**Audience:** Customers, sales, pre‑sales.
**Tone:** Sales‑friendly, but factual.

> Everything below reflects delivered functionality. Items marked 🟡/🔵/⚪ are flag‑gated,
> architecture‑ready, or roadmap and are labelled as such. No fabricated claims.

---

## Quenyx vOPS HUB — your Virtual Operations Center

**One platform for operations, compliance, and governable AI.** Quenyx unifies infrastructure
monitoring, regulatory compliance, and explainable AI on a single multi‑tenant workspace model — so
your teams stop stitching together disconnected tools.

## Platform overview

- **Multi‑tenant workspaces** ("projects") with role‑based access and per‑module entitlements.
- **Shared services** across modules: identity, audit logging, billing/subscription, and a common
  AI platform.
- **Security‑by‑default:** AI off until enabled, every compliance answer cited, full audit trail.

## Modules

| Module | What it does | Status |
|---|---|---|
| **QynSight** | Operations monitoring & observability **+ Operations Intelligence** | 🟢 Production‑ready (core frozen; intelligence layer live) |
| **QynShield** | Compliance intelligence (QCIF) | 🟢 Backend/API built |
| **Quenyx AI** | Shared, platform‑wide governable AI layer | 🟢 Foundation built |
| QynAsset, QynRun, QynKnow, QynNotify, QynReact, QynVA, QynSupport, QynBalance | Platform modules | 🔵 Registered internally, **currently disabled in the navigation** until production rollout |

> **QynCore** is the platform core (not a business module): it provides the internal services
> through which all modules communicate — Platform Event Bus, Shared Services, Module Registry,
> Service Registry, AI Context Broker, Permission Broker, Audit Pipeline, Notification Broker,
> Workspace Context, and Domain Events. **Integrations** is a platform page for **external**
> systems only (cloud, identity, ITSM, security tooling, messaging); it is not a module and does not
> carry internal module‑to‑module traffic.

## QynSight overview 🟢

Real‑time **monitoring**, **infrastructure map**, **performance analytics**, **capacity planning**,
**alert management**, **service checks**, and **host/agent enrollment**. Agents report metrics,
inventory, and heartbeats; alert rules and monitoring profiles are configurable per workspace.
QynSight's core monitoring engine is **production‑ready and feature‑frozen** at v1.0.

### Operations Intelligence (Sprint 21) 🟢

QynSight now **understands and explains** your operations, not just charts them:

- **Monitoring Copilot** — ask "Which hosts are unhealthy?", "Summarize today's alerts", "What
  changed in the last 24 hours?", "Which hosts will run out of storage first?" — answered from your
  **current** hosts, services, alerts, capacity, metrics, and topology.
- **Alert Intelligence** — ✨ Explain / ✨ Investigate on every alert: operational impact, most likely
  causes, the evidence used, related alerts, and suggested actions.
- **Deterministic Root‑Cause Analysis** and **auto‑generated Incident Timelines** from real events.
- **Capacity, Performance, Infrastructure, and Service‑Health intelligence** with **evidence‑based
  recommendations** — every recommendation references real metrics, alerts, capacity, or dependencies.
- **Contextual ✨ Quenyx AI actions** on hosts, services, alerts, capacity, and the infrastructure map,
  plus a dedicated **Operations Intelligence dashboard**.

It **reuses the same governable Quenyx AI platform** — explainable, deterministic‑first, and **never
fabricated**: if the evidence is insufficient, it says so. Available in English and Arabic.

## QynShield overview 🟢 (backend/API)

Compliance built on the **QCIF** engine:
- **Official corpus** — NCA **ECC‑2:2024 Revision v1** (5 domains, 108 controls, 108 requirements).
- **Knowledge Graph** and **Cross‑Framework Mapping**.
- **Evidence Intelligence**, **Gap Assessment**, **Recommendation Engine**.
- **Compliance Copilot** and an **Executive dashboard / scorecard / timeline / explainability**.
Every answer is **cited to an official source** — no source, no answer.

## Quenyx AI overview 🟢 / 🟡

A **shared AI platform**, not a bolt‑on chatbot:
- **Provider abstraction** (mock + OpenAI) — no model is hardcoded.
- **Skills** that reuse the compliance engines (corpus, graph, mapping, evidence, gap,
  recommendation).
- **Deterministic reasoning first**, AI used only to render explanations.
- **Mock mode by default**; real‑model and **RAG** are feature‑flagged.

## Deployment model

- **Self‑hosted on your infrastructure** (Ubuntu + Nginx). Laravel backend, React frontend, and a
  Node gateway. Standard relational database, queues, scheduler, and cache.
- Configuration is environment‑driven; AI/RAG are opt‑in.

## Key benefits

- **Consolidation** — operations + compliance + AI in one place.
- **Auditability** — provenance and citations on every compliance answer.
- **Safe AI adoption** — explainable, deterministic‑first, off by default.
- **Extensible** — modular architecture; new modules inherit identity, billing, and AI.

## Screenshots

*(Placeholders — insert current UI captures before distribution.)*

- `[screenshot: QynSight real‑time dashboard]`
- `[screenshot: QynSight infrastructure map]`
- `[screenshot: QynShield executive dashboard]`
- `[screenshot: Compliance Copilot with citations]`

## Demo workflow

1. Sign in and select a **workspace**.
2. **QynSight:** view real‑time metrics, infra map, alerts, capacity advisor.
3. **QynShield:** open the executive dashboard → scorecard → timeline → ask the Copilot a question
   and see **cited** reasoning (mock mode is safe for demos).
4. **Quenyx AI:** call the capability catalog to show the governable AI platform behind it.

## Call to action

**Book a guided demo** of QynSight monitoring and the QynShield compliance engine on your own
workspace. Start with monitoring today; layer in compliance and AI as you grow.

## Disclaimers about roadmap modules

Modules other than QynSight (and the QynShield surfaces noted above) are **on the roadmap** and are
**intentionally hidden in the UI** today. Real‑model AI and RAG are **feature‑flagged** and disabled
by default. Only **NCA ECC‑2:2024** is currently loaded. No performance benchmarks, certifications,
or customer claims are made in this brochure.
