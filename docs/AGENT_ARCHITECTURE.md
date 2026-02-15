# PortShield Agent Architecture

## Overview

Most infrastructure is located in **other networks** (DMZ, private subnets, cloud VPCs, remote offices). Central monitoring from the platform server cannot reach these hosts because:

- Firewalls block inbound connections
- NAT and routing prevent direct access
- Hosts may have no public IP

**Solution:** Deploy a **lightweight agent** on each monitored host (Windows, Linux, macOS). The agent runs locally, collects data, and **pushes** it to the PortShield platform. Only outbound HTTPS from the agent to the platform is required—no inbound ports on the host.

---

## Goals

1. **Monitoring** – Heartbeat, check results (CPU, memory, disk, services), and alerts from agents.
2. **Asset inventory** – Hardware, OS, installed software, network interfaces, and other CMDB-style data.
3. **Cross-network** – Works regardless of host location; agent initiates all connections.

---

## Architecture

```
┌─────────────────────────────────────────────────────────────────────────┐
│                        PortShield Platform                               │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐  ┌─────────────────┐ │
│  │   Gateway   │  │   Backend   │  │   Queue     │  │ Agent Ingest    │ │
│  │   (API)     │  │   (Laravel) │  │   Worker    │  │ API + Jobs      │ │
│  └──────┬──────┘  └──────┬──────┘  └──────┬──────┘  └────────┬────────┘ │
│         │                │                │                    │         │
│         └────────────────┴────────────────┴────────────────────┘         │
│                                    │                                      │
│                          HTTPS (agent → platform)                         │
└────────────────────────────────────┼─────────────────────────────────────┘
                                     │
         ┌───────────────────────────┼───────────────────────────┐
         │                           │                           │
         ▼                           ▼                           ▼
┌─────────────────┐       ┌─────────────────┐       ┌─────────────────┐
│  Agent (Linux)  │       │  Agent (Windows) │       │  Agent (macOS)  │
│  - Heartbeat    │       │  - Heartbeat     │       │  - Heartbeat    │
│  - Metrics      │       │  - Metrics       │       │  - Metrics      │
│  - Inventory    │       │  - Inventory     │       │  - Inventory    │
└─────────────────┘       └─────────────────┘       └─────────────────┘
```

---

## Agent Design Principles

| Principle | Description |
|-----------|-------------|
| **Push model** | Agent initiates all connections to the platform. No inbound ports on the host. |
| **Stable identifiers** | Agent UUID (persistent) and workspace_id for correlation. Hostname/IP are display-only. |
| **Idempotent ingest** | Inventory and metrics use composite keys (agent_id + timestamp/version) for dedup. |
| **Minimal footprint** | Small binary/service; configurable intervals; no root/admin where avoidable. |

---

## Agent Capabilities (Phase 1 – Minimal)

### 1. Registration

- Agent is installed with: `platform_url`, `workspace_id`, `agent_token` (or enrollment key).
- On first run, agent calls `POST /api/agents/register` with:
  - `workspace_id`, `hostname`, `os`, `arch`, `agent_version`
- Platform returns `agent_id` (UUID) and `agent_secret` (for subsequent requests).
- Agent stores `agent_id` and `agent_secret` locally (encrypted if possible).

### 2. Heartbeat

- Every N minutes (e.g. 5), agent sends `POST /api/agents/{agent_id}/heartbeat`:
  - `timestamp`, `uptime`, `version`
- Platform updates `last_seen_at` and `status` (online/offline/stale).

### 3. Metrics (Monitoring)

- Agent collects local metrics (CPU, memory, disk, load) using OS APIs.
- Sends `POST /api/agents/{agent_id}/metrics` with structured payload.
- Platform stores in `agent_metrics` (or similar) and can drive dashboards/alerts.

### 4. Inventory (Asset Discovery)

- On startup and periodically (e.g. daily), agent collects:
  - **Hardware:** CPU model, cores, RAM, disks, serial numbers (where available)
  - **OS:** Name, version, kernel, architecture
  - **Network:** Interfaces, IPs, MACs
  - **Software:** Installed packages (optional; can be heavy)
- Sends `POST /api/agents/{agent_id}/inventory` with full or delta payload.
- Platform reconciles by `agent_id`; creates/updates asset record.

---

## Data Model (Platform Side)

### New Tables (Proposal)

