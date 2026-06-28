# 02 — Product Brochure

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
| **QynSight** | Operations monitoring & observability | 🟢 Production‑ready (frozen) |
| **QynShield** | Compliance intelligence (QCIF) | 🟢 Backend/API built |
| **Quenyx AI** | Shared, governable AI layer | 🟢 Foundation built |
| QynRun, QynAsset, QynKnow, QynNotify, QynReact, QynVA, QynSupport, QynBalance, QynCore, QynIntegrations | Future operational modules | 🔵 Registered internally / ⚪ roadmap (hidden in UI) |

## QynSight overview 🟢

Real‑time **monitoring**, **infrastructure map**, **performance analytics**, **capacity planning**,
**alert management**, **service checks**, and **host/agent enrollment**. Agents report metrics,
inventory, and heartbeats; alert rules and monitoring profiles are configurable per workspace.
QynSight is **production‑ready and feature‑frozen** at v1.0.

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
