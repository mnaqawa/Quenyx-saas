# Release Notes — Quenyx vOPS HUB v1.0.0

> **Quenyx vOPS HUB — Document Metadata**
>
> | Field | Value |
> |---|---|
> | Document Version | 1.0 |
> | Software Version | v1.0.0 |
> | Applies To | Quenyx vOPS HUB v1.0.0 (GA) — Sprint 25 |
> | Classification | Internal |
> | Owner | Platform Engineering |
> | Status | Released |
> | Release Date | 2026-06-30 |
> | Document Type | Release notes |

## Overview

**Quenyx vOPS HUB v1.0.0** is the first General Availability release and the completion of the original
Quenyx roadmap (Phases 1–4, Sprints 1–25). Sprint 25 — the **Enterprise Intelligence Platform** — unifies
every module into a single enterprise ecosystem with a shared event bus, a shared context engine, the
QynVA Enterprise AI Operator, QynBalance Cost Intelligence, Executive Intelligence, Enterprise Analytics,
and Platform Health, and turns on the full module navigation.

## What's new in Sprint 25 (v1.0.0)

### Platform Event Bus
Shared publish/subscribe layer (`PlatformEventBus`) with a 21-event canonical vocabulary
(`PlatformEventNames`), immutable `PlatformEvent` DTO, `EventSubscriber` contract, audited publishes,
async-ready fan-out, and an example `NotificationFanoutSubscriber`. Eliminates direct module-to-module
calls.

### Enterprise Context Engine
A single `EnterpriseContextEngine` that assembles one normalized AI context from workspace, user,
permissions, monitoring, assets, automation, knowledge, incidents, notifications, compliance, timeline,
graph and search — reusing existing read-models (cross-module gather, Global Timeline, Knowledge Graph v2,
Enterprise Search). Recursion-safe; consumed by all AI surfaces.

### QynVA — Enterprise AI Operator
Not a chatbot: discovers adapters/capabilities, builds enterprise context, reasons through
`ModuleAiNarrator`, and proposes **editable, evidence-based** cross-module coordination plans. Never
executes; every turn is recorded and publishes `ConversationCompleted`. New `/api/qynva/*` surface.

### QynBalance — Enterprise Cost Intelligence
Cost analysis over **real** platform data (hosts, services, agents, seats, automation). Capacity/license/
cloud optimization, automation savings, asset utilization, budget forecasting, and advisory
recommendations. **No fabricated financial values** — monetary figures appear only where real rates are
configured; otherwise counts + "pricing unavailable". New `/api/qynbalance/*` surface.

### Executive Intelligence & Enterprise Analytics
Evidence-based executive dashboard (operational/infrastructure/compliance health, capacity forecast, top
risks/recommendations, automation success, AI usage, incident/knowledge/cost KPIs) with an AI executive
summary; and the shared analytics platform (MTTD, MTTR, trends, effectiveness, adoption, KPIs). Honest
`available: false` when data is insufficient.

### Platform Health
Self-monitoring of AI/automation/knowledge platforms, search, registries, provider health, queues, event
bus, and background jobs, with overall + per-area status.

### Navigation
The temporary sidebar feature flag is removed. **QynSight, QynAsset, QynRun, QynReact, QynKnow,
QynSupport, QynNotify, QynShield, QynBalance, QynVA** are all enabled. Platform items (Dashboard,
Workspaces, Quenyx AI, Integrations) retained. **QynCore** remains platform-only.

## Compatibility & upgrade

- **No breaking changes** to Sprint 20–24 behavior. All previous APIs, routes, and data are unchanged.
- New optional config: `config/cost.php` (+ `COST_*` env) for QynBalance pricing. Without it, QynBalance
  is fully functional in count/"pricing unavailable" mode.
- See the **Migration Guide** (doc 40) for the exact upgrade steps.

## Quality gate (met)

No duplicated platform logic · event-driven · shared Context Engine / AI / Automation / Knowledge ·
workspace isolation · UUID-only · RBAC · audit · EN/AR complete · backend syntax clean · documentation
updated · PDFs regenerated.

## Known limitations

- AI narration runs on the deterministic mock provider unless an AI provider is configured (evidence is
  always real; responses are flagged `ai_enabled: false`).
- QynBalance monetary estimates require operator-supplied rates; cloud-spend optimization needs a
  connected cloud billing source.
- Event Bus fan-out is synchronous-but-isolated; the queue-backed path is a drop-in (no publisher/
  subscriber changes) but ships disabled by default.