| Table | Purpose |
|-------|---------|
| `agents` | `id` (UUID), `workspace_id`, `hostname`, `os`, `arch`, `agent_version`, `last_seen_at`, `status`, `enrolled_at` |
| `agent_metrics` | `agent_id`, `timestamp`, `cpu`, `memory`, `disk`, `load`, etc. (JSON or columns) |
| `agent_inventories` | `agent_id`, `collected_at`, `payload` (JSON), for reconciliation and history |

### Stable Identifiers

- **Agent:** `agents.id` (UUID) – use for all joins and API auth.
- **Inventory:** `(agent_id, collected_at)` – idempotent ingest.
- **Metrics:** `(agent_id, timestamp)` – append-only time series.

---

## Agent Implementation Options

### Option A: Go Binary (Recommended)

- Single binary for Linux, Windows, macOS.
- Cross-compile from one codebase.
- Small footprint, no runtime dependency.
- Distribution: `.deb`/`.rpm`, `.msi`, `.pkg`.

### Option B: Node.js / Electron

- Easier for JS/TS teams.
- Larger footprint; requires Node runtime.
- Good for quick prototyping.

### Option C: Python

- Good for Linux; Windows/macOS packaging is heavier.
- Requires Python runtime on host.

### Option D: Integrate Existing Agents

- **Wazuh agent** – already deployed in many environments; can forward inventory and custom metrics via Wazuh API.
- **FusionInventory** – GLPI-style; could adapt its protocol.
- **Telegraf** – metrics only; would need inventory separately.

**Recommendation:** Start with **Option A (Go)** for a clean, minimal agent. Option D can be a later integration if users already have Wazuh/Telegraf.

---

## Security

| Concern | Mitigation |
|---------|------------|
| Agent auth | `agent_id` + `agent_secret` in header or signed JWT; rotate secret on compromise. |
| Transport | HTTPS only; certificate pinning optional. |
| Data at rest | Encrypt `agent_secret` in agent config (OS keychain/credential store where available). |
| Scope | Agent only sends data for its `workspace_id`; platform validates workspace membership. |

---

## Integration with Existing Observe Model

Today, `observe_targets_hosts` assumes hosts are **reachable from the backend**. Two modes:

1. **Legacy (pull):** Backend runs checks (HTTP, TCP, ping, plugins) against `host.address`. Works only for same-network hosts.
2. **Agent (push):** Agent runs checks locally and pushes results. Works for any network.

**Unified model:**

- Add `source` to host: `manual` (current) or `agent`.
- For `source=agent`, link `observe_targets_hosts` to `agents.id` (e.g. `agent_id` FK).
- When processing agent metrics/heartbeat, update the linked host's service status.
- UI can show both manual and agent-sourced hosts; filter by source.

---

## Implementation Phases

### Phase 1: Platform API + Schema

1. Migration: `agents`, `agent_metrics`, `agent_inventories`.
2. API: `POST /api/agents/register`, `POST /api/agents/{id}/heartbeat`, `POST /api/agents/{id}/metrics`, `POST /api/agents/{id}/inventory`.
3. Auth: Validate `agent_id` + `agent_secret` (or signed token) on each request.
4. Jobs: Ingest metrics and inventory asynchronously (queue) to avoid blocking agent.

### Phase 2: Minimal Agent (Go)

1. Config: `platform_url`, `workspace_id`, `enrollment_token`.
2. Registration flow.
3. Heartbeat loop.
4. Metrics collection (CPU, memory, disk) for Linux first.
5. Basic inventory (hostname, OS, CPU, RAM, interfaces).

### Phase 3: Windows + macOS Agent

1. Port metrics collection to Windows (WMI/Performance Counters) and macOS (sysctl, etc.).
2. Packaging: `.msi`, `.pkg`.
3. Install as service/daemon.

### Phase 4: Observe Integration

1. Add `agent_id` to `observe_targets_hosts`.
2. When agent sends metrics, update linked host's check results.
3. UI: "Add host from agent" – select enrolled agent to create observe target.

---

## References

- **GLPI/FusionInventory:** Agent-based discovery and inventory; reconciliation by serial/UUID.
- **Kaspersky EDR/KSC:** Agent reports to central; host ID and policy ID as stable refs.
- **Wazuh:** Agent → manager pipeline; rule IDs and agent IDs for correlation.
