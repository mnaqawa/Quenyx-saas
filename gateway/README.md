# Quenyx Gateway

**PROPRIETARY SOFTWARE - Copyright (c) 2026 Quenyx CO. All rights reserved.**

This software is the proprietary property of Quenyx CO. Unauthorized use, copying, modification, or distribution is strictly prohibited.

Lightweight API gateway service that enforces project entitlements before forwarding requests to the Laravel backend.

## Features

- **Entitlement Enforcement**: Checks project entitlements for project-scoped routes
- **Request Forwarding**: Proxies all `/api/*` requests to the backend
- **Caching**: In-memory cache for entitlements to reduce backend load
- **Logging**: Request/response logging with security-conscious token hashing

## Environment Variables

```bash
GATEWAY_PORT=4000                    # Port for gateway to listen on
BACKEND_BASE_URL=http://127.0.0.1:8000  # Laravel backend URL
ENTITLEMENTS_CACHE_TTL_MS=30000      # Cache TTL in milliseconds (default: 30s)
```

### Observe (QynSight native monitoring)

QynSight monitoring is **native**: checks are executed by the Laravel scheduler command
`observe:run-checks` in the backend. There is **no Nagios runtime** and the gateway does not
resolve a Nagios binary or container.

- **Engine status:** `GET /internal/engines/native/status` reports the native engine (owned by the backend scheduler).
- **Legacy guard:** any `/internal/engines/nagios*` request returns `410 Gone` (`code: nagios_removed`).
- **Readiness:** `GET /ready` reports `checks.observe_engine.engine = "native"` and does not depend on Docker/Nagios.

Nagios may still appear only as a **legacy migration** path or an **optional third‑party
integration** (e.g. the disabled `check_nagios` plugin that reads a remote Nagios status file);
it is never the primary monitoring engine.

## Installation

```bash
npm ci
```

## Development

```bash
npm run dev
```

Runs with hot-reload using `tsx watch`.

## Production

```bash
npm run build
npm start
```

**Deploy / Restart (e.g. systemd):** After any code change you must rebuild so `dist/` is updated, then restart:

```bash
cd /path/to/gateway && npm run build && systemctl restart quenyx-gateway
```

Restarting without rebuilding will keep running the old compiled code.

## How It Works

1. **Request Reception**: Gateway receives all `/api/*` requests
2. **Entitlement Check**: For project-scoped routes (e.g., `/api/projects/:id/integrations`), checks if the project's plan includes the required module
3. **Forwarding**: If allowed, forwards request to backend with original headers
4. **Response**: Returns backend response or 403 if access denied

## Enforced Routes

Currently enforced:
- `/api/projects/:projectId/integrations*` (and the `/api/workspaces/:projectId/integrations*` alias) → requires the `qynintegrations` entitlement key

Integrations is a **platform capability** for connecting to **external** systems, not a Quenyx
business module. `qynintegrations` is retained purely as the entitlement key that gates this
platform page (legacy alias: `shieldintegrations`).

## Nginx Configuration

To route `/api/*` requests through the gateway, add this to your Nginx config:

```nginx
location /api/ {
    proxy_pass http://127.0.0.1:4000;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header Authorization $http_authorization;
}
```

For production with WebSocket support and timeouts:

```nginx
location /api/ {
    proxy_pass http://127.0.0.1:4000;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection 'upgrade';
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_set_header Authorization $http_authorization;
    proxy_cache_bypass $http_upgrade;
    
    # Timeouts
    proxy_connect_timeout 60s;
    proxy_send_timeout 60s;
    proxy_read_timeout 60s;
}
```

## Health Check

```bash
curl http://localhost:4000/health
```

Returns: `{"status":"ok","service":"gateway"}`

## Module Keys

The gateway enforces access based on entitlement keys returned by the backend entitlements endpoint. Current key for the Integrations platform capability:
- **`qynintegrations`** - Required for accessing project/workspace integrations (legacy alias: `shieldintegrations`)

## Caching

Entitlements are cached in-memory per `(token, projectId)` combination. Cache expires after `ENTITLEMENTS_CACHE_TTL_MS` (default 30 seconds).

To clear cache manually (for testing), you can restart the gateway service.

## Logging

Gateway logs:
- All requests: `METHOD /path STATUS DURATIONms`
- Denied access: `DENIED: token=<hash> project=<id> module=<key>`
- Allowed access: `ALLOWED: token=<hash> project=<id> module=<key>`

Tokens are hashed (SHA-256, first 8 chars) for security - no secrets in logs.

## Error Responses

### 403 Forbidden (Access Denied)
```json
{
  "success": false,
  "message": "Your current plan does not allow access to this module"
}
```

### 401 Unauthorized
```json
{
  "success": false,
  "message": "Authentication required"
}
```

### 502 Bad Gateway
```json
{
  "success": false,
  "message": "Backend service unavailable"
}
```
