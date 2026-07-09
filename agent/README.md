# Quenyx Platform Agent (QPA)

Lightweight **Quenyx Platform Agent** for cross-network monitoring and asset inventory. Communicates **outbound-only** via **Quenyx Agent Gateway (QAG)** on HTTPS :9444.

## Quick start

1. In the portal: **Integrations → Platform Agent → Enrollment wizard**
2. Generate an enrollment token
3. On your server:

```bash
./quenyx-agent enroll --url="https://cloud.quenyx.com:9444" --workspace=1 --token="ps_xxx..."
./quenyx-agent run
```

## Commands

| Command | Description |
|---------|-------------|
| `enroll` | Register with QAG using an enrollment token |
| `run` | Run heartbeat, telemetry, and inventory loops |
| `status` | Human-readable connectivity and policy summary |
| `diagnostics` | JSON diagnostics (gateway, plugins, heartbeat, policy) |
| `config` | Show config file path and contents |
| `install` | Install as a system service |

## Sprint 28 — Fleet heartbeat

Each heartbeat reports:

- `agent_version`, `platform_version`, `policy_version`, `capability_hash`
- `plugin_versions`, `plugins[]`, `managed_resources[]` (minimum: `local_host`)
- `lifecycle_status`, optional bandwidth counters
- Policy response updates local config; failover gateway stored but primary URL unchanged

Dangerous plugins (`automation_runner`, `compliance`) remain **disabled** unless explicitly granted in enrollment permissions.

## Build & test

```bash
cd agent
go test ./...
go build -o quenyx-agent .
```

See below for cross-compilation targets.

## Permissions

```bash
# Linux amd64
GOOS=linux GOARCH=amd64 go build -o quenyx-agent .

# Linux arm64
GOOS=linux GOARCH=arm64 go build -o quenyx-agent .

# Windows amd64
GOOS=windows GOARCH=amd64 go build -o quenyx-agent.exe .

# macOS amd64
GOOS=darwin GOARCH=amd64 go build -o quenyx-agent .

# macOS arm64 (Apple Silicon)
GOOS=darwin GOARCH=arm64 go build -o quenyx-agent .
```

### Deploy binaries for portal download

The Install Agent instructions use `/api/agents/download/{platform}`. To serve the agent from the portal:

**Option A – Build on the same server (install Go first):**

```bash
cd /var/www/quenyx/quenyx-saas   # or your repo root
apt install -y golang-go
cd agent
chmod +x build-linux-amd64.sh
./build-linux-amd64.sh        # builds and copies to ../backend/storage/app/agents/linux-amd64 when that dir exists
```

**Option B – Build on your laptop/CI (Go installed), then copy to server:**

```bash
# On your machine (from repo root):
cd agent
./build-linux-amd64.sh        # if backend is next to agent
# Or just build and copy manually:
GOOS=linux GOARCH=amd64 go build -o quenyx-agent .
scp quenyx-agent root@your-server:/var/www/quenyx/quenyx-saas/backend/storage/app/agents/linux-amd64
```

**Storage paths (inside backend):**

- `storage/app/agents/linux-amd64` (no extension)
- `storage/app/agents/linux-arm64`
- `storage/app/agents/windows-amd64` (served as `quenyx-agent.exe`)
- `storage/app/agents/darwin-amd64`, `darwin-arm64`

Ensure `storage/app/agents` exists and is writable by the web server. If a binary is missing, the download endpoint returns JSON (so users see an error instead of a script).

## Permissions

The agent collects:

- **System metrics**: CPU, memory, disk, load (Linux: `/proc`, macOS: `sysctl`/`vm_stat`, Windows: WMI/PDH)
- **Inventory**: Hostname, OS, architecture, CPU cores
- **Network**: Interfaces (when permission granted)
- **Filesystem**: Disk usage (when permission granted)

## Protocols

- **HTTP API (default)**: Agent pushes data to the platform. Works across firewalls; only outbound HTTPS required.
- **PSAP (Quenyx Agent Protocol)**: Platform connects to agent on port 9444 (requires inbound access).
- **SNMP**: Platform polls agent via SNMP (requires SNMP agent on host).

## Config location

- **Linux/macOS**: `~/.config/quenyx/agent.json`
- **Windows**: `%APPDATA%\quenyx\agent.json`
