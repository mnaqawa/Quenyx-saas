# Quenyx Agent Gateway (QAG)

Dedicated secure communication gateway for **Quenyx Platform Agent (QPA)**.

## Architecture

```
Platform Agent (customer host)
        │  outbound HTTPS only
        ▼
Quenyx Agent Gateway :9444
        │  internal HTTP
        ▼
Laravel Platform (validated events)
        │
        ▼
QynSight / QynAsset / QynRun / ...
```

Agents **never** communicate with Laravel directly in production (`AGENT_REQUIRE_GATEWAY=true`).

## Default endpoint

```
https://cloud.quenyx.com:9444
```

## Configuration

| Variable | Default | Description |
|----------|---------|-------------|
| `QAG_PORT` | `9444` | Listen port |
| `QAG_HOST` | `0.0.0.0` | Bind address |
| `BACKEND_BASE_URL` | `http://127.0.0.1:8000` | Laravel internal URL |
| `QAG_RATE_LIMIT_MAX` | `120` | Requests per window per IP |
| `QAG_MIN_AGENT_VERSION` | _(empty)_ | Minimum agent version prefix |

Laravel `.env`:

```
AGENT_GATEWAY_PROTOCOL=https
AGENT_GATEWAY_HOST=cloud.quenyx.com
AGENT_GATEWAY_PORT=9444
AGENT_REQUIRE_GATEWAY=true
```

## API routes (v1)

| Method | Path | Forwards to |
|--------|------|-------------|
| POST | `/v1/agents/register` | `/api/agents/register` |
| POST | `/v1/agents/:id/heartbeat` | `/api/agents/:id/heartbeat` |
| POST | `/v1/agents/:id/telemetry` | `/api/agents/:id/metrics` |
| POST | `/v1/agents/:id/inventory` | `/api/agents/:id/inventory` |
| POST | `/v1/agents/:id/evidence` | `/api/agents/:id/evidence` |

## Run

```bash
cd agent-gateway
npm install
npm run build
npm start
```

## nginx (production)

```nginx
server {
    listen 9444 ssl http2;
    server_name cloud.quenyx.com;

    ssl_certificate     /etc/ssl/quenyx/fullchain.pem;
    ssl_certificate_key /etc/ssl/quenyx/privkey.pem;
    ssl_protocols       TLSv1.2 TLSv1.3;

    location / {
        proxy_pass http://127.0.0.1:9444;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Real-IP $remote_addr;
    }
}
```

## Security

- TLS 1.2+ required (terminated at nginx)
- Outbound-only from customer perspective
- No SSH, no inbound firewall, no VPN
- Rate limiting at QAG
- Agent version validation (optional)
- Observed source IP forwarded to Laravel for NAT/VPN detection
