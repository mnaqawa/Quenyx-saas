# Quenyx Agent

Lightweight agent for cross-network monitoring and asset inventory. Runs on Linux, Windows, and macOS.

## Quick start

1. In the Quenyx portal, go to **QynSight → Agents → Install Agent**.
2. Select protocol (HTTP API recommended) and permissions.
3. Generate an enrollment token.
4. On your server, run:

```bash
# Linux
./quenyx-agent enroll --url="https://app.quenyx.example.com" --workspace=1 --token="ps_xxx..."

# Then run the agent
./quenyx-agent run
```

## Commands

| Command | Description |
|---------|-------------|
| `enroll` | Register with the platform using a token from the portal |
| `run` | Run the agent (heartbeat, metrics, inventory) |
| `install` | Install as a system service (Linux systemd, macOS launchd) |

## Build

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
