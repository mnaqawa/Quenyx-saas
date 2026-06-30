# Executive Intelligence & Enterprise Analytics Guide

> **Quenyx vOPS HUB — Document Metadata**
>
> | Field | Value |
> |---|---|
> | Document Version | 1.0 |
> | Software Version | v1.0.0 |
> | Applies To | Quenyx vOPS HUB v1.0.0 — Sprint 25 |
> | Classification | Internal |
> | Owner | Platform Engineering |
> | Status | Released |
> | Document Type | Platform guide |

## What it is

Sprint 25 adds two evidence-based platform surfaces consumed through QynVA:

- **Executive Intelligence** (`app/Services/Platform/Executive/ExecutiveIntelligenceService.php`) —
  board-ready health, KPIs, top risks/recommendations, and an AI-narrated executive summary.
- **Enterprise Analytics** (`app/Services/Platform/Analytics/EnterpriseAnalyticsService.php`) — the shared
  metrics platform (MTTD/MTTR, trends, effectiveness, adoption, KPIs).

Both are pure **read-models** over real rows and are **honest**: when there is not enough data, a metric
returns `available: false` with a reason instead of inventing a number. Health scores are **deterministic
functions of real counts**, documented inline, so identical data always yields the identical score.

## Executive Intelligence

`GET /api/qynva/executive` returns:

| Block | Basis |
|---|---|
| Operational Health | 100 − weighted penalties for open critical/high incidents and open alerts |
| Infrastructure Health | share of monitored services currently in an `ok` state |
| Compliance Health | compliance assessment presence (QynShield), honest when absent |
| Capacity Forecast | metric-sample availability (pointer to QynSight Capacity Planning) |
| Top Risks | real open critical/high incidents + open critical alerts |
| Top Recommendations | QynBalance evidence-based optimization recommendations |
| Automation Success / AI Usage | from Enterprise Analytics |
| Incident / Knowledge / Cost KPIs | MTTR/MTTD/trends, knowledge usage, cost rollup |

`POST /api/qynva/executive/summary` narrates the **deterministic dashboard evidence** through
`ModuleAiNarrator` (mock-safe; flagged `ai_enabled: false` when AI is disabled). The summary never
introduces facts that are not in the dashboard.

Health bands: `healthy` (≥85), `degraded` (≥60), `at_risk` (<60).

## Enterprise Analytics

`GET /api/qynva/analytics?days=30` returns:

- **MTTD** — alert `triggered_at` → `acknowledged_at` (detection proxy).
- **MTTR** — incident `opened_at` → `resolved_at`, plus an alert MTTR companion.
- **Incident trends** — opened/resolved and by-severity over the window.
- **Automation effectiveness** — execution status mix, success rate, rollbacks.
- **AI adoption** — conversations, tokens, by provider.
- **Knowledge usage** — documents by status.
- **Asset growth** — hosts, agents, services.
- **Capacity trends** — metric-sample availability.
- **Notification statistics** — by severity/status/channel.
- **Executive KPIs** — open incidents/tickets/alerts, active notifications, knowledge documents.

Each block reports availability honestly; durations are returned in seconds plus a human form (e.g. `2.3h`).

## RBAC & isolation

Workspace-scoped, UUID-only. Reads require `accessAi`; the executive AI summary requires `can_use_ai`.
All data is workspace-isolated.

## UI

Reached via the QynVA hub: **Executive Intelligence** (`/qynva/executive`) and **Enterprise Analytics**
(`/qynva/analytics`). EN/AR complete.
