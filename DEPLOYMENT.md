# PortShield vOPS HUB – Deployment

**PROPRIETARY SOFTWARE - Copyright (c) 2026 PortShield CO. All rights reserved.**

This document describes how to deploy the PortShield vOPS HUB monorepo (backend, frontend, gateway) for development and production.

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

**Backend `.env` – Agent install instructions:**

Set `GATEWAY_BASE_URL` in `backend/.env` to your **public** gateway URL (e.g. `https://portshield.example.com`). This is used for agent download and enrollment commands. If unset, it falls back to `APP_URL` or `http://127.0.0.1:4000`.

```bash
GATEWAY_BASE_URL=https://your-public-domain.com
```

After changing `.env`, run `php artisan config:clear` (or `php artisan config:cache` in production).

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

### 7. Laravel scheduler (cron) – required for ShieldObserve

The scheduler runs `observe:run-checks` every minute for host/service monitoring. **Without this, Real-time Monitoring and Infrastructure Map will show stale or "never" data.**

Add to the **www-data** user crontab (or the user that runs the backend):

```bash
sudo crontab -u www-data -e
```

Add this line (adjust the path if your backend lives elsewhere):

```
* * * * * cd /var/www/portshield/portshield-saas/backend && php artisan schedule:run >> /var/www/portshield/portshield-saas/backend/storage/logs/scheduler.log 2>&1
```

**Verify:**
- Wait 1–2 minutes, then check `backend/storage/logs/scheduler.log` – it should contain output from `observe:run-checks` (e.g. "Ran X native check(s).").
- If the file stays empty, confirm: (1) crontab is installed for the correct user, (2) PHP path is correct (`which php`), (3) `storage/logs/` is writable by the cron user.

**After adding or changing `config/queue.php`**, run:
```bash
cd backend && php artisan config:clear && php artisan config:cache
```

### 8. Queue worker (for port scans and other background jobs)

Port scans (especially full 1–65535) run as background jobs. **Without a queue worker, scans will not complete.**

1. **Create jobs table** (if not exists):
   ```bash
   cd backend && php artisan migrate --force
   ```

2. **Set queue driver** in `.env`:
   ```
   QUEUE_CONNECTION=database
   ```

3. **Add queue worker systemd service** `/etc/systemd/system/portshield-queue.service`:
   ```ini
   [Unit]
   Description=PortShield Queue Worker
   After=network.target

   [Service]
   Type=simple
   User=www-data
   WorkingDirectory=/var/www/portshield/portshield-saas/backend
   ExecStart=/usr/bin/php artisan queue:work --sleep=3 --tries=3 --max-time=3600
   Restart=always

   [Install]
   WantedBy=multi-user.target
   ```

4. **Enable and start**:
   ```bash
   sudo systemctl daemon-reload
   sudo systemctl enable portshield-queue
   sudo systemctl start portshield-queue
   ```

**Verify:** Trigger a port scan from the UI; check `storage/logs/laravel.log` or the Port Scan tab for results.

### 9. Enable and start

```bash
sudo systemctl daemon-reload
sudo systemctl enable portshield-backend portshield-gateway portshield-queue
sudo systemctl start portshield-backend portshield-gateway portshield-queue
sudo systemctl reload nginx
```

### 10. Health checks

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

## Production readiness

- **Backend:** Use `APP_ENV=production`, `APP_DEBUG=false`, and a strong `APP_KEY`. Run `php artisan config:cache` and `route:cache` after deployment. Ensure `APP_URL` matches the public URL (HTTPS). Do not expose `.env` or storage.
- **Frontend:** Build with `npm run build`; set `VITE_API_BASE_URL` to your API base (e.g. `https://your-domain.com` so that relative `/api/` requests go to the same origin). Do not ship source maps in production if you want to hide source.
- **Gateway:** No secrets in logs; use env vars for `BACKEND_BASE_URL`. Restart after any code or config change.
- **Security:** HTTPS only in production; restrict CORS to your frontend origin; change or remove seeded default credentials before go-live.
- **Errors:** In production, Laravel should not display stack traces to users (controlled by `APP_DEBUG`). Frontend can show a generic “Something went wrong” and log details client-side or to an error reporting service.

---

## Production environment checklist

Before going live:

| Item | Action |
|------|--------|
| **Backend .env** | `APP_ENV=production`, `APP_DEBUG=false`, strong `APP_KEY`, correct `APP_URL` (HTTPS), `DB_*` for production DB |
| **CORS** | Set `SANCTUM_STATEFUL_DOMAINS` / frontend domain in backend; allow only your frontend origin |
| **Frontend build** | `VITE_API_BASE_URL` set to your API base (e.g. `https://your-domain/api`) so requests go to gateway |
| **Seeded credentials** | Change default login (`admin@portshield.test` / `Password123!`) or remove test users after first admin creation |
| **Workspaces** | Seeder creates only **Production Env** and **Staging Env**; adjust in `ProjectSeeder` if needed |
| **HTTPS** | Use Nginx (or load balancer) with SSL; redirect HTTP → HTTPS |
| **Gateway** | `BACKEND_BASE_URL` must point to backend (e.g. `http://127.0.0.1:8000` or internal LB URL) |
| **No dev deps** | Backend: `composer install --no-dev`. Frontend/gateway: use built assets; no dev servers in production |
| **Laravel scheduler** | Add crontab for `php artisan schedule:run` (see §7). Without it, ShieldObserve checks never run and "Last poll: never" appears |

---

## Real testing before go-live

Run these flows on a staging or production build:

1. **Login** — Log in with production-ready credentials.
2. **Workspace** — Switch workspace (Workspace dropdown); confirm Dashboard and Observe data change (or show empty state for the other workspace).
3. **Empty state** — In a workspace with no hosts, open Real-time Monitoring and Dashboard; confirm “No hosts in this workspace” and “Add hosts” CTA.
4. **Add host** — In Monitored Targets, add a host (name + address); save.
5. **Observe data** — Open Real-time Monitoring (and Dashboard); confirm host appears (or service totals update once services are added).
6. **Infrastructure Map** — Open Map; confirm hosts appear; test export (JSON/PNG).
7. **Integrations** — Open Integrations; configure webhook (optional); confirm settings save.
8. **Getting started** — Open **Getting started** from sidebar; confirm steps and links work.

If all pass, the platform is ready for production use.

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
