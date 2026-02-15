# PortShield Agent

Lightweight agent for cross-network monitoring and asset inventory. Runs on Linux, Windows, and macOS.

## Quick start

1. In the PortShield portal, go to **ShieldObserve → Agents → Install Agent**.
2. Select protocol (HTTP API recommended) and permissions.
3. Generate an enrollment token.
4. On your server, run:

```bash
# Linux
./portshield-agent enroll --url="https://your-portshield.com" --workspace=1 --token="ps_xxx..."

# Then run the agent
./portshield-agent run
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
GOOS=linux GOARCH=amd64 go build -o portshield-agent .

# Windows amd64
GOOS=windows GOARCH=amd64 go build -o portshield-agent.exe .

# macOS amd64
GOOS=darwin GOARCH=amd64 go build -o portshield-agent .

# macOS arm64 (Apple Silicon)
GOOS=darwin GOARCH=arm64 go build -o portshield-agent .
```

## Permissions

The agent collects:

- **System metrics**: CPU, memory, disk, load (Linux: `/proc`, macOS: `sysctl`/`vm_stat`, Windows: WMI/PDH)
- **Inventory**: Hostname, OS, architecture, CPU cores
- **Network**: Interfaces (when permission granted)
- **Filesystem**: Disk usage (when permission granted)

## Protocols

- **HTTP API (default)**: Agent pushes data to the platform. Works across firewalls; only outbound HTTPS required.
- **NRPE**: Platform connects to agent on port 5666 (requires inbound access).
- **SNMP**: Platform polls agent via SNMP (requires SNMP agent on host).

## Config location

- **Linux/macOS**: `~/.config/portshield/agent.json`
- **Windows**: `%APPDATA%\portshield\agent.json`
