# Quenyx Platform Agent (QPA) & Agent Gateway (QAG) Architecture

## Sprint 27 — GA Remediation Summary

Enterprise customers install **one Quenyx Platform Agent** per host. The agent supports **every entitled module** (QynSight, QynAsset, QynRun, QynShield, …) via modular capability plugins.

**Agents never communicate with Laravel directly.** All traffic flows through the **Quenyx Agent Gateway (QAG)** on outbound HTTPS.

```
┌──────────────────────────────────────────────────────────────────────┐
│                     Quenyx Platform (cloud)                          │
│  ┌──────────────┐    ┌──────────────┐    ┌────────────────────────┐ │
│  │ QAG :9444    │───▶│ Laravel API  │───▶│ Queue / Event Bus      │ │
│  │ (HTTPS in)   │    │ (internal)   │    │ → QynSight / QynAsset  │ │
│  └──────▲───────┘    └──────────────┘    └────────────────────────┘ │
└─────────┼────────────────────────────────────────────────────────────┘
          │ outbound HTTPS only (TLS 1.2+)
          │
┌─────────┴──────────┐
│ Quenyx Platform Agent │
│ Core + Plugin Manager │
│ - monitoring plugin │
│ - inventory plugin  │
│ - automation (off)  │
│ - compliance (off)  │
└──────────────────────┘
```

## Default endpoint

```
https://cloud.quenyx.com:9444
```

Configurable via:

```
AGENT_GATEWAY_PROTOCOL=https
AGENT_GATEWAY_HOST=cloud.quenyx.com
AGENT_GATEWAY_PORT=9444
```

## Root cause of GA blockers (SSH / UNKNOWN)

| Symptom | Root cause | Fix |
|---------|------------|-----|
| `Could not create directory '/var/www/.ssh'` | Default monitoring profile attached **SSH pull plugins** (`check_disk`, `check_load`, …) to agent-enrolled hosts; Laravel backend ran `ssh` from the web server | Agent hosts now get `check_source=platform_agent` telemetry checks only |
| `UNKNOWN` service state | Agent metrics stored in `agent_metrics` but **not wired** to `observe_services` | `AgentTelemetryObserveBridge` syncs telemetry → observe states |
| Agent treated as QynSight-only | Registration auto-created observe host with pull checks; UI under QynSight | Platform Agent APIs, capability model, multi-module plugins |
| Direct Laravel access | Agent posted to `/api/agents/*` via API gateway :4000 | Dedicated QAG on :9444; `AGENT_REQUIRE_GATEWAY=true` |

## Communication rules

- HTTPS only, TLS 1.2+
- Outbound only from customer network
- No inbound firewall, VPN, SSH, or port forwarding
- Agent always initiates

## Capability model

```
effective_capability = subscription ∩ entitlements ∩ RBAC ∩ agent_permission
```

Default permissions (if entitled): QynSight telemetry + QynAsset inventory.

**Never enabled by default:** SSH, automation execution, compliance evidence.

## QAG responsibilities

- Agent authentication & enrollment validation
- Heartbeat / telemetry / inventory / evidence ingestion
- Compression & rate limiting
- Agent version validation
- Observed source IP for NAT/VPN detection
- Forward validated payloads to Laravel

## QPA plugin architecture

```
Core Agent → Plugin Manager → Capability Plugins
```

Initial plugins: Monitoring, Asset Inventory, Automation (disabled), Compliance (disabled).

Installer ships **core only**. Plugins enabled per capability policy without reinstall.

## API surface

### Agent-facing (via QAG → Laravel)

| Method | QAG path | Laravel path |
|--------|----------|--------------|
| POST | `/v1/agents/register` | `/api/agents/register` |
| POST | `/v1/agents/{uuid}/heartbeat` | `/api/agents/{uuid}/heartbeat` |
| POST | `/v1/agents/{uuid}/telemetry` | `/api/agents/{uuid}/metrics` |
| POST | `/v1/agents/{uuid}/inventory` | `/api/agents/{uuid}/inventory` |
| POST | `/v1/agents/{uuid}/evidence` | `/api/agents/{uuid}/evidence` |

### Platform management (authenticated)

| Method | Path |
|--------|------|
| GET | `/api/platform/agents` |
| POST | `/api/platform/agents/enrollment-tokens` |
| GET | `/api/platform/agents/{uuid}` |
| PUT | `/api/platform/agents/{uuid}/permissions` |
| GET | `/api/platform/agents/metadata` |

## Module integration

| Module | Data source | Default |
|--------|-------------|---------|
| QynSight | Agent telemetry → observe services | Enabled |
| QynAsset | Agent inventory push | Enabled |
| QynRun | Automation runner plugin | Disabled |
| QynShield | Evidence endpoint | Disabled |

## Deploy checklist

1. Deploy `agent-gateway` service on port 9444
2. Configure nginx TLS termination for `:9444`
3. Set Laravel `AGENT_GATEWAY_*` and `AGENT_REQUIRE_GATEWAY=true`
4. Run migration `2026_07_09_100000_platform_agent_qpa_qag`
5. Rebuild Go agent binary
6. Re-enroll or wait for next telemetry push on existing agents

## Host & agent lifecycle (Sprint 27)

| Action | Effect on agent | Effect on linked host |
|--------|-----------------|----------------------|
| **Revoke agent** | Secret invalidated, status revoked | `agent_removed`, checks disabled |
| **Delete agent** | Soft-delete + revoke | `agent_removed`, history preserved |
| **Disable monitoring** | — | `monitoring_disabled` |
| **Suspend** | — | `suspended`, checks stopped |
| **Archive** | — | Hidden from default list, asset kept |
| **Restore** | — | Returns to `active` |
| **Delete host** | — | Soft-delete; blocked if history exists (unless force) |

Platform events: `AgentRevoked`, `HostMonitoringDisabled`

## Sprint 28 — Enterprise fleet maturity

### Multi-resource model

One Platform Agent manages many **managed resources** (`agent_managed_resources`):

```
Platform Agent → Managed Resources → Resource Types
```

### Host vs Asset separation

- **QynAsset** consumes `platform_assets`.
- **QynSight** consumes `observe_targets_hosts`.
- Linked via UUIDs; not every asset is a monitored host.

### Policy versioning

Heartbeat fields: `agent_version`, `platform_version`, `policy_version`, `plugin_versions`, `capability_hash`.

### Fleet dashboard

`GET /api/platform/agents/fleet?workspace_id=`

### New APIs

| Method | Path |
|--------|------|
| GET | `/api/platform/agents/fleet` |
| GET | `/api/platform/agents/installers` |
| GET | `/api/platform/agents/gateways` |
| GET | `/api/platform/agents/{uuid}/resources` |
| GET | `/api/platform/agents/{uuid}/plugins` |

Migration: `2026_07_09_140000_platform_agent_enterprise_fleet`

### Go agent (Sprint 28 end-to-end)

QPA heartbeat payload includes policy versions, managed resources (`local_host`), plugin metadata, and bandwidth counters. The agent:

- Applies policy from heartbeat response (`policy_version`, `capability_hash`)
- Stores `failover_gateway` without switching primary QAG URL
- Exposes `quenyx-agent status` and `quenyx-agent diagnostics`
- Never reports Docker/K8s/VM resources until discovery plugins exist (no fake data)

