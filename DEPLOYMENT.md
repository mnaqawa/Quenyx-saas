# PortShield SaaS – Deployment

**PROPRIETARY SOFTWARE - Copyright (c) 2026 PortShield CO. All rights reserved.**

This document describes how to deploy the PortShield SaaS monorepo (backend, frontend, gateway) for development and production.

## Prerequisites

| Component   | Version / Notes |
|------------|------------------|
| PHP        | 8.1+             |
| Composer   | 2.x              |
| Node.js    | 18+ LTS or 20+   |
| npm        | 9+ (use `npm ci` for reproducible installs) |
| MySQL      | 8.0+             |
| Nginx      | For production (reverse proxy, static frontend, `/api/` to gateway) |

## Single-Node Deployment (Development / Staging)

Single server runs: Laravel backend, Node gateway, Nginx serving frontend static build and proxying `/api/` to the gateway.

### 1. Clone and enter repo

```bash
git clone <repository-url>
cd portshield-saas
```

### 2. Backend

```bash
cd backend
composer install --no-dev --optimize-autoloader
cp .env.example .env
# Edit .env: DB_*, APP_URL, etc.
php artisan key:generate
php artisan migrate --force
php artisan db:seed --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Default seed login: `admin@portshield.test` / `Password123!` (change in production.)

### 3. Frontend (reproducible build)

```bash
cd ../frontend
npm ci
npm run build
```

Static output: `frontend/dist/`. Nginx will serve this as the document root.

### 4. Gateway

```bash
cd ../gateway
npm ci
npm run build
```

**Important:** After any gateway code change, run `npm run build` and restart the gateway service so `dist/` is updated.

**Environment (systemd or .env):**

```bash
GATEWAY_PORT=4000
BACKEND_BASE_URL=http://127.0.0.1:8000
ENTITLEMENTS_CACHE_TTL_MS=30000
```

**Optional – ShieldObserve / Nagios:**

```bash
NAGIOS_BIN=nagios
NAGIOS_CONFIG_DIR=./nagios/config
NAGIOS_CONTAINER_NAME=nagios-core
```

See [gateway/README.md](gateway/README.md) for Nagios binary resolution and `GET /ready` checks.

### 5. Nginx (single node)

Example: site root = frontend build; `/api/` proxied to gateway.

```nginx
server {
    listen 80;
    server_name dev.portshield.net;
    root /var/www/portshield/portshield-saas/frontend/dist;
    index index.html;

    location / {
        try_files $uri $uri/ /index.html;
    }

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

        proxy_connect_timeout 60s;
        proxy_send_timeout 60s;
        proxy_read_timeout 60s;
    }
}
```

### 6. Systemd services

**Backend**

`/etc/systemd/system/portshield-backend.service`:

```ini
[Unit]
Description=PortShield Backend API
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/portshield/portshield-saas/backend
ExecStart=/usr/bin/php artisan serve --host=127.0.0.1 --port=8000
Restart=always

[Install]
WantedBy=multi-user.target
```

**Gateway**

`/etc/systemd/system/portshield-gateway.service`:

```ini
[Unit]
Description=PortShield API Gateway
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/portshield/portshield-saas/gateway
Environment="GATEWAY_PORT=4000"
Environment="BACKEND_BASE_URL=http://127.0.0.1:8000"
Environment="ENTITLEMENTS_CACHE_TTL_MS=30000"
ExecStart=/usr/bin/node dist/server.js
Restart=always

[Install]
WantedBy=multi-user.target
```

### 7. Enable and start

```bash
sudo systemctl daemon-reload
sudo systemctl enable portshield-backend portshield-gateway
sudo systemctl start portshield-backend portshield-gateway
sudo systemctl reload nginx
```

### 8. Health checks

- Gateway: `curl http://127.0.0.1:4000/health` → `{"status":"ok","service":"gateway"}`
- Backend: `curl http://127.0.0.1:8000/api/health` → Laravel health response

---

## Multi-Node Deployment (Production)

- **Load balancer**: Nginx (SSL termination, route `/` to frontend, `/api/` to gateway pool).
- **Frontend**: Static files from `frontend/dist/` (or CDN).
- **Gateway**: 2+ stateless nodes; in-memory entitlement cache only.
- **Backend**: 2+ Laravel nodes; shared MySQL (and optional Redis for sessions/cache).

### Load balancer Nginx (example)

```nginx
upstream gateway {
    least_conn;
    server gateway1.internal:4000;
    server gateway2.internal:4000;
}

server {
    listen 443 ssl http2;
    server_name portshield.net;

    ssl_certificate /path/to/cert.pem;
    ssl_certificate_key /path/to/key.pem;

    location / {
        proxy_pass http://frontend-cdn-or-origin;
    }

    location /api/ {
        proxy_pass http://gateway;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header Authorization $http_authorization;
    }
}
```

### Gateway nodes

On each node:

```bash
cd gateway
npm ci
npm run build
```

Set `BACKEND_BASE_URL` to the backend pool or load balancer URL. After code changes: rebuild then restart the gateway service.

### Backend nodes

On each node:

```bash
cd backend
composer install --no-dev --optimize-autoloader
# .env: shared DB, same APP_KEY on all nodes
php artisan migrate --force
php artisan config:cache
php artisan route:cache
```

### Health and monitoring

- Gateway: `GET /health` → `{"status":"ok","service":"gateway"}` (and optionally `GET /ready` for Nagios).
- Backend: `GET /api/health`.
- Monitor ports 4000 (gateway) and 8000 (backend); set alerts on 502/503 and DB connectivity.

---

## Reproducible installs

- **Frontend:** Commit `package-lock.json`; use `npm ci` in CI and deployment.
- **Backend:** Use `composer install --no-dev --optimize-autoloader` with a locked `composer.lock`.
- **Gateway:** Use `npm ci` and `npm run build`; always restart after rebuilding.

---

## Updating a deployment

1. **Backend:** `git pull`, `composer install --no-dev`, `php artisan migrate --force`, `php artisan config:cache` (and route/view cache as needed), restart app.
2. **Frontend:** `git pull`, `npm ci`, `npm run build`; refresh static hosting (or CDN).
3. **Gateway:** `git pull`, `npm ci`, `npm run build`, restart gateway service.

---

## License

**PROPRIETARY SOFTWARE - Copyright (c) 2026 PortShield CO. All rights reserved.**
