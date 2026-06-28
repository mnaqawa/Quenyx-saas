# 14 — QynSight Guide

**Audience:** QynSight users and admins.
**Status:** 🟢 Production‑ready, **feature‑frozen** at v1.0.

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

`observe/services`, `observe/service-definitions`, and `observe/run-checks` (Nagios‑style service
checks with check/retry intervals). Reports: `observe/reports`, `reports/summary`.

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

QynSight is **frozen** at v1.0. This documentation describes the frozen surface; changes are out of
scope for the current phase.
