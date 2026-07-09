# Fleet Management Guide (Sprint 28)

## Overview

The **Fleet Dashboard** lives inside **Integrations → Platform Agent** (no separate nav module).

Use it for operational visibility: agent health, policy sync, gateway status, enrollments, and errors.

## Fleet summary metrics

| Metric | Meaning |
|--------|---------|
| Total | Enrolled agents in workspace |
| Online | Heartbeat within stale threshold |
| Offline | Stale or explicit offline lifecycle |
| Outdated | Policy or agent version behind platform |
| Quarantined | Administrative quarantine |
| Maintenance | Planned maintenance window |
| Enrollment pending | Awaiting successful enrollment |

## Policy status

- **Up to date** — agent, platform, and policy versions match
- **Policy outdated** — policy version mismatch
- **Upgrade available** — newer agent build published
- **Unsupported version** — agent not in supported list
- **Policy sync required** — agent has not reported policy version

## Installer center

Supports Linux (RPM/DEB/TAR), Windows (MSI/EXE), macOS (PKG), and container (Docker/Kubernetes/Helm).

Each entry includes silent-install command templates with `GATEWAY_URL`, `WORKSPACE_ID`, and optional enrollment token.

## Multi-gateway

Workspaces use a **preferred gateway**. If that gateway is unhealthy, heartbeat responses include `failover_gateway` with alternate endpoint URL.

## Managed resources vs monitoring targets

- **Managed resource** — anything the agent discovers (VM, container, network device, …)
- **Monitoring target** — subset enrolled in QynSight
- **Platform asset** — inventory record for QynAsset (may exist without monitoring)

## AI diagnostics

Quenyx AI uses live fleet context: heartbeats, lifecycle, policy, plugins, and service checks. It does not invent diagnostics beyond collected data.

## Agent CLI diagnostics

On the enrolled host:

```bash
quenyx-agent status
quenyx-agent diagnostics
```

`status` — gateway URL, versions, lifecycle, last heartbeat, enabled plugins.

`diagnostics` — JSON with policy status, capability hash, enabled/disabled plugins, failover gateway (if stored).
