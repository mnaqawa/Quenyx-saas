# 14 — QynSight Guide

> **Quenyx vOPS HUB — Document Metadata**
>
> | Field | Value |
> |---|---|
> | Document Version | 2.1 |
> | Software Version | v1.0.0 |
> | Applies To | Quenyx vOPS HUB v1.0.0 |
> | Classification | Public / External |
> | Owner | Product |
> | Status | Released |
> | Last Updated | 2026-06-30 |
> | Document Type | Module guide |
>
> **Revision History**
>
> | Version | Date | Notes |
> |---|---|---|
> | 1.0 | 2026 | Initial v1 pack (through Sprint 19). |
> | 2.0 | 2026-06-29 | Aligned to v1.0.0; native monitoring engine (no Nagios dependency). |
> | 2.1 | 2026-06-30 | Added Operations Intelligence (Sprint 21): Monitoring Copilot, Alert/Root‑Cause/Capacity/Performance/Infrastructure/Service‑Health intelligence, evidence‑based recommendations, and the Operations Intelligence dashboard — all reusing the Quenyx AI Platform. |

**Audience:** QynSight users and admins.
**Status:** 🟢 Production‑ready. Core monitoring is **feature‑frozen** at v1.0; **Operations Intelligence** (Sprint 21) is the active intelligence layer built on top of the frozen monitoring surface.

---

## 1. Overview

QynSight is Quenyx's operations/observability module ("observe"). It monitors hosts and services,
maps infrastructure, analyzes performance, plans capacity, manages alerts, runs service checks, and
enrolls agents — all scoped per workspace and gated by the `qynsight` module entitlement.

## 2. Real‑time monitoring

Live metrics per host/service: `observe/real-time/metrics`, `real-time/system-info`,
`real-time/thresholds`, plus `instances` and `instances/summary`. **Requires the Laravel scheduler**
(cron running `observe:run-checks` each minute); without it data shows "never".

## 3. Infrastructure map

Topology and connections: `observe/infrastructure/topology` and `infrastructure/connections`. The UI
renders the map and supports export (JSON/PNG).

## 4. Performance analytics

`observe/performance/metrics` and historical data (`observe_metrics_history` table) provide
performance trends and threshold context.

## 5. Capacity planning

`observe/capacity-planning` (+ `/export`) and `observe/capacity/metrics` provide a capacity advisor
and exportable capacity data.

## 6. Alert management

- **Rules:** list/create/update/delete + **toggle** (`observe/alerts/rules…`).
- **Events:** `alerts/history`, acknowledge via `alerts/events/{event}/acknowledge`.
- **Summary & channels:** `alerts/summary`, `alerts/channels`.
- **Monitoring profile:** `observe/monitoring-profile` (GET/PUT).

## 7. Service checks

`observe/services`, `observe/service-definitions`, and `observe/run-checks` — **native** service
checks (HTTP, TCP, Ping, and custom plugin scripts) executed by the QynSight Monitoring Engine on a
per‑service check/retry interval. No external monitoring daemon is required. Reports:
`observe/reports`, `reports/summary`.

## 8. Hosts

Managed via **targets**: `observe/targets` (GET/PUT), `targets/validate`, and per‑host port scan
(`targets/{hostId}/port-scan`). Hosts carry address/public‑IP metadata (`observe_targets_hosts`).

## 9. Agent enrollment

- Generate an **enrollment token** (`agents/enrollment-token`), list/revoke tokens, view metadata.
- Download the agent binary: `GET /api/agents/download/{platform}` (built on demand if Go is
  configured; see Doc 10).
- Agents authenticate with token/secret and push `register`, `heartbeat`, `metrics`, `inventory`.

## 10. Alert rules

Define conditions and channels; toggle rules on/off; review fired events and acknowledge them. Tied
to monitoring profiles per workspace.

## 11. Capacity advisor

Derived from capacity metrics; exportable. Use it to anticipate resource constraints.

## 12. Exports

Infrastructure map (JSON/PNG) and capacity planning export are supported.

## 13. Port scans

On‑demand and scheduled port scans run as **background jobs** — a **queue worker** is required
(`infrastructure/port-scans` + `/run`, `targets/{hostId}/port-scan`). Results in
`host_port_scans` tables.

