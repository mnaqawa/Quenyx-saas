# QynBalance Guide — Enterprise Cost Intelligence

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
> | Document Type | Module / platform guide |

## What QynBalance is

**QynBalance** is **Enterprise Cost Intelligence** built on **real platform data**: monitored hosts and
services, enrolled agents, workspace seats, and automation activity. It analyzes infrastructure cost,
capacity/license/cloud optimization, automation savings, asset utilization, and budget forecasting, and
produces **advisory** optimization recommendations.

**It never fabricates financial values.** Monetary estimates appear *only* where the operator has
configured real unit rates. When a rate is missing, QynBalance reports the real resource **count** and
clearly states **"pricing unavailable"** rather than inventing a currency figure.

## Backend

| Piece | Path |
|---|---|
| Cost service | `app/Services/Platform/Cost/CostIntelligenceService.php` |
| Cost copilot | `app/Services/Platform/Cost/CostIntelligenceCopilot.php` |
| Adapter | `app/Services/QuenyxAI/Adapters/QynBalanceAiAdapter.php` |
| Controller | `app/Http/Controllers/Platform/CostController.php` |
| Routes | `routes/qynbalance-cost.php` (prefix `/api/qynbalance`) |
| Config | `config/cost.php` |

## Pricing configuration (honest by default)

`config/cost.php` rates are **NULL by default**. Provide real rates (from your own contracts / cloud
bills) via env to unlock monetary estimates:

```
COST_CURRENCY=USD
COST_HOST_PER_MONTH=...        COST_AGENT_PER_MONTH=...      COST_SERVICE_PER_MONTH=...
COST_LICENSE_PER_SEAT=...      COST_AUTOMATION_RUN_MINUTE=...
COST_MONTHLY_BUDGET=...        COST_IDLE_AGENT_HOURS=72
```

Until set, the overview shows counts with `pricing_available: false` and a `configure_pricing`
recommendation.

## API

Base `/api/qynbalance`. Workspace-scoped, UUID-only. Overview requires `accessAi`; the copilot requires
`can_use_ai`.

| Method | Endpoint | Notes |
|---|---|---|
| GET | `/cost/overview` | Infrastructure cost, license/asset/automation/capacity/cloud sections, budget forecast, recommendations. |
| POST | `/cost/copilot` | FinOps copilot narrating the deterministic cost evidence (mock-safe). |

## What it computes (all from real rows)

- **Infrastructure cost** — host/service/agent counts × configured rates (or "pricing unavailable").
- **License optimization** — workspace seats × seat rate (if configured).
- **Asset utilization** — hosts/services/agents, idle agents (real `last_seen_at`), services-per-host.
- **Automation savings** — successful executions and automated runtime minutes; monetized only if a
  per-minute rate is configured.
- **Capacity optimization** — pointer to QynSight Capacity Planning, gated on real metric samples.
- **Cloud optimization** — explicitly unavailable until a cloud billing source is connected (no invented
  cloud spend).
- **Budget forecast** — monthly/annual projection vs. optional configured budget (only when priced).
- **Recommendations** — idle agents, hosts without services, and "configure pricing" — each with the real
  evidence behind it. Advisory only; never auto-applied.

## UI

`/app/workspaces/:id/qynbalance/cost` — cost table (counts + monetary where priced), a clear
"pricing unavailable" banner when rates are unset, recommendations, and a Cost Intelligence copilot.