## 14. Known limitations

- Requires scheduler + queue worker to be running for live data and scans.
- Agent on‑demand build needs Go configured on the server (otherwise pre‑place binaries).
- v1.0 is **feature‑frozen** — no functional changes pre‑Sprint 20.

## 15. Feature‑freeze status

QynSight's **core monitoring engine** (checks, metrics, alerts, topology, capacity, agents) is
**frozen** at v1.0. Section 16 (Operations Intelligence) is an additive intelligence layer that reads
the frozen monitoring data — it does not change monitoring behavior.

## 16. Operations Intelligence (Sprint 21)

Operations Intelligence turns QynSight from a monitoring platform into an **operations intelligence
platform**: the AI *understands and explains* your operational data rather than just answering free
questions. It **reuses the Quenyx AI Platform** (Doc 07) end‑to‑end — the same provider abstraction,
prompt orchestration, conversation service, and audit — with **no duplicated AI logic**. Every
narrative is grounded in **real** monitoring evidence; when evidence is insufficient the system says
so and never fabricates data.

**Access & scope.** All Operations Intelligence endpoints are workspace‑scoped, require the
`qynsight` module entitlement, monitoring RBAC, and the per‑workspace **`can_use_ai`** AI capability.
Every AI request is audited, provider‑logged, conversation‑logged, and rate limited. All resource
identifiers are **UUIDs** (a deterministic UUIDv5 is derived from internal monitoring IDs — no schema
change and no numeric IDs are exposed).

### 16.1 Monitoring Copilot
Ask grounded operational questions — "Which hosts are unhealthy?", "Summarize today's alerts", "What
changed in the last 24 hours?", "Which hosts will run out of storage first?". Answers use current
hosts, services, alerts, capacity, metrics, and topology. Each thread is a **real Quenyx AI
conversation** that can be opened in the AI Workspace.

### 16.2 Alert Intelligence — ✨ Explain / ✨ Investigate
Every alert gains **Explain** and **Investigate** actions that return: operational impact, most
likely causes, the **evidence used**, related alerts, suggested actions, and a **confidence score
only when derived from real evidence**.

### 16.3 Root Cause Analysis (deterministic)
A deterministic analyzer scores resource layers (CPU → memory → storage → database → application)
from real alert history, metrics, dependencies, and topology, then identifies the most probable root
cause and **explains why**. Causal chains are never invented.

### 16.4 Incident Timeline
Auto‑generated timelines built from **actual event timestamps** (alert lifecycle and service state
changes), e.g. alert opened → AI investigation → recovery.

### 16.5 Capacity Intelligence
Reuses Capacity Planning and adds per‑host forecasting with an AI explanation of growth trend,
forecast, estimated exhaustion, operational impact, recommended action, and business risk — from
available historical metrics only.

### 16.6 Performance Intelligence
Explains performance degradation, trend changes, resource hotspots, slow services, infrastructure
bottlenecks, and anomalies, with recommendations, from real metric history.

### 16.7 Infrastructure Intelligence
Reuses the Infrastructure Map topology to answer "which systems depend on this host?", blast radius
if a host fails, critical paths, downstream services, single points of failure, and potential
cascading failures.

### 16.8 Service Health Intelligence
Beyond Healthy/Warning/Critical, the AI explains **why** a service is in its state, what changed, the
expected impact, suggested action, and related systems.

### 16.9 Operational Recommendations
Evidence‑based recommendations (e.g. increase RAM, restart service, investigate database, check
storage, review thresholds). Every recommendation references real metrics, alerts, capacity, or
dependencies — never produced without evidence.

### 16.10 Contextual ✨ actions & dashboard
Existing QynSight pages get a single, uncluttered **✨ Quenyx AI** action: Host → Explain,
Service → Analyze, Alert → Investigate, Capacity → Predict, Infrastructure Map → Impact Analysis. A
new **Operations Intelligence dashboard** (`/observe/operations-intelligence`) shows only real data:
infrastructure health summary, open alerts, critical services, top operational risks, predicted
capacity risks, recent recommendations, and recent AI investigations.

See Doc 08 (API Reference) §Operations Intelligence for the full endpoint contract and Doc 07 (AI
Platform Bible) for how these features reuse the shared AI runtime.
